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
 * Finished work goes to Past, and student rows say only what is unusual.
 *
 * From tests/behat/student-rows-say-only-the-unusual.feature: each test is one of its scenarios, run by view_testcase
 * one sentence at a time; the sentences that read the page are checked against the rendered dashboard. Every class is
 * covered: a scenario reaches the views through the adapters and the index.
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
final class student_rows_say_only_the_unusual_test extends view_testcase {
    /**
     * Undated marking that is all done goes to Completed marking
     */
    public function test_undated_marking_that_is_all_done_goes_to_completed_marking(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Completed marking" section');
        $this->step('And "A1" should not appear in the "Undated" section');
    }

    /**
     * Undated work that is all marked and released goes to the convenor's Past
     */
    public function test_undated_work_that_is_all_marked_and_released_goes_to_the_convenor_s_past(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And a summative "assign" "A2" in "M1" with no due date exists');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And "A2" should appear in the "Undated" section');
    }

    /**
     * Graded undated work moves to Past, placed by the date feedback was given
     */
    public function test_graded_undated_work_moves_to_past_placed_by_the_date_feedback_was_given(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And a summative "assign" "A2" in "M1" with no due date exists');
        $this->step('And a summative "assign" "A3" in "M1" was due "3 days ago"');
        $this->step('And "student1" submitted "A1" "6 days ago"');
        $this->step('And "student1" submitted "A2" "2 days ago"');
        $this->step('And "student1" submitted "A3" "4 days ago"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A2"');
        $this->step('And "marker1" marks the submission of "student1" for "A3"');
        $this->step('And the grade of "student1" for "A1" was released "5 days ago"');
        $this->step('And the grade of "student1" for "A2" was released "1 day ago"');
        $this->step('And the grade of "student1" for "A3" was released "2 days ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "Past" section should list "A2, A3, A1" in that order');
        $this->step('And "A1" should show "Feedback released"');
    }

    /**
     * Work not yet handed in shows no status pill in Upcoming
     */
    public function test_work_not_yet_handed_in_shows_no_status_pill_in_upcoming(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" is due "in 10 days"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A1" should have no status pill');
        $this->step('And "A1" should show "Submit"');
    }

    /**
     * Generic activities show no status pill before the deadline or without one
     */
    public function test_generic_activities_show_no_status_pill_before_the_deadline_or_without_one(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given the "Workshop" activity type is enabled in My assessments');
        $this->step('And a summative "workshop" "W1" in "M1" is due "in 10 days"');
        $this->step('And a summative "workshop" "W2" in "M1" with no due date exists');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "W1" should have no status pill');
        $this->step('And "W2" should have no status pill');
    }

    /**
     * Nothing on the dashboard says "Action required"
     */
    public function test_nothing_on_the_dashboard_says_action_required(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 days ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then I should not see "Action required" in the "My assessments" block');
    }

    /**
     * Work handed in on time carries no modifier icon
     */
    public function test_work_handed_in_on_time_carries_no_modifier_icon(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 days ago"');
        $this->step('And "student1" submitted "A1" "3 days ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And the activity icon of "A1" should have no modifiers');
    }

    /**
     * Work handed in before an extended deadline is not late
     */
    public function test_work_handed_in_before_an_extended_deadline_is_not_late(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has an extension on "A1" to "1 day ago"');
        $this->step('And "student1" submitted "A1" "2 days ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the activity icon of "A1" should not have the "late" modifier');
        $this->step('And the activity icon of "A1" should have the "extension" modifier');
    }

    /**
     * An on-time mark has no late penalty popover
     */
    public function test_an_on_time_mark_has_no_late_penalty_popover(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" submitted "A1" "4 days ago"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should not have a late penalty hint');
    }
}
