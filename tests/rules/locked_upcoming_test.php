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
 * Work the student can no longer change stays in Upcoming, with the reason in place of Submit.
 *
 * From tests/behat/locked-upcoming.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class locked_upcoming_test extends view_testcase {
    /**
     * At the deadline the row moves to Past as usual
     */
    public function test_at_the_deadline_the_row_moves_to_past_as_usual(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with required Submit was due "1 hour ago"');
        $this->step('And "student1" submitted "A1" "2 hours ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And "A1" should show the status "In marking"');
        $this->step('And "A1" should not have a "Can\'t resubmit" button');
    }

    /**
     * Reverted to draft, the row is ordinary again
     */
    public function test_reverted_to_draft_the_row_is_ordinary_again(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is reverted to draft');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A1" should have a "Submit" button');
        $this->step('And "A1" should not have a "Can\'t resubmit" button');
    }

    /**
     * A draft under required Submit keeps Submit
     */
    public function test_a_draft_under_required_submit_keeps_submit(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with required Submit is due "in 3 days"');
        $this->step('And "student1" has saved a draft of "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A1" should have a "Submit" button');
    }

    /**
     * Work handed in that can still be changed keeps Submit
     */
    public function test_work_handed_in_that_can_still_be_changed_keeps_submit(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" is due "in 3 days"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A1" should show the status "Submitted"');
        $this->step('And "A1" should have a "Submit" button');
        $this->step('And "A1" should not have a "Can\'t resubmit" button');
    }

    /**
     * A reopened attempt after a final submission is upcoming work
     */
    public function test_a_reopened_attempt_after_a_final_submission_is_upcoming_work(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with required Submit and reopened attempts was due "3 days '
            . 'ago"');
        $this->step('And "student1" submitted "A1" "4 days ago"');
        $this->step('And "teacher1" grades "student1" on "A1" with "40"');
        $this->step('And the submission of "student1" for "A1" is reopened');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A1" should have no status pill');
        $this->step('And "A1" should have a "Submit" button');
    }

    /**
     * Finalised coursework stays in Upcoming until its deadline
     */
    public function test_finalised_coursework_stays_in_upcoming_until_its_deadline(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "coursework" "C1" in "M1" is due "in 2 days"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should appear in the "Upcoming" section');
        $this->step('And "C1" should show the status "Submitted"');
        $this->step('And "C1" should have a "Can\'t resubmit" button');
    }

    /**
     * A coursework draft that isn't final keeps Submit
     */
    public function test_a_coursework_draft_that_isn_t_final_keeps_submit(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "coursework" "C1" in "M1" is due "in 2 days"');
        $this->step('And "student1" has saved a draft of "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should appear in the "Upcoming" section');
        $this->step('And "C1" should have a "Submit" button');
    }

    /**
     * Past the deadline, locked work that was never handed in goes to Past
     */
    public function test_past_the_deadline_locked_work_that_was_never_handed_in_goes_to_past(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "1 hour ago"');
        $this->step('And "teacher1" locks submissions for "student1" on "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And "A1" should not have a "Can\'t submit" button');
    }

    /**
     * Unlocking a submission shows at once
     */
    public function test_unlocking_a_submission_shows_at_once(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" is due "in 3 days"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "teacher1" locks submissions for "student1" on "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When "teacher1" unlocks submissions for "student1" on "A1"');
        $this->step('And I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should not have a "Can\'t resubmit" button');
        $this->step('And "A1" should have a "Submit" button');
    }
}
