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
 * Students see coursework's own feedback release date, not the marking deadline.
 *
 * From tests/behat/coursework-feedback-release-date.feature: each test is one of its scenarios, run by view_testcase
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
final class coursework_feedback_release_date_test extends view_testcase {
    /**
     * A personal feedback date comes first (the Behat scenario, here too so a
     * run without a browser checks it)
     */
    public function test_a_personal_feedback_date_comes_first(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'config', 'value' => 'value', 'plugin' => 'plugin'],
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('Given a summative "coursework" "C1" in "M1" with a marking deadline "in 5 days" and an individual '
            . 'feedback date "in 15 days" and personal feedback dates was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student1" has a personal feedback date "in 20 days" on "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should show "Feedback expected" with the date "in 20 days"');
    }

    /**
     * Without a personal date, the individual feedback date
     */
    public function test_without_a_personal_date_the_individual_feedback_date(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'config', 'value' => 'value', 'plugin' => 'plugin'],
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('Given a summative "coursework" "C1" in "M1" with a marking deadline "in 5 days" and an individual '
            . 'feedback date "in 15 days" and personal feedback dates was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('And "student1" has a personal feedback date "in 20 days" on "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student2" and view my dashboard');
        $this->step('Then "C1" should show "Feedback expected" with the date "in 15 days"');
    }

    /**
     * Personal feedback dates switched off are ignored
     */
    public function test_personal_feedback_dates_switched_off_are_ignored(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'config', 'value' => 'value', 'plugin' => 'plugin'],
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('Given a summative "coursework" "C1" in "M1" with a marking deadline "in 5 days" and an individual '
            . 'feedback date "in 15 days" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student1" has a personal feedback date "in 20 days" on "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should show "Feedback expected" with the date "in 15 days"');
    }

    /**
     * With neither release date, the marking deadline as before
     */
    public function test_with_neither_release_date_the_marking_deadline_as_before(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'config', 'value' => 'value', 'plugin' => 'plugin'],
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('Given a summative "coursework" "C1" in "M1" with a marking deadline "in 5 days" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should show "Feedback expected" with the date "in 5 days"');
    }

    /**
     * A release date shows even when marking deadlines are hidden from students
     */
    public function test_a_release_date_shows_even_when_marking_deadlines_are_hidden_from_students(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'config', 'value' => 'value', 'plugin' => 'plugin'],
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'config', 'value' => 'value', 'plugin' => 'plugin'],
            ['config' => 'feedbackdatemode', 'value' => 'none', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "coursework" "C1" in "M1" with a marking deadline "in 5 days" and an individual '
            . 'feedback date "in 15 days" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should show "Feedback expected" with the date "in 15 days"');
    }

    /**
     * Staff still see the marking deadline
     */
    public function test_staff_still_see_the_marking_deadline(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'config', 'value' => 'value', 'plugin' => 'plugin'],
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('Given a summative "coursework" "C1" in "M1" with a marking deadline "in 5 days" and an individual '
            . 'feedback date "in 15 days" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show "Marking deadline" with the date "in 5 days"');
    }
}
