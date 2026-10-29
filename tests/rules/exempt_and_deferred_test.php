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
 * An exempt or deferred student owes nothing and leaves the counts.
 *
 * From tests/behat/exempt-and-deferred.feature: each test is one of its scenarios, run by view_testcase one sentence at
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
final class exempt_and_deferred_test extends view_testcase {
    /**
     * An exempt or deferred student is complete for staff and sees Exempt with no actions
     *
     * Example: type = permanent
     */
    public function test_an_exempt_or_deferred_student_is_complete_for_staff_and_sees_exempt_with_no_actions_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('Given "student3" has a "permanent" mitigation on "C1"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Submitted" progress bar with "2" of "2"');
        $this->step('And "C1" should show a "Marked" progress bar with "0" of "2"');
        $this->step('When I log in as "student3" and view my dashboard');
        $this->step('Then "C1" should appear in the "Past" section');
        $this->step('And "C1" should not appear in the "Overdue" section');
        $this->step('And "C1" should show the status "Exempt"');
        $this->step('And "C1" should not have a "Submit" button');
        $this->step('And "C1" should not have a "View" button');
    }

    /**
     * An exempt or deferred student is complete for staff and sees Exempt with no actions
     *
     * Example: type = temporary
     */
    public function test_an_exempt_or_deferred_student_is_complete_for_staff_and_sees_exempt_with_no_actions_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('Given "student3" has a "temporary" mitigation on "C1"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Submitted" progress bar with "2" of "2"');
        $this->step('And "C1" should show a "Marked" progress bar with "0" of "2"');
        $this->step('When I log in as "student3" and view my dashboard');
        $this->step('Then "C1" should appear in the "Past" section');
        $this->step('And "C1" should not appear in the "Overdue" section');
        $this->step('And "C1" should show the status "Exempt"');
        $this->step('And "C1" should not have a "Submit" button');
        $this->step('And "C1" should not have a "View" button');
    }

    /**
     * An exempt student keeps the date rules before the deadline
     */
    public function test_an_exempt_student_keeps_the_date_rules_before_the_deadline(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('Given a summative "coursework" "C2" in "M1" is due "in 3 days"');
        $this->step('And "student3" has a "permanent" mitigation on "C2"');
        $this->step('When I log in as "student3" and view my dashboard');
        $this->step('Then "C2" should appear in the "Upcoming" section');
        $this->step('And "C2" should show the status "Exempt"');
        $this->step('And "C2" should not have a "Submit" button');
    }

    /**
     * An exemption applied after submitting takes the submission out of the counts too
     */
    public function test_an_exemption_applied_after_submitting_takes_the_submission_out_of_the_counts_too(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('Given "student1" has a "permanent" mitigation on "C1"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Submitted" progress bar with "1" of "2"');
    }

    /**
     * Removing an exemption brings the student back into the counts
     */
    public function test_removing_an_exemption_brings_the_student_back_into_the_counts(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('Given "student1" has a "permanent" mitigation on "C1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When the "permanent" mitigation of "student1" on "C1" is removed');
        $this->step('And I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
    }

    /**
     * An exemption wins over an extension
     */
    public function test_an_exemption_wins_over_an_extension(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('Given "student3" self-certified "C1" to "in 4 days"');
        $this->step('And "student3" has a "permanent" mitigation on "C1"');
        $this->step('When I log in as "student3" and view my dashboard');
        $this->step('Then "C1" should show the status "Exempt"');
        $this->step('And "C1" should not have a "Submit" button');
    }

    /**
     * Staff hints still count extensions still to run, not exemptions
     */
    public function test_staff_hints_still_count_extensions_still_to_run_not_exemptions(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'student3', 'firstname' => 'Sid', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student3', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" has submitted "C1"');
        $this->step('And "student2" has submitted "C1"');
        $this->step('Given a summative "coursework" "C2" in "M1" is due "in 1 day"');
        $this->step('And "student1" self-certified "C2" to "in 4 days"');
        $this->step('And "student2" has a "permanent" mitigation on "C2"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C2" should show "1 student has an extension"');
    }
}
