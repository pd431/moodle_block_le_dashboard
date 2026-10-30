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

use block_le_dashboard\local\adapter\registry;
use block_le_dashboard\local\output\format;

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
        if (!file_exists($lib)) {
            // Scenarios tagged @block_rhb on a site without it.
            $lib = testing::rhb_stub();
        }
        if (!$lib || !file_exists($lib)) {
            return false;
        }
        require_once($lib);
        return true;
    }

    /** Menu item kinds; each is also the mitigate_<kind> label. */
    const KIND_SELFCERT = 'selfcert';
    /** The request form: formal mitigation. */
    const KIND_REQUEST = 'request';

    /**
     * The Mitigate menu of one row (option A of the Mitigate options): block_rhb's links
     * under our labels, and where the type takes self-certified extensions (adapter::takes_selfcert(), coursework)
     * "Self-certify an extension" with how many are left, or a line saying why it can't be used.
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
        // The adapter says whether the type takes self-certified extensions, and gives block_rhb what its link needs.
        $adapter = registry::adapter_for(self::stub_coursework($modname, $instanceid) ? 'coursework' : $modname, false);
        $takesselfcert = $adapter && $adapter->takes_selfcert();
        if ($takesselfcert) {
            if (!function_exists('block_rhb_get_self_certification_link')) {
                return null;
            }
            try {
                $subject = $adapter->selfcert_subject($instanceid);
                // Block_rhb's self-certification link (blocks/rhb/lib.php).
                $selfcert = $subject ? (string)block_rhb_get_self_certification_link($subject) : '';
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
        return testing::rhb_stub_coursework($modname, $instanceid);
    }

    /**
     * Builds the Mitigate menu from block_rhb's answers.
     * - Self-certification (coursework only): a link is live, with "N of M left" when the block's count has some
     *   left; block_rhb decides, so a link stays live whatever the count says. A delete link means it was used
     *   here: a line says so and "Delete mitigation" follows it. No link: a line with the reason, and no item
     *   (no greyed item).
     * - Formal mitigation: block_rhb's request form link, after a divider when self-certification is listed.
     * There is no menu without at least one live link.
     *
     * @param bool $takesselfcert Whether the activity takes self-certified extensions (coursework).
     * @param string $selfcert block_rhb's self-certification HTML.
     * @param string $srm block_rhb's request form HTML.
     * @param array|null $count used and max (max null: no limit), or null when unknown.
     * @param int|null $here The new deadline when the student self-certified on this one.
     * @return array|null items (each url and label, delete too for Delete mitigation, html, info with a reason,
     *     or divider), or null for no Mitigate
     */
    public static function menu(bool $takesselfcert, string $selfcert, string $srm, ?array $count, ?int $here): ?array {
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
                $dated = null;
                if ($delete || $here !== null) {
                    $dated = $here !== null ? format::dated('mitigate_reason_here', $here) : null;
                    $reason = $dated ? $dated['text'] : get_string('mitigate_reason_herenodate', 'block_le_dashboard');
                } else {
                    $reason = $count && $count['max'] !== null && $count['used'] >= $count['max']
                        ? get_string('mitigate_reason_used', 'block_le_dashboard', $count['max'])
                        : get_string('mitigate_reason_unavailable', 'block_le_dashboard');
                    if ($request) {
                        $reason .= ' ' . get_string('mitigate_stillrequest', 'block_le_dashboard');
                    }
                }
                $item = ['info' => true, 'live' => false, 'reason' => $reason, 'reasondated' => $dated];
            }
            $items[] = $item;
            if ($delete) {
                // Its own red item with a bin, confirmed by a second click in place.
                $item = self::item('delete', $selfcert);
                $items[] = isset($item['url']) ? ['delete' => true, 'live' => false] + $item
                    : ['html' => self::delete_label($selfcert)];
                $live = true;
            }
        }
        if ($request) {
            if ($items) {
                $items[] = ['divider' => true, 'live' => false];
            }
            $items[] = $request;
        }
        // The keyboard skips the line, so the first link after it reads it out.
        $info = array_key_first(array_filter($items, fn($item) => !empty($item['info'])));
        if ($info !== null) {
            foreach (array_slice($items, $info + 1, null, true) as $i => $item) {
                if (isset($item['url'])) {
                    $items[$i]['describedby'] = true;
                    break;
                }
            }
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
     * Needs block_rhb's selfcertmax and an activity type that takes self-certified extensions and can count
     * them (adapter::selfcert_used(): coursework's mitigations table). Counts those since the date in the
     * student's "count from" profile field; students whose exemption field holds the exemption value have no
     * limit. The field names and value are settings (SELFCERT_SETTINGS).
     *
     * @param int $userid
     * @return array|null used and max (max null means unlimited), or null when not applicable
     */
    public static function selfcert(int $userid): ?array {
        global $DB;
        $max = (int)get_config('block_rhb', 'selfcertmax');
        if (!$max) {
            return null;
        }
        // Only installed types: without coursework there is no mitigations table to count from (C4).
        $installed = array_filter(array_keys(registry::OWN), fn($modname) => registry::module_installed($modname));
        $adapters = array_filter(
            array_map(fn($modname) => registry::adapter_for($modname, false), $installed),
            fn($adapter) => $adapter && $adapter->takes_selfcert()
        );
        if (!$adapters) {
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
        $used = null;
        foreach ($adapters as $adapter) {
            $used = $adapter->selfcert_used($userid, $since);
            if ($used !== null) {
                break;
            }
        }
        if ($used === null) {
            return null;
        }
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
