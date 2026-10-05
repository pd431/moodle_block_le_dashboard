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
 * Academic years, which scope the staff and convenor views by course start date.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class years {
    /** Table name. */
    const TABLE = 'block_le_dashboard_year';

    /**
     * Every year, oldest first.
     *
     * @return \stdClass[]
     */
    public static function all(): array {
        global $DB;
        return $DB->get_records(self::TABLE, null, 'timestart ASC');
    }

    /**
     * The year to show by default: the admin's choice, else the one containing now, else none (0).
     *
     * @param int|null $now
     * @return int Year id, or 0 for all years.
     */
    public static function default_id(?int $now = null): int {
        $now = $now ?? time();
        return memo::get('year:' . intdiv($now, HOURSECS), fn() => self::lookup_default_id($now));
    }

    /**
     * The default year, from the database.
     *
     * @param int $now
     * @return int
     */
    protected static function lookup_default_id(int $now): int {
        global $DB;
        $configured = (int)get_config('block_le_dashboard', 'defaultacademicyear');
        if ($configured && $DB->record_exists(self::TABLE, ['id' => $configured])) {
            return $configured;
        }
        $current = $DB->get_records_select(
            self::TABLE,
            'timestart <= :now1 AND timeend > :now2',
            ['now1' => $now, 'now2' => $now],
            'timestart DESC',
            'id',
            0,
            1
        );
        return $current ? (int)reset($current)->id : 0;
    }

    /**
     * A year's bounds, or null for "all years" or an unknown id.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        return $id ? ($DB->get_record(self::TABLE, ['id' => $id]) ?: null) : null;
    }

    /**
     * Inserts the default years: 1 September to 1 September, two back and two ahead.
     *
     * @param int|null $now
     */
    public static function create_defaults(?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $year = (int)date('Y', $now) - ((int)date('n', $now) < 9 ? 1 : 0);
        for ($y = $year - 2; $y <= $year + 2; $y++) {
            $start = make_timestamp($y, 9, 1);
            if ($DB->record_exists(self::TABLE, ['timestart' => $start])) {
                continue;
            }
            $DB->insert_record(self::TABLE, (object)[
                'label' => $y . '/' . substr((string)($y + 1), -2),
                'timestart' => $start,
                'timeend' => make_timestamp($y + 1, 9, 1),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }
}
