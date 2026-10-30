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
 * Finished marking shows where the assessment stands, with the marking detail on request.
 *
 * From tests/behat/staff-past-status.feature: each test is one of its scenarios, run by view_testcase one sentence at a
 * time; the sentences that read the page are checked against the rendered dashboard. Every class is covered: a scenario
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
final class staff_past_status_test extends view_testcase {
    /**
     * My share is done while a colleague is still grading
     */
    public function test_my_share_is_done_while_a_colleague_is_still_grading(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And "student3" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student3" for "A1" is allocated to "marker2"');
        $this->step('Given "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student2" for "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Completed marking" section');
        $this->step('And "A1" should show the grading status "Colleagues still grading"');
        $this->step('And "A1" should not show any progress bars');
    }

    /**
     * Everything marked, nothing released
     */
    public function test_everything_marked_nothing_released(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And "student3" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student3" for "A1" is allocated to "marker2"');
        $this->step('Given "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student2" for "A1"');
        $this->step('And "marker2" marks the submission of "student3" for "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should show the grading status "Marked, not yet released"');
    }

    /**
     * Some work released, the rest withheld, with the number withheld
     */
    public function test_some_work_released_the_rest_withheld_with_the_number_withheld(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And "student3" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student3" for "A1" is allocated to "marker2"');
        $this->step('Given "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student2" for "A1"');
        $this->step('And "marker2" marks the submission of "student3" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should show the grading status "Released to students. 2 withheld"');
        $this->step('When I log in as "convenor" and view my dashboard');
        $this->step('Then "A1" should appear in the "In progress" section');
    }

    /**
     * Everything released
     */
    public function test_everything_released(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And "student3" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student3" for "A1" is allocated to "marker2"');
        $this->step('Given "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student2" for "A1"');
        $this->step('And "marker2" marks the submission of "student3" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the grade of "student2" for "A1" is released');
        $this->step('And the grade of "student3" for "A1" is released');
        $this->step('When I log in as "marker2" and view my dashboard');
        $this->step('Then "A1" should show the grading status "Released to students"');
        $this->step('When I log in as "convenor" and view my dashboard');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And "A1" should show the grading status "Released to students"');
    }

    /**
     * A convenor's assessment with nothing released stays In progress, with its bars
     */
    public function test_a_convenor_s_assessment_with_nothing_released_stays_in_progress_with_its_bars(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And "student3" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student3" for "A1" is allocated to "marker2"');
        $this->step('Given "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student2" for "A1"');
        $this->step('And "marker2" marks the submission of "student3" for "A1"');
        $this->step('When I log in as "convenor" and view my dashboard');
        $this->step('Then "A1" should appear in the "In progress" section');
        $this->step('And "A1" should not show a grading status');
        $this->step('And "A1" should show progress bars "Submitted, Unallocated, Marked, Released"');
    }

    /**
     * Rows still being marked keep their bars and have no status
     */
    public function test_rows_still_being_marked_keep_their_bars_and_have_no_status(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And "student3" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student3" for "A1" is allocated to "marker2"');
        $this->step('Given "marker1" marks the submission of "student1" for "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And "A1" should not show a grading status');
        $this->step('And "A1" should show progress bars "Marked"');
    }
}
