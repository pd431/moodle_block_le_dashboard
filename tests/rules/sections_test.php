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
 * Each view has the sections its users work in.
 *
 * From tests/behat/sections.feature: each test is one of its scenarios, run by view_testcase one sentence at a time;
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
final class sections_test extends view_testcase {
    /**
     * Work I've marked while extensions run is in Completed marking, with the extensions hint
     */
    public function test_work_i_ve_marked_while_extensions_run_is_in_completed_marking_with_the_extensions_hint(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has an extension on "A1" to "in 2 days"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Completed marking" section');
        $this->step('And "A1" should have an extensions hint');
    }

    /**
     * Completed marking holds only work I marked, where nothing is allocated
     */
    public function test_completed_marking_holds_only_work_i_marked_where_nothing_is_allocated(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker2" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should not appear in the "Completed marking" section');
        $this->step('When I log in as "marker2" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Completed marking" section');
    }

    /**
     * Completed marking holds only work I marked, with 2 markers and nothing allocated
     */
    public function test_completed_marking_holds_only_work_i_marked_with_2_markers_and_nothing_allocated(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with 2 markers was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker2" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should not appear in the "Completed marking" section');
        $this->step('When I log in as "marker2" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Completed marking" section');
    }

    /**
     * An assessment with no submissions goes to Past at its deadline, and back if late work arrives
     */
    public function test_an_assessment_with_no_submissions_goes_to_past_at_its_deadline_and_back_if_late_work_arrives(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('When "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('And I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "In progress" section');
    }

    /**
     * A student's sections hold work by its deadline
     */
    public function test_a_student_s_sections_hold_work_by_its_deadline(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "in 5 days"');
        $this->step('And a summative "assign" "A2" in "M1" is due "in 3 days"');
        $this->step('And a summative "assign" "A3" in "M1" was due "2 days ago"');
        $this->step('And a summative "assign" "A4" in "M1" was due "4 days ago"');
        $this->step('And "student1" has submitted "A2"');
        $this->step('And "student1" has submitted "A4"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A2" should appear in the "Upcoming" section');
        $this->step('And "A3" should appear in the "Overdue" section');
        $this->step('And "A4" should appear in the "Past" section');
    }

    /**
     * A partly released assessment stays In progress for the convenor until the rest is released
     */
    public function test_a_partly_released_assessment_stays_in_progress_for_the_convenor_until_the_rest_is_released(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with marking workflow was due "5 days ago"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student2" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "In progress" section');
        $this->step('When the grade of "student2" for "A1" is released');
        $this->step('And the My assessments index is up to date');
        $this->step('And I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And "A1" should show the grading status "Released to students"');
    }
}
