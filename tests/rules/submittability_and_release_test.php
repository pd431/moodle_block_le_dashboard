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
 * Rows leave Upcoming when nothing more can be handed in, and marks release as the activity does.
 *
 * From tests/behat/submittability-and-release.feature: each test is one of its scenarios, run by view_testcase one
 * sentence at a time; the sentences that read the page are checked against the rendered dashboard. Every class is
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
final class submittability_and_release_test extends view_testcase {
    /**
     * A quiz whose attempts are used up stays in Upcoming, without Submit
     */
    public function test_a_quiz_whose_attempts_are_used_up_stays_in_upcoming_without_submit(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1" with 1 attempt allowed is due "next week"');
        $this->step('And "student1" has finished 1 attempts at "Q1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Upcoming" section');
        $this->step('And "Q1" should show the status "Submitted"');
        $this->step('And "Q1" should not have a "Submit" button');
        $this->step('And "Q1" should have a "Can\'t resubmit" button');
    }

    /**
     * A quiz with attempts left stays in Upcoming
     */
    public function test_a_quiz_with_attempts_left_stays_in_upcoming(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1" with 2 attempts allowed is due "next week"');
        $this->step('And "student1" has finished 1 attempts at "Q1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Upcoming" section');
    }

    /**
     * A quiz override that extends the close keeps the quiz open for that student
     */
    public function test_a_quiz_override_that_extends_the_close_keeps_the_quiz_open_for_that_student(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1" was due "2 hours ago"');
        $this->step('And "student1" has a quiz override on "Q1" closing "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Upcoming" section');
        $this->step('And "Q1" should show "Submit"');
        $this->step('When I log in as "student2" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Past" section');
    }

    /**
     * An assignment past its cut-off goes to Past
     */
    public function test_an_assignment_past_its_cut_off_goes_to_past(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "assign" "A1" in "M1" with a cut-off "1 hour ago" was due "2 hours ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And "A1" should show the status "Not submitted"');
        $this->step('And "A1" should not show "Submit"');
    }

    /**
     * An assignment that still accepts late work stays in Overdue
     */
    public function test_an_assignment_that_still_accepts_late_work_stays_in_overdue(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "assign" "A1" in "M1" with a cut-off "tomorrow" was due "2 hours ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Overdue" section');
        $this->step('And "A1" should show "Submit"');
    }

    /**
     * The convenor's In progress drops assessments with nothing left to mark
     *
     * Example: type = workshop, name = L1, options =
     */
    public function test_the_convenor_s_in_progress_drops_assessments_with_nothing_left_to_mark_1(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "workshop" "L1" in "M1"  was due "5 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "L1" should appear in the "Past" section');
        $this->step('And "L1" should not appear in the "In progress" section');
    }

    /**
     * The convenor's In progress drops assessments with nothing left to mark
     *
     * Example: type = assign, name = A1, options = with a cut-off "4 days ago"
     */
    public function test_the_convenor_s_in_progress_drops_assessments_with_nothing_left_to_mark_2(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "assign" "A1" in "M1" with a cut-off "4 days ago" was due "5 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should appear in the "Past" section');
        $this->step('And "A1" should not appear in the "In progress" section');
    }

    /**
     * The convenor's In progress drops assessments with nothing left to mark
     *
     * Example: type = quiz, name = Q1, options =
     */
    public function test_the_convenor_s_in_progress_drops_assessments_with_nothing_left_to_mark_3(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1"  was due "5 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "Q1" should appear in the "Past" section');
        $this->step('And "Q1" should not appear in the "In progress" section');
    }

    /**
     * An assignment that can still receive work stays in the convenor's In progress
     */
    public function test_an_assignment_that_can_still_receive_work_stays_in_the_convenor_s_in_progress(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has an extension on "A1" to "next week"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should appear in the "In progress" section');
    }

    /**
     * Marking early doesn't show New work
     */
    public function test_marking_early_doesn_t_show_new_work(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And "student1" submitted "A1" "2 days ago"');
        $this->step('And "teacher1" marks the submission of "student1" for "A1"');
        $this->step('And "student2" submitted "A1" "1 hour ago"');
        $this->step('And the deadline of "A1" is changed directly in the database to "1 hour ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "teacher1" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Marking in progress" section');
        $this->step('And "A1" should not have a "returned" hint');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should not have a "returned" hint');
    }

