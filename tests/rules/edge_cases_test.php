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

namespace block_le_dashboard\rules;

use block_le_dashboard\view_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../view_testcase.php');

/**
 * Work stays visible when people and assessments move at awkward moments.
 *
 * From tests/behat/edge-cases.feature: each test is one of its scenarios, run by view_testcase one sentence at a time;
 * the sentences that read the page are checked against the rendered dashboard. Every class is covered: a scenario
 * reaches the views through the adapters and the index.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\external\get_view
 * @covers     \block_le_dashboard\external\refresh
 * @covers     \block_le_dashboard\form\year
 * @covers     \block_le_dashboard\local\adapter\assign
 * @covers     \block_le_dashboard\local\adapter\base
 * @covers     \block_le_dashboard\local\adapter\coursework
 * @covers     \block_le_dashboard\local\adapter\generic
 * @covers     \block_le_dashboard\local\adapter\peerwork
 * @covers     \block_le_dashboard\local\adapter\quiz
 * @covers     \block_le_dashboard\local\adapter\registry
 * @covers     \block_le_dashboard\local\config
 * @covers     \block_le_dashboard\local\genericname
 * @covers     \block_le_dashboard\local\guidance
 * @covers     \block_le_dashboard\local\index\cm_row
 * @covers     \block_le_dashboard\local\index\enrolment
 * @covers     \block_le_dashboard\local\index\indexer
 * @covers     \block_le_dashboard\local\index\marking_row
 * @covers     \block_le_dashboard\local\index\pass
 * @covers     \block_le_dashboard\local\index\queue
 * @covers     \block_le_dashboard\local\index\student_row
 * @covers     \block_le_dashboard\local\index\writer
 * @covers     \block_le_dashboard\local\memo
 * @covers     \block_le_dashboard\local\mitigation
 * @covers     \block_le_dashboard\local\output\format
 * @covers     \block_le_dashboard\local\scope
 * @covers     \block_le_dashboard\local\setupchecks
 * @covers     \block_le_dashboard\local\tags
 * @covers     \block_le_dashboard\local\testing
 * @covers     \block_le_dashboard\local\untagged
 * @covers     \block_le_dashboard\local\view\base
 * @covers     \block_le_dashboard\local\view\classifier
 * @covers     \block_le_dashboard\local\view\convenor
 * @covers     \block_le_dashboard\local\view\factory
 * @covers     \block_le_dashboard\local\view\marking_rules
 * @covers     \block_le_dashboard\local\view\staff
 * @covers     \block_le_dashboard\local\view\staff_row
 * @covers     \block_le_dashboard\local\view\student
 * @covers     \block_le_dashboard\local\years
 * @covers     \block_le_dashboard\observer
 * @covers     \block_le_dashboard\output\dashboard
 * @covers     \block_le_dashboard\privacy\provider
 * @covers     \block_le_dashboard\task\backfill
 * @covers     \block_le_dashboard\task\reconcile
 * @covers     \block_le_dashboard\task\reindex
 */
final class edge_cases_test extends view_testcase {
    /**
     * A marker who only loses their marking role is treated the same way
     */
    public function test_a_marker_who_only_loses_their_marking_role_is_treated_the_same_way(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When "marker1" is enrolled in "M1" as "student" only');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "convenor" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
    }

