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
 * Convenors oversee all marking in their modules, with bars only where the activity supports them (assign mirror).
 *
 * From tests/behat/convenor-scope-assign.feature: each test is one of its scenarios, run by view_testcase one sentence
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
final class convenor_scope_assign_test extends view_testcase {
    /**
     * Convenor sees marking by every marker, including their own
     */
    public function test_convenor_sees_marking_by_every_marker_including_their_own(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "A1" is allocated to "convenor"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show a "Marked" progress bar with "0" of "2"');
        $this->step('And "A1" should list markers "Mo Marker" and "Cat Convenor"');
    }

    /**
     * Unallocated bar appears only for activity types with allocation
     */
    public function test_unallocated_bar_appears_only_for_activity_types_with_allocation(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" with marker allocation was due "3 days ago"');
        $this->step('And a summative "quiz" "Q1" in "M1" with an essay question was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is not allocated');
        $this->step('And "student1" has finished "Q1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show an "Unallocated" progress bar with "1"');
        $this->step('And "Q1" should not show an "Unallocated" progress bar');
    }

    /**
     * Submission totals count only students who can access the assessment
     */
    public function test_submission_totals_count_only_students_who_can_access_the_assessment(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "A1" is restricted to group "Group A"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show a "Submitted" progress bar with "1" of "2"');
    }

    /**
     * An assessment hidden from students is still overseen, and flagged
     */
    public function test_an_assessment_hidden_from_students_is_still_overseen_and_flagged(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "A1" is hidden from students');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then I should see "A1" in the "My assessments" block');
        $this->step('And "A1" should have a "hidden" hint');
    }

    /**
     * A marker who is not a convenor cannot open the convenor view
     */
    public function test_a_marker_who_is_not_a_convenor_cannot_open_the_convenor_view(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('When I log in as "marker1" and view my dashboard');
        $this->step('Then I should not be able to open the "Convenor view"');
    }

    /**
     * Marking bars appear only once something is submitted
     */
    public function test_marking_bars_appear_only_once_something_is_submitted(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "3 days ago"');
        $this->step('And "student2" has an extension on "A1" to "tomorrow"');
        $this->step('And a summative "assign" "A2" in "M1" was due "3 days ago"');
        $this->step('And "student1" has submitted "A2"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show progress bars "Submitted"');
        $this->step('And "A2" should show progress bars "Submitted, Marked, Released"');
    }

    /**
     * Before the deadline the convenor sees submissions only
     */
    public function test_before_the_deadline_the_convenor_sees_submissions_only(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "Upcoming" section');
        $this->step('And "A1" should show progress bars "Submitted"');
    }

    /**
     * Before the deadline an allocated assessment also shows what is unallocated
     */
    public function test_before_the_deadline_an_allocated_assessment_also_shows_what_is_unallocated(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" with marker allocation is due "tomorrow"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show progress bars "Submitted, Unallocated"');
        $this->step('And "A1" should show an "Unallocated" progress bar with "1" of "2"');
        $this->step('And I should not see "Show markers" in the "My assessments" block');
    }

    /**
     * Once the deadline passes the marking bars appear
     */
    public function test_once_the_deadline_passes_the_marking_bars_appear(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "yesterday"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "In progress" section');
        $this->step('And "A1" should show progress bars "Submitted, Marked, Released"');
    }

    /**
     * A resubmission under marking workflow comes back to the convenor, not released
     */
    public function test_a_resubmission_under_marking_workflow_comes_back_to_the_convenor_not_released(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" with marking workflow and reopened attempts was due "5 days '
            . 'ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And the submission of "student1" for "A1" is reopened');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "In progress" section');
        $this->step('And "A1" should show a "Released" progress bar with "0" of "1"');
    }

    /**
     * Returned work moves the assessment back to In progress with a hint for the convenor
     */
    public function test_returned_work_moves_the_assessment_back_to_in_progress_with_a_hint_for_the_convenor(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student2" has an extension on "A1" to "in 2 minutes"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And the grade of "student1" for "A1" is released');
        $this->step('And "student2" has submitted "A1"');
        $this->step('And 3 minutes pass');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "In progress" section');
        $this->step('And "A1" should have a returned work hint');
    }

    /**
     * Unmarked work past the marking deadline is called out for the convenor
     */
    public function test_unmarked_work_past_the_marking_deadline_is_called_out_for_the_convenor(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" with a marking deadline "yesterday" was due "10 days ago"');
        $this->step('And "student1" and "student2" have submitted "A1"');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show "1 unmarked after the marking deadline"');
    }

    /**
     * Before the marking deadline there is no call-out
     */
    public function test_before_the_marking_deadline_there_is_no_call_out(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" with a marking deadline "in 5 days" was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should not show "unmarked after the marking deadline"');
    }

    /**
     * Staff rows name which deadline a date is
     */
    public function test_staff_rows_name_which_deadline_a_date_is(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" with a marking deadline "in 10 days" was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show "Submission deadline"');
        $this->step('And "A1" should show "Marking deadline"');
        $this->step('And "A1" should not show "Due"');
    }

    /**
     * The convenor sees extensions still to come on the assessment
     */
    public function test_the_convenor_sees_extensions_still_to_come_on_the_assessment(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1', 'startdate' => '##2026-09-28##'],
            ['fullname' => 'Module 2', 'shortname' => 'M2', 'startdate' => '##2025-09-29##'],
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
            ['user' => 'convenor', 'course' => 'M2', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the following "groups" exist:', [
            ['name' => 'Group A', 'course' => 'M1', 'idnumber' => 'GA'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'GA'],
            ['user' => 'student2', 'group' => 'GA'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('Given a summative "assign" "A1" in "M1" was due "5 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And "student2" has an extension on "A1" to "in 3 days"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should appear in the "In progress" section');
        $this->step('And the "extension" flag of "A1" should be labelled "1 student has an extension"');
    }
}
