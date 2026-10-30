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
 * Day counts and dates agree, in the viewer's own time zone.
 *
 * From tests/behat/consistent-dates.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class consistent_dates_test extends view_testcase {
    /**
     * "Due in" counts the calendar days to the date shown
     *
     * Example: now = Mon 12 October 2026 20:00, due = Wed 14 October 2026 12:00, note = Due in 2 days
     */
    public function test_due_in_counts_the_calendar_days_to_the_date_shown_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'timezone' => 'Europe/London'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher', 'timezone' => 'Europe/London'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "Wed 14 October 2026 12:00" in "Europe/London"');
        $this->step('And the My assessments index is up to date');
        $this->step('And it is now "Mon 12 October 2026 20:00" in "Europe/London"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Due in 2 days"');
    }

    /**
     * "Due in" counts the calendar days to the date shown
     *
     * Example: now = Mon 12 October 2026 08:00, due = Tue 13 October 2026 12:00, note = Due tomorrow
     */
    public function test_due_in_counts_the_calendar_days_to_the_date_shown_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'timezone' => 'Europe/London'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher', 'timezone' => 'Europe/London'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "Tue 13 October 2026 12:00" in "Europe/London"');
        $this->step('And the My assessments index is up to date');
        $this->step('And it is now "Mon 12 October 2026 08:00" in "Europe/London"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Due tomorrow"');
    }

    /**
     * "Due in" counts the calendar days to the date shown
     *
     * Example: now = Mon 12 October 2026 20:00, due = Tue 13 October 2026 12:00, note = Due in 16 h
     */
    public function test_due_in_counts_the_calendar_days_to_the_date_shown_3(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'timezone' => 'Europe/London'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher', 'timezone' => 'Europe/London'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "Tue 13 October 2026 12:00" in "Europe/London"');
        $this->step('And the My assessments index is up to date');
        $this->step('And it is now "Mon 12 October 2026 20:00" in "Europe/London"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Due in 16 h"');
    }

    /**
     * "Due in" counts the calendar days to the date shown
     *
     * Example: now = Mon 12 October 2026 11:15, due = Mon 12 October 2026 12:00, note = Due in 45 min
     */
    public function test_due_in_counts_the_calendar_days_to_the_date_shown_4(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'timezone' => 'Europe/London'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher', 'timezone' => 'Europe/London'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "Mon 12 October 2026 12:00" in "Europe/London"');
        $this->step('And the My assessments index is up to date');
        $this->step('And it is now "Mon 12 October 2026 11:15" in "Europe/London"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Due in 45 min"');
    }

    /**
     * Without the browser's help the count is in the Moodle zone
     */
    public function test_without_the_browser_s_help_the_count_is_in_the_moodle_zone(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'timezone' => 'Europe/London'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher', 'timezone' => 'Europe/London'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with a cut-off "Wed 14 October 2026 01:00 Europe/London" '
            . 'was due "Mon 12 October 2026 10:00 Europe/London"');
        $this->step('And the My assessments index is up to date');
        $this->step('And it is now "Mon 12 October 2026 20:00" in "Europe/London"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "You can still submit for 2 days"');
    }
}
