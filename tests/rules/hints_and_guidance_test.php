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
 * Admins can turn each optional hint off, on a Hints and guidance page with neutral defaults.
 *
 * From tests/behat/hints-and-guidance.feature: each test is one of its scenarios, run by view_testcase one sentence at
 * a time; the sentences that read the page are checked against the rendered dashboard. Every class is covered: a
 * scenario reaches the views through the adapters and the index.
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
final class hints_and_guidance_test extends view_testcase {
    /**
     * Turning a staff hint off removes it, and only it
     *
     * Example: key = hint_hidden, hint = hidden, other = extensions
     */
    public function test_turning_a_staff_hint_off_removes_it_and_only_it_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "A1" is hidden from students');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has an extension on "A1" to "in 2 days"');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'hint_hidden', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should not have a "hidden" hint');
        $this->step('And "A1" should have a "extensions" hint');
    }

    /**
     * Turning a staff hint off removes it, and only it
     *
     * Example: key = hint_extensions, hint = extensions, other = hidden
     */
    public function test_turning_a_staff_hint_off_removes_it_and_only_it_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "A1" is hidden from students');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has an extension on "A1" to "in 2 days"');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'hint_extensions', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should not have a "extensions" hint');
        $this->step('And "A1" should have a "hidden" hint');
    }

    /**
     * Turning the Check settings flag off leaves the convenor's checklist line
     */
    public function test_turning_the_check_settings_flag_off_leaves_the_convenor_s_checklist_line(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'hint_setup', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should not have a "setup" hint');
        $this->step('And I should see "1 tagged activity needs its settings checked" in the "My assessments" block');
    }

    /**
     * Turning the late clock off keeps the late mark on the activity icon
     */
    public function test_turning_the_late_clock_off_keeps_the_late_mark_on_the_activity_icon(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" submitted "A1" "2 days ago"');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'hint_late', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should not have a "late" hint');
        $this->step('And the activity icon of "A1" should have the "late" modifier');
    }

    /**
     * Turning the no online submission hint off removes it for students and staff
     */
    public function test_turning_the_no_online_submission_hint_off_removes_it_for_students_and_staff(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "P1" in "M1" with no online submission is due "tomorrow"');
        $this->step('And the following config values are set as admin:', [
            ['config' => 'hint_nosubmission', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "P1" should not have a "nosubmission" hint');
        $this->step('And I log in as "convenor1" and view the "Convenor view"');
        $this->step('And "P1" should not have a "nosubmission" hint');
    }

    /**
     * A new site doesn't show marking deadlines to students
     */
    public function test_a_new_site_doesn_t_show_marking_deadlines_to_students(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should not show "Feedback expected"');
        $this->step('And "A1" should not have a "feedbackcontact" hint');
    }

    /**
     * "As set" shows the marking deadline, and the question once it has passed
     */
    public function test_as_set_shows_the_marking_deadline_and_the_question_once_it_has_passed(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Feedback expected" with the date "2 days ago"');
        $this->step('And "A1" should have a "feedbackcontact" hint');
    }

    /**
     * "With a delay" adds the delay, and the question waits for the delayed date
     */
    public function test_with_a_delay_adds_the_delay_and_the_question_waits_for_the_delayed_date(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'feedbackdatemode', 'value' => 'delayed', 'plugin' => 'block_le_dashboard'],
            ['config' => 'feedbackdatedelay', 'value' => '7', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Feedback expected" with the date "in 5 days"');
        $this->step('And "A1" should not have a "feedbackcontact" hint');
    }

    /**
     * The setting covers coursework's marking deadline too
     */
    public function test_the_setting_covers_coursework_s_marking_deadline_too(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'feedbackdatemode', 'value' => 'none', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "coursework" "C1" in "M1" with a marking deadline "2 days ago" was due "10 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should not show "Feedback expected"');
        $this->step('And "C1" should not have a "feedbackcontact" hint');
    }

    /**
     * Staff see the internal marking deadline whatever students are shown
     */
    public function test_staff_see_the_internal_marking_deadline_whatever_students_are_shown(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'feedbackdatemode', 'value' => 'none', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" with a marking deadline "in 2 days" was due "10 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should show "Marking deadline"');
    }

    /**
     * The default marking period gives students a date where none was set
     */
    public function test_the_default_marking_period_gives_students_a_date_where_none_was_set(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'feedbackdatemode', 'value' => 'delayed', 'plugin' => 'block_le_dashboard'],
            ['config' => 'feedbackdatedelay', 'value' => '7', 'plugin' => 'block_le_dashboard'],
            ['config' => 'defaultmarkingdays', 'value' => '12', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" was due "10 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Feedback expected" with the date "in 9 days"');
    }

    /**
     * On a new site the late clock says how late, with no penalty rule
     */
    public function test_on_a_new_site_the_late_clock_says_how_late_with_no_penalty_rule(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" submitted "A1" "2 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "late" hint of "A1" should be titled "Submitted 1 day late."');
        $this->step('And I should not see "Late penalties may apply" in the "My assessments" block');
        $this->step('And I should not see "10%" in the "My assessments" block');
    }

    /**
     * A site that sets its own late penalties text gets the penalty line back
     */
    public function test_a_site_that_sets_its_own_late_penalties_text_gets_the_penalty_line_back(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            [
                'config' => 'guidance_latepenalty',
                'value' => '<p>Our late penalty policy.</p>',
                'plugin' => 'block_le_dashboard',
            ],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" submitted "A1" "2 days ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "late" hint of "A1" should be titled "Submitted 1 day late. Late penalties may apply."');
    }

    /**
     * An upgraded site that never saved its settings keeps today's text and hints
     */
    public function test_an_upgraded_site_that_never_saved_its_settings_keeps_today_s_text_and_hints(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the My assessments guidance settings were never saved before the beta27 upgrade');
        $this->step('When the site was upgraded from before the hint switches existed');
        $this->step('Then the "guidance_latepenalty" setting should contain "Work up to 10 minutes late may have 10% '
            . 'taken off its mark."');
        $this->step('And the "guidance_finalised" setting should contain "contact your Info Point"');
        $this->step('And the "feedbackdatemode" setting should be "asset"');
        $this->step('And the "hint_plagiarism" setting should be "1"');
        $this->step('And the "genericnames" setting should be "1"');
    }

    /**
     * A convenor allowed to see hidden emails gets every marker's link, as on their profile
     */
    public function test_a_convenor_allowed_to_see_hidden_emails_gets_every_marker_s_link_as_on_their_profile(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" was due "10 days ago"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And "marker2" marks the submission of "student2" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then the marker "Max Hidden" of "A1" should link to their email');
    }

    /**
     * Turning marker email links off shows every marker as a plain name
     */
    public function test_turning_marker_email_links_off_shows_every_marker_as_a_plain_name(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'hint_markeremail', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" was due "10 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then the marker "Mo Marker" of "A1" should not link to their email');
    }

    /**
     * Work within the late tolerance is not late
     *
     * Example: when = 17 minutes ago, have = not have
     */
    public function test_work_within_the_late_tolerance_is_not_late_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'latetolerance', 'value' => '5', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" was due "20 minutes ago"');
        $this->step('And "student1" submitted "A1" "17 minutes ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should not have a "late" hint');
        $this->step('And the activity icon of "A1" should not have the "late" modifier');
    }

    /**
     * Work within the late tolerance is not late
     *
     * Example: when = 10 minutes ago, have = have
     */
    public function test_work_within_the_late_tolerance_is_not_late_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student', 'maildisplay' => '1'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker', 'maildisplay' => '1'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Hidden', 'maildisplay' => '0'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor', 'maildisplay' => '1'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'latetolerance', 'value' => '5', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" was due "20 minutes ago"');
        $this->step('And "student1" submitted "A1" "10 minutes ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should have a "late" hint');
        $this->step('And the activity icon of "A1" should have the "late" modifier');
    }
}
