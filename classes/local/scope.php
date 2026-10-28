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
 * Who sees which view, and over which courses.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /** Student view. */
    const STUDENT = 'student';
    /** Marker view. */
    const STAFF = 'staff';
    /** Module convenor view. */
    const CONVENOR = 'convenor';

    /**
     * Courses where the user holds one of the roles (at course level or above)
     * with an active enrolment, optionally limited to courses starting in an academic year.
     *
     * Course visibility is ignored: markers still mark in hidden courses.
     *
     * @param int $userid
     * @param int[] $roleids
     * @param \stdClass|null $year
     * @return int[]
     */
    public static function courses_with_roles(int $userid, array $roleids, ?\stdClass $year = null): array {
        if (!$roleids) {
            return [];
        }
        $key = 'scope:' . $userid . ':' . implode(',', $roleids) . ':' . ($year ? $year->timestart . '-' . $year->timeend : '');
        return memo::get($key, fn() => self::lookup_courses_with_roles($userid, $roleids, $year));
    }

    /**
     * Courses where a user holds a role, from the database.
     *
     * @param int $userid
     * @param int[] $roleids
     * @param \stdClass|null $year
     * @return int[]
     */
    protected static function lookup_courses_with_roles(int $userid, array $roleids, ?\stdClass $year): array {
        global $DB;
        $now = time();
        [$rolesql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params += ['userid1' => $userid, 'userid2' => $userid, 'now1' => $now, 'now2' => $now,
            'courselevel' => CONTEXT_COURSE, 'siteid' => SITEID];
        $yearsql = '';
        if ($year) {
            $yearsql = 'AND ' . self::year_sql('c');
            $params += self::year_params($year);
        }
        // Role at course level, or at a category or system context above it.
        $sql = "SELECT DISTINCT c.id
                  FROM {course} c
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :courselevel
                  JOIN {role_assignments} ra ON ra.userid = :userid1 AND ra.roleid $rolesql
                  JOIN {context} rctx ON rctx.id = ra.contextid
                  JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid2 AND ue.status = 0
                       AND ue.timestart <= :now1 AND (ue.timeend = 0 OR ue.timeend > :now2)
                 WHERE c.id <> :siteid
                   AND (rctx.id = ctx.id OR ctx.path LIKE " . $DB->sql_concat('rctx.path', "'/%'") . ")
                       $yearsql";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * The views this user can open, in tab order.
     *
     * As in rc6: anyone with a staff or convenor role (active enrolment, any course,
     * any year) gets only the staff views; everyone else gets the student view.
     *
     * @param int $userid
     * @return string[]
     */
    public static function views(int $userid): array {
        $staff = (bool)self::courses_with_roles($userid, config::role_ids('staffrole'));
        $convenor = (bool)self::courses_with_roles($userid, config::role_ids('moduleconvenorrole'));
        if (!$staff && !$convenor) {
            return [self::STUDENT];
        }
        $views = [];
        if ($staff) {
            $views[] = self::STAFF;
        }
        if ($convenor) {
            $views[] = self::CONVENOR;
        }
        return $views;
    }

    /**
     * The view to open: the last one chosen, else marking first, then convenor, then student.
     *
     * @param int $userid
     * @param string[] $views
     * @return string
     */
    public static function default_view(int $userid, array $views): string {
        $last = get_user_preferences('block_le_dashboard_view', '', $userid);
        if (in_array($last, $views)) {
            return $last;
        }
        foreach ([self::STAFF, self::CONVENOR, self::STUDENT] as $view) {
            if (in_array($view, $views)) {
                return $view;
            }
        }
        return self::STUDENT;
    }

    /**
     * SQL for a course (by alias) belonging to an academic year (EC3b, Pierre): every year its
     * start-to-end span overlaps; with only a start date, its start year; with only an end date,
     * its end year; with neither, no year (it shows under All years only).
     *
     * @param string $alias
     * @param string $prefix Parameter name prefix, so the fragment can sit in a query beside another.
     * @return string
     */
    public static function year_sql(string $alias, string $prefix = 'y'): string {
        $c = $alias;
        $s = ':' . $prefix . 'start';
        $e = ':' . $prefix . 'end';
        return "(($c.startdate > 0 AND $c.enddate > 0 AND $c.startdate < {$e}1 AND $c.enddate >= {$s}1)
                 OR ($c.startdate > 0 AND $c.enddate = 0 AND $c.startdate >= {$s}2 AND $c.startdate < {$e}2)
                 OR ($c.startdate = 0 AND $c.enddate > 0 AND $c.enddate >= {$s}3 AND $c.enddate < {$e}3))";
    }

    /**
     * Parameters for year_sql().
     *
     * @param \stdClass $year timestart, timeend
     * @param string $prefix As given to year_sql().
     * @return array
     */
    public static function year_params(\stdClass $year, string $prefix = 'y'): array {
        $params = [];
        foreach ([1, 2, 3] as $i) {
            $params[$prefix . 'start' . $i] = (int)$year->timestart;
            $params[$prefix . 'end' . $i] = (int)$year->timeend;
        }
        return $params;
    }

    /**
     * SQL for an indexed assessment (block_le_dashboard_cm, by alias) belonging to an academic year (EC3): its
     * default deadline or the latest deadline any student has falls in the year, or its course belongs to the
     * year (year_sql()). No restriction for All years. in_year() is the same rule in PHP.
     *
     * @param string $alias
     * @param \stdClass|null $year timestart, timeend; null for All years.
     * @return array [sql, params]
     */
    public static function in_year_sql(string $alias, ?\stdClass $year): array {
        static $n = 0;
        if (!$year) {
            return ['1 = 1', []];
        }
        $p = 'iy' . ++$n;
        $c = $alias;
        $sql = "(($c.due >= :{$p}d1 AND $c.due < :{$p}d2)
                 OR ($c.latestdue >= :{$p}l1 AND $c.latestdue < :{$p}l2)
                 OR $c.courseid IN (SELECT {$p}c.id FROM {course} {$p}c WHERE " . self::year_sql($p . 'c', $p) . '))';
        $start = (int)$year->timestart;
        $end = (int)$year->timeend;
        return [$sql, ["{$p}d1" => $start, "{$p}d2" => $end, "{$p}l1" => $start, "{$p}l2" => $end]
            + self::year_params($year, $p)];
    }

    /**
     * The PHP twin of in_year_sql(), for one row.
     *
     * @param \stdClass $cm Index row: due, latestdue, courseid.
     * @param int[] $yearcourses Courses belonging to the year by year_sql().
     * @param \stdClass|null $year
     * @return bool
     */
    public static function in_year(\stdClass $cm, array $yearcourses, ?\stdClass $year): bool {
        if (!$year) {
            return true;
        }
        $within = fn($time) => $time !== null && (int)$time >= (int)$year->timestart && (int)$time < (int)$year->timeend;
        return $within($cm->due) || $within($cm->latestdue) || in_array((int)$cm->courseid, $yearcourses, true);
    }
}
