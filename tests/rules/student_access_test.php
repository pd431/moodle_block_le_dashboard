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
 * Students see only assessments they can actually access.
 *
 * From tests/behat/student-access.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class student_access_test extends view_testcase {
    /**
     * Assessments in a hidden course do not appear
     */
    public function test_assessments_in_a_hidden_course_do_not_appear(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "quiz" "A1" in "M2" is due "tomorrow"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then I should not see "A1" in the "My assessments" block');
    }

    /**
     * An assessment restricted by activity completion I have not met does not appear
     */
    public function test_an_assessment_restricted_by_activity_completion_i_have_not_met_does_not_appear(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "quiz" "A1" in "M1" is due "tomorrow"');
        $this->step('And "A1" is only available once "Reading" in "M1" is complete');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then I should not see "A1" in the "My assessments" block');
    }

    /**
     * An enrolment that has ended hides the module's assessments
     */
    public function test_an_enrolment_that_has_ended_hides_the_module_s_assessments(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "quiz" "A1" in "M1" is due "tomorrow"');
        $this->step('And the enrolment of "student1" in "M1" ended "yesterday"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then I should not see "A1" in the "My assessments" block');
    }

    /**
     * A suspended enrolment hides the module's assessments
     */
    public function test_a_suspended_enrolment_hides_the_module_s_assessments(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "quiz" "A1" in "M1" is due "tomorrow"');
        $this->step('And the enrolment of "student1" in "M1" is suspended');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then I should not see "A1" in the "My assessments" block');
    }

    /**
     * Summary counts are unaffected by a customised section title
     */
    public function test_summary_counts_are_unaffected_by_a_customised_section_title(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "yesterday"');
        $this->step('And the language string for the "Overdue" section title is customised to "Needs attention"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Needs attention" section');
        $this->step('And the "Overdue" summary count should be "1"');
    }

    /**
     * A feedback date still to come has no hint
     */
    public function test_a_feedback_date_still_to_come_has_no_hint(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with a marking deadline "in 5 days" was due "3 days ago"');
        $this->step('And "student1" submitted "A1" "4 days ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Feedback expected"');
        $this->step('And "A1" should not have a feedback contact hint');
    }

    /**
     * A student row names the module in full and the kind of activity
     */
    public function test_a_student_row_names_the_module_in_full_and_the_kind_of_activity(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And a formative "quiz" "Q1" in "M1" is due "tomorrow"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Module 1"');
        $this->step('And "A1" should show "Summative assignment"');
        $this->step('And "A1" should not show "M1"');
        $this->step('And "Q1" should show "Formative quiz"');
    }

    /**
     * A student who resubmits sees "In marking", not the previous grade
     */
    public function test_a_student_who_resubmits_sees_in_marking_not_the_previous_grade(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given the following "users" exist:', [
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" with marking workflow and reopened attempts was due "5 days '
            . 'ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the submission of "student1" for "A1" is reopened');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "In marking"');
        $this->step('And "A1" should not show "Feedback released"');
    }

    /**
     * Lateness is shown to the precision its size needs
     *
     * Example: due = 2 minutes ago, submitted = 90 seconds ago, text = Submitted less than a minute late
     */
    public function test_lateness_is_shown_to_the_precision_its_size_needs_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 minutes ago"');
        $this->step('And "student1" submitted "A1" "90 seconds ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "late" icon of "A1" should be labelled "Submitted less than a minute late. Late penalties '
            . 'may apply."');
    }

    /**
     * Lateness is shown to the precision its size needs
     *
     * Example: due = 1 hour ago, submitted = 48 minutes ago, text = Submitted 12 min late
     */
    public function test_lateness_is_shown_to_the_precision_its_size_needs_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "1 hour ago"');
        $this->step('And "student1" submitted "A1" "48 minutes ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "late" icon of "A1" should be labelled "Submitted 12 min late. Late penalties may apply."');
    }

    /**
     * Lateness is shown to the precision its size needs
     *
     * Example: due = 8 hours ago, submitted = 2 hours ago, text = Submitted 6 h late
     */
    public function test_lateness_is_shown_to_the_precision_its_size_needs_3(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "8 hours ago"');
        $this->step('And "student1" submitted "A1" "2 hours ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "late" icon of "A1" should be labelled "Submitted 6 h late. Late penalties may apply."');
    }

    /**
     * Lateness is shown to the precision its size needs
     *
     * Example: due = 3 days ago, submitted = 20 hours ago, text = Submitted 2 days late
     */
    public function test_lateness_is_shown_to_the_precision_its_size_needs_4(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" submitted "A1" "20 hours ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "late" icon of "A1" should be labelled "Submitted 2 days late. Late penalties may apply."');
    }

    /**
     * On-time work says nothing about earliness or the time it was submitted
     */
    public function test_on_time_work_says_nothing_about_earliness_or_the_time_it_was_submitted(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "yesterday"');
        $this->step('And "student1" submitted "A1" "3 days ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "In marking"');
        $this->step('And "A1" should not show "Submitted"');
        $this->step('And "A1" should not show "early"');
        $this->step('And "A1" should not show "late"');
    }

    /**
     * Late summative work says late penalties may apply, late formative work only says it was late
     */
    public function test_late_summative_work_says_late_penalties_may_apply_late_formative_work_only_says_it_was_late(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 days ago"');
        $this->step('And a formative "assign" "F1" in "M1" was due "2 days ago"');
        $this->step('And "student1" submitted "A1" "1 day ago"');
        $this->step('And "student1" submitted "F1" "1 day ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then the "late" icon of "A1" should be labelled "Submitted 1 day late. Late penalties may apply."');
        $this->step('And the "late" icon of "F1" should be labelled "Submitted 1 day late."');
    }

    /**
     * Work submitted before an extended deadline is not late
     */
    public function test_work_submitted_before_an_extended_deadline_is_not_late(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student1" has an extension on "A1" to "tomorrow"');
        $this->step('And "student1" submitted "A1" "1 day ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should not show "late"');
        $this->step('And "A1" should not have a late penalty hint');
    }

    /**
     * An overdue assessment with a cut-off says how long is left to submit
     */
    public function test_an_overdue_assessment_with_a_cut_off_says_how_long_is_left_to_submit(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" with a cut-off "Tue 13 October 2026 08:00 Europe/London" '
            . 'was due "Mon 12 October 2026 09:00 Europe/London"');
        $this->step('And it is now "Mon 12 October 2026 12:00" in "Europe/London"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Overdue" section');
        $this->step('And "A1" should show "You can still submit for 20 h"');
    }

    /**
     * An overdue assignment without a cut-off has no countdown
     */
    public function test_an_overdue_assignment_without_a_cut_off_has_no_countdown(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 hours ago"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Overdue" section');
        $this->step('And "A1" should not show "You can still submit"');
    }

    /**
     * A released row labels its feedback date without repeating "released"
     */
    public function test_a_released_row_labels_its_feedback_date_without_repeating_released(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'visible' => '1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'visible' => '0'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2', 'role' => 'student'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given the following "users" exist:', [
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show "Feedback released"');
        $this->step('And "A1" should not show "Released"');
        $this->step('And "A1" should show "Feedback received" with the date "today"');
    }
}
