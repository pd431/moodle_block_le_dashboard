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
            if (!class_exists('\mod_coursework\models\coursework')) {
                // Activities the scenario has the stand-in treat as coursework, on sites without mod_coursework.
                require_once($CFG->dirroot . '/blocks/le_dashboard/tests/fixtures/coursework_stub.php');
            }
        }
        if (!file_exists($lib)) {
            return false;
        }
        require_once($lib);
        return true;
    }

    /** Menu item kinds; each is also the mitigate_<kind> label. */
    const KIND_SELFCERT = 'selfcert';
    /** The request form: formal mitigation (Pierre, 2026-10-09). */
    const KIND_REQUEST = 'request';

    /**
     * The Mitigate menu of one row (option A of the Mitigate options, Pierre 2026-10-09): block_rhb's links
     * under our labels, and for coursework "Self-certify an extension" always listed, with how many are left
     * or, greyed, why it can't be used. Only coursework takes self-certified extensions.
     *
     * @param string $modname
     * @param int $instanceid
     * @param array|null $count self-certifications used and max (selfcert()), or null when unknown.
     * @param string|null $here The student's new deadline when they self-certified on this one, else null.
     * @return array|null the menu (see menu()), or null for no Mitigate
     */
    public static function links(string $modname, int $instanceid, ?array $count = null, ?string $here = null): ?array {
        if (!self::available()) {
            return null;
        }
        $selfcert = '';
        $takesselfcert = $modname === 'coursework' || self::stub_coursework($modname, $instanceid);
        if ($takesselfcert) {
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
        return self::menu($takesselfcert, $selfcert, self::$srm, $count, $here);
    }

    /**
     * Whether a Behat scenario has the block_rhb stand-in treat this activity as a coursework (on sites without
     * mod_coursework, "the block_rhb stand-in treats ... as a coursework").
     *
     * @param string $modname
     * @param int $instanceid
     * @return bool
     */
    protected static function stub_coursework(string $modname, int $instanceid): bool {
        if (!defined('BEHAT_SITE_RUNNING') || !get_config('block_le_dashboard', 'behatrhbstub')) {
            return false;
        }
        return in_array("$modname:$instanceid", explode(',', (string)get_config('block_le_dashboard', 'behatcoursework')));
    }

    /**
     * Builds the Mitigate menu from block_rhb's answers.
     * - Self-certification (coursework only): a link is live, with "N of M left" when the block's count has some
     *   left; block_rhb decides, so a link stays live whatever the count says. A delete link means it was used
     *   here: the item is greyed and "Delete mitigation" follows it. No link: greyed, with the reason.
     * - Formal mitigation: block_rhb's request form link.
     * There is no menu without at least one live link.
     *
     * @param bool $takesselfcert Whether the activity takes self-certified extensions (coursework).
     * @param string $selfcert block_rhb's self-certification HTML.
     * @param string $srm block_rhb's request form HTML.
     * @param array|null $count used and max (max null: no limit), or null when unknown.
     * @param string|null $here The new deadline when the student self-certified on this one.
     * @return array|null items (each url and label, delete too for Delete mitigation, html, or greyed with a reason),
     *     or null for no Mitigate
     */
    public static function menu(bool $takesselfcert, string $selfcert, string $srm, ?array $count, ?string $here): ?array {
        $request = self::item(self::KIND_REQUEST, $srm);
        $items = [];
        $live = (bool)$request;
        if ($takesselfcert) {
            $delete = self::has_link($selfcert) && self::delete_label($selfcert) !== $selfcert;
            $item = $delete ? null : self::item(self::KIND_SELFCERT, $selfcert);
            if ($item) {
                $left = $count && $count['max'] !== null ? $count['max'] - $count['used'] : null;
                if (isset($item['url']) && $left !== null && $left > 0) {
                    $item['note'] = get_string('mitigate_left', 'block_le_dashboard', ['left' => $left, 'max' => $count['max']]);
                }
                $live = true;
            } else {
                if ($delete || $here !== null) {
                    $reason = $here !== null ? get_string('mitigate_reason_here', 'block_le_dashboard', $here)
                        : get_string('mitigate_reason_herenodate', 'block_le_dashboard');
                } else {
                    $reason = $count && $count['max'] !== null && $count['used'] >= $count['max']
                        ? get_string('mitigate_reason_used', 'block_le_dashboard', $count['max'])
                        : get_string('mitigate_reason_unavailable', 'block_le_dashboard');
                    if ($request) {
                        $reason .= ' ' . get_string('mitigate_stillrequest', 'block_le_dashboard');
                    }
                }
                $item = [
                    'greyed' => true,
                    'live' => false,
                    'label' => get_string('mitigate_' . self::KIND_SELFCERT, 'block_le_dashboard'),
                    'reason' => $reason,
                ];
            }
            $items[] = $item;
            if ($delete) {
                // Its own red item with a bin, confirmed by a second click in place (Pierre, 2026-10-09).
                $item = self::item('delete', $selfcert);
                $items[] = isset($item['url']) ? ['delete' => true, 'live' => false] + $item
                    : ['html' => self::delete_label($selfcert)];
                $live = true;
            }
        }
        if ($request) {
            $items[] = $request;
        }
        return $live ? ['items' => $items] : null;
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
        if (!self::has_link($html)) {
            return null;
        }
        if (!preg_match('/<a\b[^>]*\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $m)) {
            return ['html' => $html];
        }
        $href = html_entity_decode(($m[2] ?? '') . ($m[3] ?? '') . ($m[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = clean_param($href, PARAM_LOCALURL) ?: clean_param($href, PARAM_URL);
        if ($url === '' || $url === '#') {
            return ['html' => $html];
        }
        // Flagged, as a row's own url would otherwise show through the template's sections.
        return ['live' => true, 'url' => $url, 'label' => get_string('mitigate_' . $kind, 'block_le_dashboard'),
            'note' => null];
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
