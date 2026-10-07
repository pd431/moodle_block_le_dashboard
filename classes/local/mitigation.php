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
    /** @var string|null|false Cached request-form link; false until loaded. */
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

    /** Link kinds, each with its own menu label (mitigate_<kind>). */
    const KIND_SELFCERT = 'selfcert';
    /** The student request form. */
    const KIND_REQUEST = 'request';

    /**
     * Links for the Mitigate menu of one row. block_rhb returns each link as HTML; only its address is
     * kept, and the label comes from this plugin's strings by the kind of link (Pierre, 2026-10-06).
     * A link whose address can't be read falls back to block_rhb's own HTML, so no route is lost.
     *
     * Shown only when there is at least one real link, as rc6 did.
     *
     * @param string $modname
     * @param int $instanceid
     * @return array[] Each with url and label, or html for the fallback.
     */
    public static function links(string $modname, int $instanceid): array {
        if (!self::available()) {
            return [];
        }
        $links = [];
        if (
            $modname === 'coursework' && function_exists('block_rhb_get_self_certification_link')
                && class_exists('\mod_coursework\models\coursework')
        ) {
            try {
                $coursework = \mod_coursework\models\coursework::find($instanceid);
                if ($coursework) {
                    // Block_rhb's self-certification link (blocks/rhb/lib.php).
                    $links[] = self::item(self::KIND_SELFCERT, (string)block_rhb_get_self_certification_link($coursework));
                }
            } catch (\Throwable $e) {
                debugging('block_rhb self-certification link failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        if (self::$srm === false) {
            self::$srm = null;
            if (function_exists('block_rhb_get_srm_link')) {
                try {
                    // Block_rhb's student request form link (blocks/rhb/lib.php), the same for every row.
                    self::$srm = self::item(self::KIND_REQUEST, (string)block_rhb_get_srm_link());
                } catch (\Throwable $e) {
                    debugging('block_rhb request link failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
        $links = array_values(array_filter($links));
        if (!$links && $modname === 'coursework') {
            return [];
        }
        if (self::$srm) {
            $links[] = self::$srm;
        }
        return $links;
    }

    /**
     * One menu item from block_rhb's HTML: its address with our label, the HTML itself when the address
     * can't be read, or null when there is no link at all (a message such as "limit reached").
     *
     * @param string $kind
     * @param string $html
     * @return array|null
     */
    public static function item(string $kind, string $html): ?array {
        if (!preg_match('/<a\b[^>]*\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $m)) {
            return null;
        }
        $href = html_entity_decode($m[2] !== '' ? $m[2] : ($m[3] ?? '') . ($m[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = clean_param($href, PARAM_LOCALURL) ?: clean_param($href, PARAM_URL);
        if ($url === '' || $url === '#') {
            return ['html' => $html];
        }
        return ['url' => $url, 'label' => get_string('mitigate_' . $kind, 'block_le_dashboard')];
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
