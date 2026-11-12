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
 * A quiz's due date is its deadline and its close date the cut-off.
 *
 * From tests/behat/quiz-due-date.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class quiz_due_date_test extends view_testcase {
    /**
     * A quiz with a due date and no close date is dated
     */
    public function test_a_quiz_with_a_due_date_and_no_close_date_is_dated(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the site runs Moodle 5.3 or later');
        $this->step('And a summative "quiz" "Q1" in "M1" is due "in 2 days" and never closes');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Upcoming" section');
        $this->step('And "Q1" should show "Due in 2 days"');
    }

    /**
     * A quiz past its close date is past, whatever its due date
     */
    public function test_a_quiz_past_its_close_date_is_past_whatever_its_due_date(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the site runs Moodle 5.3 or later');
        $this->step('And a summative "quiz" "Q1" in "M1" is due "3 days ago" and closes "yesterday"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Past" section');
    }

    /**
     * A quiz whose first attempt was finished after the due date is late
     */
    public function test_a_quiz_whose_first_attempt_was_finished_after_the_due_date_is_late(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the site runs Moodle 5.3 or later');
        $this->step('And a summative "quiz" "Q1" in "M1" with 2 attempts allowed is due "2 days ago" and closes "in 5 '
            . 'days"');
        $this->step('And "student1" submitted "Q1" "yesterday"');
        $this->step('And "student2" submitted "Q1" "3 days ago"');
        $this->step('And "student2" submitted "Q1" "yesterday"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should have a "late" hint');
        $this->step('And I log in as "student2" and view my dashboard');
        $this->step('And "Q1" should not have a "late" hint');
    }

    /**
     * A student's own due date on a quiz moves their deadline, not anyone else's
     */
    public function test_a_student_s_own_due_date_on_a_quiz_moves_their_deadline_not_anyone_else_s(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the site runs Moodle 5.3 or later');
        $this->step('And a summative "quiz" "Q1" in "M1" is due "yesterday" and closes "in 5 days"');
        $this->step('And "student1" has a quiz override on "Q1" due "in 3 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Upcoming" section');
        $this->step('And "Q1" should show "Due in 3 days"');
        $this->step('And I log in as "student2" and view my dashboard');
        $this->step('And "Q1" should appear in the "Overdue" section');
    }

    /**
     * A quiz's academic year follows its due date
     */
    public function test_a_quiz_s_academic_year_follows_its_due_date(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2025-09-15##', 'enddate' => '##2026-06-30##'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the site runs Moodle 5.3 or later');
        $this->step('And it is now "15 September 2026 10:00" in "Europe/London"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And a summative "quiz" "Q1" in "M1" is due "20 August 2026 12:00 Europe/London" and closes "10 '
            . 'September 2026 12:00 Europe/London"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view the "Student view" for academic year "2025/26"');
        $this->step('Then "Q1" should appear in the "Past" section');
        $this->step('And I log in as "student1" and view the "Student view" for academic year "2026/27"');
        $this->step('And I should not see "Q1" in the "My assessments" block');
    }

    /**
     * A quiz without a due date is dated by its close, on every version
     */
    public function test_a_quiz_without_a_due_date_is_dated_by_its_close_on_every_version(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "quiz" "Q1" in "M1" is due "in 3 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Upcoming" section');
        $this->step('And "Q1" should show "Due in 3 days"');
    }
}
