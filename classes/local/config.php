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
     * after), and the nightly reconcile's live window (months, 0 for every course).
     */
    const NUMBERS = ['graceperiod' => 24, 'mitigationopens' => 90, 'mitigationcloses' => 24, 'reconcilemonths' => 13];

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
     * Seconds after a deadline (the default or a student's own) before work still to come stops
     * counting, and that a student without a cut-off is told they can still submit for (G9).
     *
     * @return int
     */
    public static function grace(): int {
        return self::number('graceperiod') * HOURSECS;
    }

    /**
     * Whether Mitigate is offered at a time, from the student's effective deadline (G4).
     *
     * @param int|null $due
     * @param int $now
     * @return bool
     */
    public static function mitigation_open(?int $due, int $now): bool {
        return $due !== null && $now >= $due - self::number('mitigationopens') * DAYSECS
            && $now <= $due + self::number('mitigationcloses') * HOURSECS;
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
     * Assessments a Refresh re-indexes inline before it queues the rest instead.
     *
     * @return int
     */
    public static function inline_refresh_limit(): int {
        $value = (int)get_config('block_le_dashboard', 'inlinerefreshlimit');
        return $value > 0 ? $value : 30;
    }
}
