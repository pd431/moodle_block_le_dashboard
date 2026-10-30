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
 * Markers see the marking that is theirs, whatever the activity plugin.
 *
 * From tests/behat/staff-scope.feature: each test is one of its scenarios, run by view_testcase one sentence at a time;
 * the sentences that read the page are checked against the rendered dashboard. Every class is covered: a scenario
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
final class staff_scope_test extends view_testcase {
    /**
     * An activity type without allocation shows all its marking to every marker in the module
     */
    public function test_an_activity_type_without_allocation_shows_all_its_marking_to_every_marker_in_the_module(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"');
        $this->step('And "student1" and "student2" have finished "Q1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "Q1" should appear in the "Marking in progress" section with "2" to mark');
    }

    /**
     * A group-submitted peerwork shows one item to mark per group
     */
    public function test_a_group_submitted_peerwork_shows_one_item_to_mark_per_group(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And a summative "peerwork" "P1" in "M1" was due "3 days ago"');
        $this->step('And "student1" has submitted "P1" for "Group A"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "P1" should appear in the "Marking in progress" section with "1" to mark');
    }

    /**
     * Only the attempt that counts for the grade needs marking
     */
    public function test_only_the_attempt_that_counts_for_the_grade_needs_marking(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "quiz" "Q1" in "M1" with an essay question and grading method "Last attempt" was '
            . 'due "3 days ago"');
        $this->step('And "student1" has finished 2 attempts at "Q1"');
        $this->step('And the first attempt of "student1" at "Q1" still needs grading');
        $this->step('And the last attempt of "student1" at "Q1" has been graded');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "Q1" should not appear in the "Marking in progress" section');
    }

    /**
     * A resubmission under marking workflow comes back to the marker
     */
    public function test_a_resubmission_under_marking_workflow_comes_back_to_the_marker(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with marking workflow and reopened attempts was due "5 days '
            . 'ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the submission of "student1" for "A1" is reopened');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
    }

    /**
     * A convenor whose marking is all allocated sees no unallocated hint
     */
    public function test_a_convenor_whose_marking_is_all_allocated_sees_no_unallocated_hint(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the following "users" exist:', [
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "convenor"');
        $this->step('When I log in as "convenor" and view the "My marking"');
        $this->step('Then "A1" should not have an unallocated hint');
    }

    /**
     * Work that arrives after I finished marking comes back with a hint
     */
    public function test_work_that_arrives_after_i_finished_marking_comes_back_with_a_hint(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student2" has an extension on "A1" to "in 2 minutes"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And 3 minutes pass');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And "A1" should have a returned work hint');
    }

    /**
     * Marking that has simply not started has no returned work hint
     */
    public function test_marking_that_has_simply_not_started_has_no_returned_work_hint(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And "A1" should not have a returned work hint');
    }

    /**
     * Without allocation, a marker who has given their mark is done with that submission
     */
    public function test_without_allocation_a_marker_who_has_given_their_mark_is_done_with_that_submission(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with 2 markers was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Completed marking" section');
        $this->step('When I log in as "marker2" and view my dashboard');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
    }

    /**
     * With allocation, agreement belongs to the first allocated marker
     */
    public function test_with_allocation_agreement_belongs_to_the_first_allocated_marker(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" with 2 markers, manual agreement and marker allocation was '
            . 'due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1" and "marker2"');
        $this->step('And both markers have given feedback on the submission of "student1" for "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And "A1" should show an "Agreed" progress bar with "0" of "1"');
        $this->step('When I log in as "marker2" and view my dashboard');
        $this->step('Then "A1" should appear in the "Completed marking" section');
    }

    /**
     * An early submission isn't marking until the deadline passes
     */
    public function test_an_early_submission_isn_t_marking_until_the_deadline_passes(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "in 3 days"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should not appear in the "Marking in progress" section');
        $this->step('And "A1" should not appear in the "Completed marking" section');
    }

    /**
     * An extension stops counting once its deadline and the grace period have passed
     *
     * Example: extension = 20 hours ago, have = have
     */
    public function test_an_extension_stops_counting_once_its_deadline_and_the_grace_period_have_passed_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has an extension on "A1" to "20 hours ago"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should have an "extension" hint');
    }

    /**
     * An extension stops counting once its deadline and the grace period have passed
     *
     * Example: extension = 30 hours ago, have = not have
     */
    public function test_an_extension_stops_counting_once_its_deadline_and_the_grace_period_have_passed_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has an extension on "A1" to "30 hours ago"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should not have an "extension" hint');
    }

    /**
     * With only extension work to come and none of mine marked, the assessment isn't listed yet
     */
    public function test_with_only_extension_work_to_come_and_none_of_mine_marked_the_assessment_isn_t_listed_yet(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "2 days ago"');
        $this->step('And "student2" has an extension on "A1" to "in 5 days"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then "A1" should not appear in the "Marking in progress" section');
        $this->step('And "A1" should not appear in the "Completed marking" section');
    }

    /**
     * Extensions count only for students whose marking is mine
     */
    public function test_extensions_count_only_for_students_whose_marking_is_mine(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'editingteacher'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given the following "users" exist:', [
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
            ['username' => 'student3', 'firstname' => 'Stan', 'lastname' => 'Student'],
            ['username' => 'student4', 'firstname' => 'Stella', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student4', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"');
        $this->step('And "student1" has an extension on "A1" to "tomorrow"');
        $this->step('And "student2" has an extension on "A1" to "tomorrow"');
        $this->step('And "student3" and "student4" have submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student3" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is not allocated');
        $this->step('And the submission of "student4" for "A1" is not allocated');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then the "extension" flag of "A1" should be labelled "1 student has an extension"');
        $this->step('When I log in as "convenor" and view the "My marking"');
        $this->step('Then the "extension" flag of "A1" should be labelled "1 student has an extension"');
        $this->step('When I log in as "marker2" and view my dashboard');
        $this->step('Then I should not see "has an extension" in the "My assessments" block');
    }
}
