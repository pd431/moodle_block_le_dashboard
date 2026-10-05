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

/**
 * Event observers. Activity events are generated from each adapter's events() list;
 * tests/observer_test.php checks the two agree.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Observers that write the index straight away run only after the change's transaction commits
// ('internal' => false), so a re-index can never roll back, or wait for a lock inside, someone
// else's transaction (PR2). The rest only queue tasks, which commit with the change.
$observers = [
    ['eventname' => '\core\event\course_module_created', 'callback' => '\block_le_dashboard\observer::course_module_changed'],
    ['eventname' => '\core\event\course_module_updated', 'callback' => '\block_le_dashboard\observer::course_module_changed'],
    ['eventname' => '\core\event\course_module_deleted', 'callback' => '\block_le_dashboard\observer::course_module_deleted',
        'internal' => false],
    ['eventname' => '\core\event\tag_added', 'callback' => '\block_le_dashboard\observer::tag_changed'],
    ['eventname' => '\core\event\tag_removed', 'callback' => '\block_le_dashboard\observer::tag_changed'],
    ['eventname' => '\core\event\user_enrolment_created', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\user_enrolment_updated', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\user_enrolment_deleted', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\role_assigned', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\role_unassigned', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\group_member_added', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\group_member_removed', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\course_section_updated', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    // Members leave a deleted group without group_member_removed; groupings and enrolment methods
    // change team groupings, restrictions and whole cohorts (EC6).
    ['eventname' => '\core\event\group_deleted', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\grouping_deleted', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\grouping_group_assigned', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\grouping_group_unassigned', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\enrol_instance_updated', 'callback' => '\block_le_dashboard\observer::course_people_changed'],
    ['eventname' => '\core\event\course_restored', 'callback' => '\block_le_dashboard\observer::course_restored'],
    ['eventname' => '\core\event\course_deleted', 'callback' => '\block_le_dashboard\observer::course_deleted',
        'internal' => false],
    ['eventname' => '\core\event\user_deleted', 'callback' => '\block_le_dashboard\observer::user_deleted'],
    ['eventname' => '\core\event\user_graded', 'callback' => '\block_le_dashboard\observer::user_graded'],
    ['eventname' => '\core\event\grade_item_updated', 'callback' => '\block_le_dashboard\observer::grade_item_updated'],
    ['eventname' => '\core\event\calendar_event_created', 'callback' => '\block_le_dashboard\observer::calendar_event_changed'],
    ['eventname' => '\core\event\calendar_event_updated', 'callback' => '\block_le_dashboard\observer::calendar_event_changed'],
    ['eventname' => '\core\event\calendar_event_deleted', 'callback' => '\block_le_dashboard\observer::calendar_event_changed'],
    ['eventname' => '\mod_assign\event\assessable_submitted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\submission_status_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\submission_removed', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\submission_graded', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\extension_granted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\marker_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\workflow_state_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\user_override_created', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\user_override_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\user_override_deleted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\group_override_created', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\group_override_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_assign\event\group_override_deleted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\attempt_submitted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\attempt_deleted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\attempt_regraded', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\attempt_reopened', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\attempt_manual_grading_completed',
        'callback' => '\block_le_dashboard\observer::module_event'],
    ['eventname' => '\mod_quiz\event\question_manually_graded', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\quiz_grade_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\user_override_created', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\user_override_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\user_override_deleted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\group_override_created', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\group_override_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_quiz\event\group_override_deleted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_peerwork\event\assessable_submitted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_peerwork\event\submission_created', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_peerwork\event\submission_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_peerwork\event\submission_cleared', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_peerwork\event\submission_graded', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_peerwork\event\grades_released', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_coursework\event\assessable_submitted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_coursework\event\coursework_deadline_changed',
        'callback' => '\block_le_dashboard\observer::module_event'],
    ['eventname' => '\mod_coursework\event\extension_created', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_coursework\event\extension_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_coursework\event\extension_deleted', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_coursework\event\personaldeadline_created', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_coursework\event\personaldeadline_updated', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
    ['eventname' => '\mod_coursework\event\feedback_changed', 'callback' => '\block_le_dashboard\observer::module_event',
        'internal' => false],
];