    /**
     * Work arriving after marking was finished still shows New work
     */
    public function test_work_arriving_after_marking_was_finished_still_shows_new_work(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" submitted "A1" "6 days ago"');
        $this->step('And "teacher1" marks the submission of "student1" for "A1"');
        $this->step('And "student2" submitted "A1" "now"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "teacher1" and view the "My marking"');
        $this->step('Then "A1" should have a "returned" hint');
    }

    /**
     * Unticking a type removes it from courses outside the live window
     */
    public function test_unticking_a_type_removes_it_from_courses_outside_the_live_window(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a course "OLD" with a summative "quiz" "OldQ" was due "3 years ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When the "quiz" activity type is unticked on the Activity types page');
        $this->step('And the rebuild task runs');
        $this->step('Then "OldQ" should not be in the index');
    }

    /**
     * Removing a tag from the settings removes its activities outside the live window
     */
    public function test_removing_a_tag_from_the_settings_removes_its_activities_outside_the_live_window(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a course "OLD" with a formative "assign" "OldA" was due "3 years ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When the formative tag is changed to "practice" in the settings');
        $this->step('And the rebuild task runs');
        $this->step('Then "OldA" should not be in the index');
    }

    /**
     * Activities whose submissions the block can't see show no Not submitted icon
     */
    public function test_activities_whose_submissions_the_block_can_t_see_show_no_not_submitted_icon(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "workshop" "L1" in "M1" was due "5 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "L1" should appear in the "Past" section');
        $this->step('And "L1" should show the status "Closed"');
        $this->step('And the activity icon of "L1" should have no modifiers');
    }

    /**
     * Manually grading a quiz credits the marker
     */
    public function test_manually_grading_a_quiz_credits_the_marker(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1" with an essay question was due "2 days ago"');
        $this->step('And "student1" has finished 1 attempts at "Q1"');
        $this->step('And "teacher1" grades the essay of "student1" in "Q1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "Q1" should list marker "Tim Teacher"');
    }

    /**
     * Publishing coursework grades reaches the dashboard without a Refresh
     */
    public function test_publishing_coursework_grades_reaches_the_dashboard_without_a_refresh(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "teacher1" grades "student1" on "C1" with "60"');
        $this->step('And the My assessments index is up to date');
        $this->step('When the agreed grade for "student1" on "C1" is published');
        $this->step('And I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should show the status "Feedback released"');
    }

    /**
     * A quiz that never releases marks and has no close date leaves Undated once marked
     */
    public function test_a_quiz_that_never_releases_marks_and_has_no_close_date_leaves_undated_once_marked(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1" with no due date exists');
        $this->step('And the review options of "Q1" never show marks');
        $this->step('And "student1" has finished 1 attempts at "Q1"');
        $this->step('And the last attempt of "student1" at "Q1" has been graded');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Past" section');
        $this->step('And "Q1" should show the status "Submitted"');
    }

    /**
     * A quiz that never releases marks waits in In marking while an attempt needs grading
     */
    public function test_a_quiz_that_never_releases_marks_waits_in_in_marking_while_an_attempt_needs_grading(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1" with marks never shown was due "2 days ago"');
        $this->step('And "student1" has finished 1 attempts at "Q1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should show the status "In marking"');
    }

    /**
     * Marks shown after close wait for the student's own close
     */
    public function test_marks_shown_after_close_wait_for_the_student_s_own_close(): void {
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
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the "workshop" activity type is enabled in My assessments');
        $this->step('Given a summative "quiz" "Q1" in "M1" with marks shown after close only was due "2 hours ago"');
        $this->step('And "student1" has a quiz override on "Q1" closing "tomorrow"');
        $this->step('And "student1" and "student2" have finished "Q1"');
        $this->step('And the last attempt of "student1" at "Q1" has been graded');
        $this->step('And the last attempt of "student2" at "Q1" has been graded');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Upcoming" section');
        $this->step('And "Q1" should not show "Feedback released"');
        $this->step('When I log in as "student2" and view my dashboard');
        $this->step('Then "Q1" should show the status "Feedback released"');
    }
}
