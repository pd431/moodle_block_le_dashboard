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
 * Assessments with no online submission.
 *
 * From tests/behat/no-submission.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class no_submission_test extends view_testcase {
    /**
     * After the date, an in-person assessment waits for feedback in Past, never overdue
     */
    public function test_after_the_date_an_in_person_assessment_waits_for_feedback_in_past_never_overdue(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "workshop" "Viva" in "M1" tagged "inperson" was due "2 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Viva" should appear in the "Past" section');
        $this->step('And "Viva" should show the status "Awaiting feedback"');
        $this->step('And "Viva" should not show "Not submitted"');
        $this->step('And "Viva" should not have a "nosubmission" hint');
        $this->step('And the activity icon of "Viva" should have no modifiers');
    }

    /**
     * An assignment with no submission types is treated the same way
     */
    public function test_an_assignment_with_no_submission_types_is_treated_the_same_way(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "assign" "Exam" in "M1" with no online submission was due "2 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Exam" should appear in the "Past" section');
        $this->step('And "Exam" should show the status "Awaiting feedback"');
        $this->step('And "Exam" should not show "Not submitted"');
    }

    /**
     * Markers count every student as ready to mark from the date
     */
    public function test_markers_count_every_student_as_ready_to_mark_from_the_date(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "workshop" "Viva" in "M1" tagged "inperson" was due "2 days ago"');
        $this->step('And the gradebook grade of "student1" for "Viva" is set to "64"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "teacher1" and view the "My marking"');
        $this->step('Then "Viva" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And "Viva" should have a "nosubmission" hint');
        $this->step('And "Viva" should not show a "Submitted" progress bar');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "Viva" should appear in the "In progress" section');
        $this->step('And "Viva" should show a "Marked" progress bar with "1" of "2"');
        $this->step('And "Viva" should have a "nosubmission" hint');
    }

    /**
     * Before the date, markers have nothing to mark yet
     */
    public function test_before_the_date_markers_have_nothing_to_mark_yet(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "workshop" "Viva" in "M1" tagged "inperson" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "teacher1" and view the "My marking"');
        $this->step('Then "Viva" should not appear in the "Marking in progress" section');
    }

    /**
     * A workshop without the in-person tag keeps the generic rules
     */
    public function test_a_workshop_without_the_in_person_tag_keeps_the_generic_rules(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "workshop" "L1" in "M1" was due "2 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "L1" should show the status "Closed"');
        $this->step('And "L1" should not have a "nosubmission" hint');
    }

    /**
     * An attendance activity has no online submission
     */
    public function test_an_attendance_activity_has_no_online_submission(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "attendance" "Seminars" in "M1" was due "2 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Seminars" should show the status "Awaiting feedback"');
    }

    /**
     * Coursework assessed by attendance has no online submission
     */
    public function test_coursework_assessed_by_attendance_has_no_online_submission(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "coursework" "Presentation" in "M1" with submission type attendance was due "2 '
            . 'days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Presentation" should show the status "Awaiting feedback"');
        $this->step('And "Presentation" should not show "Not submitted"');
    }
}
