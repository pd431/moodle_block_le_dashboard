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

namespace block_le_dashboard\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use block_le_dashboard\local\index\indexer;

/**
 * Privacy provider. The index holds per-user data derived from the activity
 * plugins; it is stored against the user's own context, exported on request
 * and deleted by removing the rows (they are rebuilt from the plugins if the
 * user is still enrolled).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /** Preferences the block stores. */
    const PREFERENCES = ['view', 'showgrades', 'window', 'range', 'type', 'year', 'refreshing', 'lastrefresh'];

    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_le_dashboard_stu', [
            'userid' => 'privacy:metadata:stu:userid',
            'due' => 'privacy:metadata:stu:due',
            'submitted' => 'privacy:metadata:stu:submitted',
            'timesubmitted' => 'privacy:metadata:stu:timesubmitted',
            'timereleased' => 'privacy:metadata:stu:timereleased',
            'gradedisplay' => 'privacy:metadata:stu:gradedisplay',
            'mitigation' => 'privacy:metadata:stu:mitigation',
            'reopened' => 'privacy:metadata:stu:reopened',
            'allocatedto' => 'privacy:metadata:stu:allocatedto',
            'active' => 'privacy:metadata:stu:active',
            'enrolstart' => 'privacy:metadata:stu:enrolstart',
        ], 'privacy:metadata:stu');
        $collection->add_database_table('block_le_dashboard_mark', [
            'unitid' => 'privacy:metadata:mark:unitid',
            'allocatedto' => 'privacy:metadata:mark:allocatedto',
            'markedby' => 'privacy:metadata:mark:markedby',
            'timemarked' => 'privacy:metadata:mark:timemarked',
            'timeready' => 'privacy:metadata:mark:timeready',
            'unitdue' => 'privacy:metadata:mark:unitdue',
        ], 'privacy:metadata:mark');
        foreach (self::PREFERENCES as $name) {
            $collection->add_user_preference('block_le_dashboard_' . $name, 'privacy:metadata:preference:' . $name);
        }
        return $collection;
    }

    /**
     * Whether the user has any index rows.
     *
     * @param int $userid
     * @return bool
     */
    protected static function has_rows(int $userid): bool {
        global $DB;
        return $DB->record_exists('block_le_dashboard_stu', ['userid' => $userid])
            || $DB->record_exists_select('block_le_dashboard_stu', self::allocated_sql(), self::allocated_params($userid))
            || $DB->record_exists_select(
                'block_le_dashboard_mark',
                "(unitid = :u1 AND unittype = 'user') OR allocatedto = :u2 OR markedby = :u3",
                ['u1' => $userid, 'u2' => $userid, 'u3' => $userid]
            );
    }

    /**
     * Student rows that list the user as an allocated marker (stored as ",id,id,").
     *
     * @return string
     */
    protected static function allocated_sql(): string {
        global $DB;
        return $DB->sql_like('allocatedto', ':allocated');
    }

    /**
     * Parameters for allocated_sql().
     *
     * @param int $userid
     * @return array
     */
    protected static function allocated_params(int $userid): array {
        return ['allocated' => '%,' . $userid . ',%'];
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        if (self::has_rows($userid)) {
            $list->add_user_context($userid);
        }
        return $list;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && self::has_rows($context->instanceid)) {
            $userlist->add_user($context->instanceid);
        }
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || $context->instanceid != $userid) {
                continue;
            }
            $rows = [];
            foreach ($DB->get_records('block_le_dashboard_stu', ['userid' => $userid]) as $r) {
                $rows[] = [
                    'cmid' => $r->cmid,
                    'due' => $r->due ? transform::datetime($r->due) : null,
                    'submitted' => $r->submitted === null ? null : transform::yesno($r->submitted),
                    'timesubmitted' => $r->timesubmitted ? transform::datetime($r->timesubmitted) : null,
                    'timereleased' => $r->timereleased ? transform::datetime($r->timereleased) : null,
                    'grade' => $r->gradedisplay,
                ];
            }
            $marking = array_values($DB->get_records_select(
                'block_le_dashboard_mark',
                "(unitid = :u1 AND unittype = 'user') OR allocatedto = :u2 OR markedby = :u3",
                ['u1' => $userid, 'u2' => $userid, 'u3' => $userid],
                '',
                'id, cmid, stage, allocatedto, marked, markedby, timemarked'
            ));
            // Students allocated to this person as marker, counted per assessment.
            $allocated = array_values($DB->get_records_sql(
                "SELECT cmid, COUNT(1) AS students
                   FROM {block_le_dashboard_stu}
                  WHERE " . self::allocated_sql() . "
               GROUP BY cmid",
                self::allocated_params($userid)
            ));
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'block_le_dashboard')],
                (object)['assessments' => $rows, 'marking' => $marking, 'allocated' => $allocated]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_user) {
            (new indexer())->remove_user((int)$context->instanceid);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && $context->instanceid == $contextlist->get_user()->id) {
                (new indexer())->remove_user((int)$context->instanceid);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && in_array($context->instanceid, $userlist->get_userids())) {
            (new indexer())->remove_user((int)$context->instanceid);
        }
    }

    #[\Override]
    public static function export_user_preferences(int $userid) {
        foreach (self::PREFERENCES as $name) {
            $value = get_user_preferences('block_le_dashboard_' . $name, null, $userid);
            if ($value !== null) {
                writer::export_user_preference(
                    'block_le_dashboard',
                    'block_le_dashboard_' . $name,
                    $value,
                    get_string('privacy:metadata:preference:' . $name, 'block_le_dashboard')
                );
            }
        }
    }
}
