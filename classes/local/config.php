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
 * The block's settings, read in one place.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** Default days after which an unsubmitted assessment moves from Overdue to Past. */
    const DEFAULT_THRESHOLD = 90;

    /**
     * Number settings and their defaults: grace period (hours), Mitigate window (days before, hours
     * after), the nightly reconcile's live window (months, 0 for every course) and the time limit of each
     * index run (minutes, 0 for none), the default marking period (days, 0 for none) and how many minutes after a
     * deadline work starts to count as late.
     */
    const NUMBERS = ['graceperiod' => 24, 'mitigationcloses' => 24, 'reconcilemonths' => 13, 'indexminutes' => 45,
        'defaultmarkingdays' => 12, 'latetolerance' => 0, 'feedbackdatedelay' => 2];

    /**
     * The optional hints' on/off settings, in the order the Hints and guidance page lists them, each with its default
     * on a new site and its guidance boxes (beta27 hints audit). An upgrade turns
     * each one on, so a site sees no change until an admin changes it.
     */
    const HINTS = [
        'hint_late' => [1, ['latepenalty']],
        'hint_nosubmission' => [1, ['nosubmission']],
        'hint_hidden' => [1, []],
        'hint_unallocated' => [1, ['unallocated']],
        'hint_returned' => [1, []],
        'hint_extensions' => [1, []],
        'hint_plagiarism' => [0, ['plagiarism']],
        'hint_setup' => [1, ['setup']],
        'hint_brief' => [0, ['brief']],
        'genericnames' => [0, []],
        'showuntagged' => [1, ['untagged']],
        'showsetupchecks' => [1, []],
        'hint_unmarkedlate' => [1, []],
        'hint_markeremail' => [1, []],
        'hoverpopovers' => [1, []],
    ];

    /** How students see marking deadlines (feedbackdatemode): as set, with a delay, or not at all. */
    const FEEDBACK_MODES = ['asset', 'delayed', 'none'];

    /**
     * The Feedback expected date a student sees (Pierre: "Site admin can choose whether to expose
     * internal marking deadlines to students as is, with a specified delay, or not at all"): the marking deadline,
     * the assessment's own or the default marking period after the student's own deadline, as set, plus the delay,
     * or none. A new site shows none; an upgrade keeps "as set".
     *
     * @param \stdClass $r Student row (markingdue, due: the student's own deadline).
     * @return int|null
     */
    public static function feedback_date(\stdClass $r): ?int {
        $mode = get_config('block_le_dashboard', 'feedbackdatemode');
        $mode = in_array($mode, self::FEEDBACK_MODES, true) ? $mode : 'none';
        [$deadline] = $mode === 'none' ? [null] : self::markingdue($r);
        if ($deadline === null) {
            return null;
        }
        return $mode === 'delayed' ? $deadline + self::number('feedbackdatedelay') * DAYSECS : $deadline;
    }

    /**
     * Upgrade to beta27: a site sees no change until an admin changes it. Every hint switch it never saved is on,
     * marking deadlines are shown to students as set, and guidance boxes it never saved keep their old text.
     */
    public static function keep_today(): void {
        foreach (array_keys(self::HINTS) as $name) {
            if (get_config('block_le_dashboard', $name) === false) {
                set_config($name, 1, 'block_le_dashboard');
            }
        }
        if (get_config('block_le_dashboard', 'feedbackdatemode') === false) {
            set_config('feedbackdatemode', 'asset', 'block_le_dashboard');
        }
        foreach (guidance::LEGACY as $key => $html) {
            if (get_config('block_le_dashboard', 'guidance_' . $key) === false) {
                set_config('guidance_' . $key, $html, 'block_le_dashboard');
            }
        }
        guidance::reset_caches();
    }

    /**
     * Whether an optional hint is on: its setting, or its new-site default when unset.
     *
     * @param string $name A key of HINTS.
     * @return bool
     */
    public static function shown(string $name): bool {
        $value = get_config('block_le_dashboard', $name);
        return $value === false || $value === '' ? (bool)self::HINTS[$name][0] : (bool)$value;
    }

    /**
     * A whole-number setting, or its default when unset.
     *
     * @param string $name A key of NUMBERS.
     * @return int
     */
    public static function number(string $name): int {
        $value = get_config('block_le_dashboard', $name);
        return $value === false || $value === '' ? self::NUMBERS[$name] : max(0, (int)$value);
    }

    /**
     * An assessment's marking deadline: its own, or else its deadline plus the default marking period, whether its
     * type has no such setting (quiz, peer work) or it was left empty. None without a deadline,
     * or with the period set to 0.
     *
     * @param \stdClass $cm Index record (markingdue, due).
     * @return array [deadline or null, whether it is the default]
     */
    public static function markingdue(\stdClass $cm): array {
        if (!empty($cm->markingdue)) {
            return [(int)$cm->markingdue, false];
        }
        $days = self::number('defaultmarkingdays');
        return $days && !empty($cm->due) ? [(int)$cm->due + $days * DAYSECS, true] : [null, false];
    }

    /**
     * Seconds after a deadline (the default or a student's own) before work still to come stops
     * counting, and that a student without a cut-off is told they can still submit for (G9).
     *
     * @return int
     */
    public static function grace(): int {
        return self::number('graceperiod') * HOURSECS;
    }

    /**
     * Whether Past work still offers Mitigate: until the hours set after the student's own deadline.
     *
     * @param int|null $due
     * @param int $now
     * @return bool
     */
    public static function mitigation_open(?int $due, int $now): bool {
        return $due !== null && $now <= $due + self::number('mitigationcloses') * HOURSECS;
    }

    /**
     * A comma-separated setting as a clean list.
     *
     * @param string $name
     * @param bool $lowercase
     * @return string[]
     */
    protected static function csv(string $name, bool $lowercase = false): array {
        $value = (string)get_config('block_le_dashboard', $name);
        $items = array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== '' && $v !== '0');
        if ($lowercase) {
            $items = array_map(fn($v) => \core_text::strtolower($v), $items);
        }
        return array_values(array_unique($items));
    }

    /**
     * Tag names that mark an activity as summative.
     *
     * @return string[]
     */
    public static function summative_tags(): array {
        return self::csv('certaintags', true);
    }

    /**
     * Tag names that mark an activity as formative.
     *
     * @return string[]
     */
    public static function formative_tags(): array {
        return self::csv('formativetags', true);
    }

    /**
     * Tag names that mark an assessment as done in person: students hand nothing in online.
     *
     * @return string[]
     */
    public static function inperson_tags(): array {
        return self::csv('inpersontags', true);
    }

    /**
     * Role ids for one of the role settings, falling back to archetypes when unset.
     *
     * @param string $setting studentrole, staffrole or moduleconvenorrole
     * @return int[]
     */
    public static function role_ids(string $setting): array {
        return memo::get('roles:' . $setting, fn() => self::lookup_role_ids($setting));
    }

    /**
     * Role ids for a role setting, from the database.
     *
     * @param string $setting
     * @return int[]
     */
    protected static function lookup_role_ids(string $setting): array {
        global $DB;
        $shortnames = self::csv($setting);
        if (!$shortnames) {
            $archetypes = ['studentrole' => ['student'], 'staffrole' => ['editingteacher', 'teacher'],
                'moduleconvenorrole' => []][$setting] ?? [];
            if (!$archetypes) {
                return [];
            }
            [$insql, $params] = $DB->get_in_or_equal($archetypes, SQL_PARAMS_NAMED);
            return array_map('intval', $DB->get_fieldset_select('role', 'id', "archetype $insql", $params));
        }
        [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED);
        return array_map('intval', $DB->get_fieldset_select('role', 'id', "shortname $insql", $params));
    }

    /**
     * Days after the deadline when an unsubmitted assessment moves from Overdue to Past.
     *
     * @return int
     */
    public static function overdue_threshold_days(): int {
        $value = get_config('block_le_dashboard', 'overduetopastthreshold');
        return $value === false || $value === '' ? self::DEFAULT_THRESHOLD : max(0, (int)$value);
    }

    /**
     * Rows shown in a section before "Show more".
     *
     * @return int
     */
    public static function visible_rows(): int {
        return 5;
    }

    /**
     * The time the dashboard is drawn at. A Behat run can set it, so a scenario can say what day it is: day counts
     * such as "Due tomorrow" depend on the calendar, not only on the time left.
     *
     * @return int
     */
    public static function now(): int {
        return testing::now() ?? time();
    }

    /**
     * Assessments a Refresh re-indexes inline before it queues the rest instead.
     *
     * @return int
     */
    public static function inline_refresh_limit(): int {
        $value = (int)get_config('block_le_dashboard', 'inlinerefreshlimit');
        return $value > 0 ? $value : 30;
    }

    /**
     * Student rows a Refresh re-indexes inline before it queues the work instead, so a Refresh of a few very
     * large assessments (a convenor at 12:05 on a deadline day) can't hold a web request, or the activities'
     * locks that students' submissions wait on, for long (deadline rush, P2). A hidden setting, for tests.
     *
     * @return int
     */
    public static function inline_refresh_rows(): int {
        $value = (int)get_config('block_le_dashboard', 'inlinerefreshrows');
        return $value > 0 ? $value : 5000;
    }
}
