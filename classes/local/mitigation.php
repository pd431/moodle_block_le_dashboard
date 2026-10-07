<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_le_dashboard\local;

/**
 * Optional integration with block_rhb (self-certification and the student
 * request form). Everything here does nothing when block_rhb is absent.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mitigation {
    /** @var string|false Cached request form HTML; false until loaded. */
    protected static $srm = false;

    /**
     * Whether block_rhb's library is installed.
     *
     * @return bool
     */
    protected static function available(): bool {
        global $CFG;
        $lib = $CFG->dirroot . '/blocks/rhb/lib.php';
        if (!file_exists($lib) && defined('BEHAT_SITE_RUNNING') && get_config('block_le_dashboard', 'behatrhbstub')) {
            // Scenarios tagged @block_rhb on a site without it.
            $lib = $CFG->dirroot . '/blocks/le_dashboard/tests/fixtures/rhb_stub.php';
        }
        if (!file_exists($lib)) {
            return false;
        }
        require_once($lib);
        return true;
    }

    /**
     * The Mitigate menu of one row, as rc6 built it (Pierre, 2026-10-07: "pull back the behaviour from rc6"):
     * block_rhb's own HTML, shown as it is.
     * - Coursework: only when its self-certification HTML holds a real link; the request form follows it.
     * - Anything else: never self-certification; only when the request form HTML holds a real link, with
     *   the note that self-certification isn't allowed.
     * A link for deleting a self-certification reads "Delete mitigation" (rc6 did this in its JavaScript).
     *
     * @param string $modname
     * @param int $instanceid
     * @return array|null items (HTML) and noselfcert, or null for no Mitigate
     */
    public static function links(string $modname, int $instanceid): ?array {
        if (!self::available()) {
            return null;
        }
        $selfcert = '';
        if ($modname === 'coursework') {
            if (!function_exists('block_rhb_get_self_certification_link') || !class_exists('\mod_coursework\models\coursework')) {
                return null;
            }
            try {
                $coursework = \mod_coursework\models\coursework::find($instanceid);
                // Block_rhb's self-certification link (blocks/rhb/lib.php).
                $selfcert = $coursework ? (string)block_rhb_get_self_certification_link($coursework) : '';
            } catch (\Throwable $e) {
                debugging('block_rhb self-certification link failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            if (!self::has_link($selfcert)) {
                return null;
            }
        }
        if (self::$srm === false) {
            self::$srm = '';
            if (function_exists('block_rhb_get_srm_link')) {
                try {
                    // Block_rhb's student request form link (blocks/rhb/lib.php), the same for every row.
                    self::$srm = (string)block_rhb_get_srm_link();
                } catch (\Throwable $e) {
                    debugging('block_rhb request link failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
        if ($modname !== 'coursework' && !self::has_link(self::$srm)) {
            return null;
        }
        $items = array_values(array_filter([self::delete_label($selfcert), self::delete_label(self::$srm)], 'strlen'));
        return ['items' => $items, 'noselfcert' => $selfcert === ''];
    }

    /**
     * Whether block_rhb's HTML holds a real link, as rc6 checked it.
     *
     * @param string $html
     * @return bool
     */
    public static function has_link(string $html): bool {
        return preg_match('/<a\b[^>]*href=/i', $html) === 1;
    }

    /**
     * Relabels block_rhb's links for deleting a self-certification "Delete mitigation", as rc6 did: any link
     * whose text or address mentions delete. dashboard.js follows them in the background and reloads.
     *
     * @param string $html
     * @return string
     */
    public static function delete_label(string $html): string {
        return preg_replace_callback('~<a\b([^>]*)>(.*?)</a>~is', function ($m) {
            if (stripos(strip_tags($m[2]), 'delete') === false && stripos($m[1], 'delete') === false) {
                return $m[0];
            }
            return '<a' . $m[1] . ' data-action="mitigate-delete">' . get_string('mitigate_delete', 'block_le_dashboard') . '</a>';
        }, $html) ?? $html;
    }

    /**
     * Forgets the cached request link (tests change block_rhb's answer).
     */
    public static function reset_caches(): void {
        self::$srm = false;
    }

    /**
     * Self-certified extensions used and allowed, for the student summary box.
     *
     * Needs block_rhb's selfcertmax and coursework's mitigations table. Counts
     * self-certified extensions since the date in the student's "count from"
     * profile field; students whose exemption field holds the exemption value
     * have no limit. The field names and value are settings (SELFCERT_SETTINGS).
     *
     * @param int $userid
     * @return array|null used and max (max null means unlimited), or null when not applicable
     */
    public static function selfcert(int $userid): ?array {
        global $DB;
        $max = (int)get_config('block_rhb', 'selfcertmax');
        if (!$max || !$DB->get_manager()->table_exists('coursework_mitigations')) {
            return null;
        }
        $sincefield = self::setting('selfcertsincefield');
        $exemptfield = self::setting('selfcertexemptfield');
        $fields = $DB->get_records_sql_menu(
            "SELECT f.shortname, d.data
               FROM {user_info_field} f
               JOIN {user_info_data} d ON d.fieldid = f.id
              WHERE d.userid = :userid AND f.shortname IN (:since, :exempt)",
            ['userid' => $userid, 'since' => $sincefield, 'exempt' => $exemptfield]
        );
        $since = !empty($fields[$sincefield]) ? (int)strtotime($fields[$sincefield]) : 0;
        $used = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {coursework_mitigations}
              WHERE type = 'extension' AND selfcert = 1 AND allocatabletype = 'user'
                AND allocatableid = :userid AND timecreated > :since",
            ['userid' => $userid, 'since' => $since]
        );
        $exempt = ($fields[$exemptfield] ?? '') === self::setting('selfcertexemptvalue');
        return ['used' => $used, 'max' => $exempt ? null : $max];
    }

    /** Self-certification settings and their defaults (the values rc6 hardcoded). */
    const SELFCERT_SETTINGS = [
        'selfcertsincefield' => 'enrol_date',
        'selfcertexemptfield' => 'exte',
        'selfcertexemptvalue' => 'EXTE',
    ];

    /**
     * A self-certification setting, or its default.
     *
     * @param string $name A key of SELFCERT_SETTINGS.
     * @return string
     */
    public static function setting(string $name): string {
        $value = get_config('block_le_dashboard', $name);
        return $value === false || $value === '' ? self::SELFCERT_SETTINGS[$name] : (string)$value;
    }
}
