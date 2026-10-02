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

namespace block_my_assessments\local;

/**
 * Who sees which view, and over which courses.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
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
        global $DB;
        if (!$roleids) {
            return [];
        }
        $now = time();
        [$rolesql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params += ['userid1' => $userid, 'userid2' => $userid, 'now1' => $now, 'now2' => $now,
            'courselevel' => CONTEXT_COURSE, 'siteid' => SITEID];
        $yearsql = '';
        if ($year) {
            $yearsql = 'AND c.startdate >= :ystart AND c.startdate < :yend';
            $params += ['ystart' => $year->timestart, 'yend' => $year->timeend];
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
     * The student view is offered to anyone with a student role somewhere, and
     * to anyone with no staff or convenor role at all (it then says there is nothing to show).
     *
     * @param int $userid
     * @return string[]
     */
    public static function views(int $userid): array {
        $views = [];
        $staff = (bool)self::courses_with_roles($userid, config::role_ids('staffrole'));
        $convenor = (bool)self::courses_with_roles($userid, config::role_ids('moduleconvenorrole'));
        $student = (bool)self::courses_with_roles($userid, config::role_ids('studentrole'));
        if ($student || (!$staff && !$convenor)) {
            $views[] = self::STUDENT;
        }
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
        $last = get_user_preferences('block_my_assessments_view', '', $userid);
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
}
