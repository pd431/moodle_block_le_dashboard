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
 * Every row reads in the same order, in every view.
 *
 * From tests/behat/order-of-information.feature: each test is one of its scenarios, run by view_testcase one sentence
 * at a time; the sentences that read the page are checked against the rendered dashboard. Every class is covered: a
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
final class order_of_information_test extends view_testcase {
    /**
     * Released feedback comes after the deadline it follows
     */
    public function test_released_feedback_comes_after_the_deadline_it_follows(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the dates of "A1" should be "Due, Feedback received" in that order');
    }

    /**
     * Expected feedback comes after the deadline, as before
     */
    public function test_expected_feedback_comes_after_the_deadline_as_before(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'feedbackdatemode', 'value' => 'asset', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the dates of "A1" should be "Due, Feedback expected" in that order');
    }

    /**
     * An activity not open yet still reads Opens, then Due
     */
    public function test_an_activity_not_open_yet_still_reads_opens_then_due(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" opens "in 3 days" and is due "in 10 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the dates of "A1" should be "Opens, Due" in that order');
    }

    /**
     * Undated work with released feedback shows only the feedback date
     */
    public function test_undated_work_with_released_feedback_shows_only_the_feedback_date(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the dates of "A1" should be "Feedback received" in that order');
    }

    /**
     * Students read the type before the activity, as before
     */
    public function test_students_read_the_type_before_the_activity_as_before(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" is due "in 10 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Summative assignment"');
    }

    /**
     * Staff and convenors read the type before the activity too
     *
     * Example: user = marker1, view = My marking
     */
    public function test_staff_and_convenors_read_the_type_before_the_activity_too_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "A1" should show "Summative · Assignment"');
        $this->step('And "A1" should not show "Assignment · Summative"');
    }

    /**
     * Staff and convenors read the type before the activity too
     *
     * Example: user = convenor, view = Convenor view
     */
    public function test_staff_and_convenors_read_the_type_before_the_activity_too_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show "Summative · Assignment"');
        $this->step('And "A1" should not show "Assignment · Summative"');
    }

    /**
     * Flags come in the same order on My marking and the Convenor view
     *
     * Example: user = marker1, view = My marking, hints = hidden, generic name, extensions
     */
    public function test_flags_come_in_the_same_order_on_my_marking_and_the_convenor_view_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'genericnames', 'value' => '1', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "Essay 1" in "M1" was due "3 days ago"');
        $this->step('And "Essay 1" is hidden from students');
        $this->step('And "student1" has submitted "Essay 1"');
        $this->step('And "student2" has an extension on "Essay 1" to "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then the hints of "Essay 1" should be "hidden, generic name, extensions" in that order');
    }

    /**
     * Flags come in the same order on My marking and the Convenor view
     *
     * Example: user = convenor, view = Convenor view, hints = hidden, generic name, extensions
     */
    public function test_flags_come_in_the_same_order_on_my_marking_and_the_convenor_view_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'genericnames', 'value' => '1', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "Essay 1" in "M1" was due "3 days ago"');
        $this->step('And "Essay 1" is hidden from students');
        $this->step('And "student1" has submitted "Essay 1"');
        $this->step('And "student2" has an extension on "Essay 1" to "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then the hints of "Essay 1" should be "hidden, generic name, extensions" in that order');
    }

    /**
     * A student's overdue work comes oldest first, then upcoming work soonest first
     */
    public function test_a_student_s_overdue_work_comes_oldest_first_then_upcoming_work_soonest_first(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" was due "1 day ago"');
        $this->step('And a summative "assign" "A2" in "M1" was due "2 days ago"');
        $this->step('And a summative "assign" "A3" in "M1" is due "in 7 days"');
        $this->step('And a summative "assign" "A4" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "Overdue" section should list "A2, A1" in that order');
        $this->step('And the "Upcoming" section should list "A4, A3" in that order');
    }

    /**
     * A student's past work comes most recent first
     */
    public function test_a_student_s_past_work_comes_most_recent_first(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" was due "14 days ago"');
        $this->step('And a summative "assign" "A2" in "M1" was due "7 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student1" has submitted "A2"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A2"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the grade of "student1" for "A2" is released');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "Past" section should list "A2, A1" in that order');
    }

    /**
     * Completed marking shows the latest marking deadline first
     */
    public function test_completed_marking_shows_the_latest_marking_deadline_first(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" with a marking deadline "3 days ago" was due "20 days ago"');
        $this->step('And a summative "assign" "A2" in "M1" with a marking deadline "yesterday" was due "10 days ago"');
        $this->step('And a summative "assign" "A3" in "M1" with a marking deadline "10 days ago" was due "30 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student1" has submitted "A2"');
        $this->step('And "student1" has submitted "A3"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A2"');
        $this->step('And "marker1" marks the submission of "student1" for "A3"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then the "Completed marking" section should list "A2, A1, A3" in that order');
    }

    /**
     * Convenor In progress sorts by marking deadline, else by deadline, as My marking does
     */
    public function test_convenor_in_progress_sorts_by_marking_deadline_else_by_deadline_as_my_marking_does(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'defaultmarkingdays', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" with a marking deadline "in 10 days" was due "5 days ago"');
        $this->step('And a summative "assign" "A2" in "M1" was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student1" has submitted "A2"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then the "In progress" section should list "A2, A1" in that order');
    }

    /**
     * Convenor Past shows the latest marking deadline first, as Completed marking does
     */
    public function test_convenor_past_shows_the_latest_marking_deadline_first_as_completed_marking_does(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "A1" in "M1" with a marking deadline "2 days ago" was due "20 days ago"');
        $this->step('And a summative "assign" "A2" in "M1" with a marking deadline "5 days ago" was due "10 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student1" has submitted "A2"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A2"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the grade of "student1" for "A2" is released');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then the "Past" section should list "A1, A2" in that order');
    }

    /**
     * Rows with the same date come by name in every view
     */
    public function test_rows_with_the_same_date_come_by_name_in_every_view(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Stu', 'lastname' => 'Student'],
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
        $this->step('Given a summative "assign" "Beta" in "M1" is due "in 3 days"');
        $this->step('And a summative "assign" "Alpha" in "M1" is due "in 3 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then the "Upcoming" section should list "Alpha, Beta" in that order');
    }
}