    /**
     * Marking outstanding on last year's module stays in My marking
     */
    public function test_marking_outstanding_on_last_year_s_module_stays_in_my_marking(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 0', 'shortname' => 'M0', 'startdate' => '##-400 days##'],
        ]);
        $this->step('And "marker1" is enrolled in "M0" as "teacher"');
        $this->step('And "student1" is enrolled in "M0" as "student"');
        $this->step('And a summative "assign" "Old essay" in "M0" was due "2 days ago"');
        $this->step('And "student1" has submitted "Old essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "Old essay" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And the "To mark" summary count should be "1"');
    }

    /**
     * Last year's completed marking stays under its own year
     */
    public function test_last_year_s_completed_marking_stays_under_its_own_year(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 0', 'shortname' => 'M0', 'startdate' => '##-400 days##'],
        ]);
        $this->step('And "marker1" is enrolled in "M0" as "teacher"');
        $this->step('And "student1" is enrolled in "M0" as "student"');
        $this->step('And a summative "assign" "Old essay" in "M0" was due "60 days ago"');
        $this->step('And "student1" has submitted "Old essay"');
        $this->step('And "marker1" marks the submission of "student1" for "Old essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "Old essay" should not appear in the "Completed marking" section');
    }

    /**
     * A released mark on an assignment with no online submission shows as released
     */
    public function test_a_released_mark_on_an_assignment_with_no_online_submission_shows_as_released(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "Presentation" in "M1" with no online submission was due "2 days ago"');
        $this->step('And "marker1" marks the submission of "student1" for "Presentation"');
        $this->step('And the grade of "student1" for "Presentation" is released');
        $this->step('And the My assessments index catches up');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Presentation" should show "Feedback released"');
    }

    /**
     * Deleting a group updates its members without waiting for the night
     */
    public function test_deleting_a_group_updates_its_members_without_waiting_for_the_night(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "T1" in "M1" with team submission is due "in 2 days"');
        $this->step('And the following "groups" exist:', [
            ['name' => 'G1', 'course' => 'M1', 'idnumber' => 'G1'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'G1'],
            ['user' => 'student2', 'group' => 'G1'],
        ]);
        $this->step('And "student1" has submitted "T1" for "G1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When the group "G1" is deleted');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "student2" and view my dashboard');
        $this->step('Then "T1" should not show "Submitted"');
    }

    /**
     * Reallocating a marked script leaves the credit with the marker who marked it
     */
    public function test_reallocating_a_marked_script_leaves_the_credit_with_the_marker_who_marked_it(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with marker allocation was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "marker2"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker2"');
        $this->step('And the My assessments index catches up');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Completed marking" section');
        $this->step('When I log in as "marker2" and view the "My marking"');
        $this->step('Then "A1" should show a "Marked" progress bar with "0" of "1"');
    }

    /**
     * Agreement waits for the currently allocated markers
     */
    public function test_agreement_waits_for_the_currently_allocated_markers(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with marker allocation, 2 markers and manual agreement was '
            . 'due "2 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1" and "marker2"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker2" and "convenor"');
        $this->step('And "marker2" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index catches up');
        $this->step('When I log in as "marker2" and view the "My marking"');
        $this->step('Then "A1" should not show an "Agreed" progress bar');
    }

    /**
     * A released submission reverted to draft is upcoming work again, with no flag
     */
    public function test_a_released_submission_reverted_to_draft_is_upcoming_work_again_with_no_flag(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the submission of "student1" for "A1" is reverted to draft');
        $this->step('And the My assessments index catches up');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A1" should have no status pill');
        $this->step('And "A1" should not appear in the "Overdue" section');
    }

    /**
     * Students get no flag on undated rows, markers do
     */
    public function test_students_get_no_flag_on_undated_rows_markers_do(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A2" in "M1" with no due date exists');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A2" should not have a "setup" hint');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A2" should have a "setup" hint');
    }

    /**
     * The Undated section is hidden when nothing is undated
     */
    public function test_the_undated_section_is_hidden_when_nothing_is_undated(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "in 3 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then I should not see "Undated" in the "My assessments" block');
    }

    /**
     * Submitted work of a suspended student still counts in marking
     */
    public function test_submitted_work_of_a_suspended_student_still_counts_in_marking(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When the enrolment of "student1" in "M1" is suspended');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "2" to mark');
    }

    /**
     * Unenrolled students leave the counts, as in Moodle's grading table
     */
    public function test_unenrolled_students_leave_the_counts_as_in_moodle_s_grading_table(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When "student1" is unenrolled from "M1"');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
    }

    /**
     * A student enrolled after the grace period sees the work as not submitted, without the overdue icon
     */
    public function test_a_student_enrolled_after_the_grace_period_sees_the_work_as_not_submitted_without_the_overdue_icon(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And the following "users" exist:', [
            ['username' => 'student3', 'firstname' => 'Lee', 'lastname' => 'Late'],
        ]);
        $this->step('And "student3" is enrolled in "M1" as "student"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student3" and view my dashboard');
        $this->step('Then "A1" should not appear in the "Overdue" section');
        $this->step('And "A1" should appear in the "Past" section');
        $this->step('And "A1" should show "Not submitted"');
        $this->step('And the activity icon of "A1" should have the "notsubmitted" modifier');
        $this->step('And the activity icon of "A1" should not have the "overdue" modifier');
    }

    /**
     * A student enrolled inside the grace period can still submit
     */
    public function test_a_student_enrolled_inside_the_grace_period_can_still_submit(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 hours ago"');
        $this->step('And the following "users" exist:', [
            ['username' => 'student3', 'firstname' => 'Lee', 'lastname' => 'Late'],
        ]);
        $this->step('And "student3" is enrolled in "M1" as "student"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student3" and view my dashboard');
        $this->step('Then "A1" should appear in the "Overdue" section');
        $this->step('And "A1" should show "Submit"');
    }

    /**
     * A late joiner given an extension sees the work as Upcoming
     */
    public function test_a_late_joiner_given_an_extension_sees_the_work_as_upcoming(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And "convenor" is enrolled in "M1" as "teacher"');
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And the following "users" exist:', [
            ['username' => 'student3', 'firstname' => 'Lee', 'lastname' => 'Late'],
        ]);
        $this->step('And "student3" is enrolled in "M1" as "student"');
        $this->step('And "student3" has an extension on "A1" to "in 5 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student3" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
    }
}
