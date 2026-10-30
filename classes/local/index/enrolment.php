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

namespace block_le_dashboard\local\index;

use block_le_dashboard\local\config;

/**
 * Who is in a course, for the index: its students, its markers, when each student joined, and which
 * students an activity's restrictions let in.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrolment {
    /**
     * Students of a course: enrolled, suspended or ended enrolments included, with one of the
     * configured student roles. Submitted work of students who left still gets marked (EC4).
     *
     * @param int $courseid
     * @param bool $onlyactive Only students whose enrolment is active now.
     * @return int[]
     */
    public static function students(int $courseid, bool $onlyactive = false): array {
        return self::with_roles($courseid, config::role_ids('studentrole'), $onlyactive);
    }

    /**
     * When each student joined the course: the start of their earliest enrolment there, or when
     * it was made if it has no start date (EC10).
     *
     * @param int $courseid
     * @param int[] $userids
     * @return int[] userid => time
     */
    public static function enrol_starts(int $courseid, array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$usql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        return array_map('intval', $DB->get_records_sql_menu("SELECT ue.userid,
                                                                     MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart
                                                                              ELSE ue.timecreated END)
                                                                FROM {user_enrolments} ue
                                                                JOIN {enrol} e ON e.id = ue.enrolid
                                                               WHERE e.courseid = :courseid AND ue.userid $usql
                                                            GROUP BY ue.userid", $params));
    }

    /**
     * Users who can mark in a course now: an active enrolment and a staff or module convenor role
     * there or above (EC1).
     *
     * @param int $courseid
     * @return int[]
     */
    public static function markers(int $courseid): array {
        $roleids = array_unique(array_merge(config::role_ids('staffrole'), config::role_ids('moduleconvenorrole')));
        return self::with_roles($courseid, $roleids, true);
    }

    /**
     * Enrolled users of a course holding one of some roles there or above: the one query behind students()
     * and markers().
     *
     * @param int $courseid
     * @param int[] $roleids
     * @param bool $onlyactive Only users whose enrolment is active now.
     * @return int[]
     */
    protected static function with_roles(int $courseid, array $roleids, bool $onlyactive): array {
        global $DB;
        if (!$roleids) {
            return [];
        }
        $context = \context_course::instance($courseid);
        [$esql, $params] = get_enrolled_sql($context, '', 0, $onlyactive);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                  JOIN ($esql) je ON je.id = u.id
                  JOIN {role_assignments} ra ON ra.userid = u.id
                 WHERE u.deleted = 0 AND ra.roleid $rolesql AND ra.contextid $ctxsql";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params + $roleparams + $ctxparams));
    }

    /**
     * Which students can access the activity, as far as availability can tell for a list (groups,
     * groupings, profile fields). Date and completion conditions are checked live in the student
     * view instead.
     *
     * @param \cm_info $cm
     * @param int[] $userids
     * @return bool[] userid => can access
     */
    public static function accessible(\cm_info $cm, array $userids): array {
        $result = array_fill_keys($userids, false);
        if (!$userids) {
            return $result;
        }
        $users = [];
        foreach ($userids as $id) {
            $users[$id] = (object)['id' => $id];
        }
        $info = new \core_availability\info_module($cm);
        foreach ($info->filter_user_list($users) as $id => $unused) {
            $result[$id] = true;
        }
        return $result;
    }
}
