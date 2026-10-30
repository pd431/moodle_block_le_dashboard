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
 * Students an access restriction shuts out drop out of an assessment's numbers.
 *
 * From tests/behat/restricted-students.feature: each test is one of its scenarios, run by view_testcase one sentence at
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
final class restricted_students_test extends view_testcase {
    /**
     * Work handed in on the original before the move stops counting
     */
    public function test_work_handed_in_on_the_original_before_the_move_stops_counting(): void {
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
        $this->step('And the following "groups" exist:', [
            ['name' => 'NC', 'course' => 'M1', 'idnumber' => 'NC'],
            ['name' => 'NC extension', 'course' => 'M1', 'idnumber' => 'NCX'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'NC'],
            ['user' => 'student2', 'group' => 'NC'],
            ['user' => 'student3', 'group' => 'NC'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "NC Individual" in "M1" was due "2 days ago"');
        $this->step('And "NC Individual" is restricted to group "NC" and not group "NC extension"');
        $this->step('And a summative "assign" "Extension copy" in "M1" is due "in 14 days"');
        $this->step('And "Extension copy" is restricted to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('Given "student1" submitted "NC Individual" "3 days ago"');
        $this->step('And "student2" submitted "NC Individual" "3 days ago"');
        $this->step('And "student3" submitted "NC Individual" "3 days ago"');
        $this->step('And "marker1" marks the submission of "student1" for "NC Individual"');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "marker1" and view my dashboard');
        $this->step('And "NC Individual" shows "2" to mark');
        $this->step('When "student2" is added to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('And I view my dashboard again');
        $this->step('Then "NC Individual" should show "1" to mark');
        $this->step('When I log in as "convenor" and view my dashboard');
        $this->step('Then "NC Individual" should show a "Submitted" progress bar with "2" of "2"');
        $this->step('And "NC Individual" should show a "Marked" progress bar with "1" of "2"');
    }

    /**
     * An unmarked student moved out doesn't hold the original in In progress
     */
    public function test_an_unmarked_student_moved_out_doesn_t_hold_the_original_in_in_progress(): void {
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
        $this->step('And the following "groups" exist:', [
            ['name' => 'NC', 'course' => 'M1', 'idnumber' => 'NC'],
            ['name' => 'NC extension', 'course' => 'M1', 'idnumber' => 'NCX'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'NC'],
            ['user' => 'student2', 'group' => 'NC'],
            ['user' => 'student3', 'group' => 'NC'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "NC Individual" in "M1" was due "2 days ago"');
        $this->step('And "NC Individual" is restricted to group "NC" and not group "NC extension"');
        $this->step('And a summative "assign" "Extension copy" in "M1" is due "in 14 days"');
        $this->step('And "Extension copy" is restricted to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('Given "student1" submitted "NC Individual" "3 days ago"');
        $this->step('And "student2" submitted "NC Individual" "3 days ago"');
        $this->step('And "student3" submitted "NC Individual" "3 days ago"');
        $this->step('And "marker1" marks the submission of "student1" for "NC Individual"');
        $this->step('And "marker1" marks the submission of "student3" for "NC Individual"');
        $this->step('And the grade of "student1" for "NC Individual" is released');
        $this->step('And the grade of "student3" for "NC Individual" is released');
        $this->step('When "student2" is added to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "convenor" and view my dashboard');
        $this->step('Then "NC Individual" should appear in the "Past" section');
        $this->step('And "NC Individual" should show the grading status "Released to students"');
    }

    /**
     * Taking the student out of the extension group brings their work back
     */
    public function test_taking_the_student_out_of_the_extension_group_brings_their_work_back(): void {
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
        $this->step('And the following "groups" exist:', [
            ['name' => 'NC', 'course' => 'M1', 'idnumber' => 'NC'],
            ['name' => 'NC extension', 'course' => 'M1', 'idnumber' => 'NCX'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'NC'],
            ['user' => 'student2', 'group' => 'NC'],
            ['user' => 'student3', 'group' => 'NC'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "NC Individual" in "M1" was due "2 days ago"');
        $this->step('And "NC Individual" is restricted to group "NC" and not group "NC extension"');
        $this->step('And a summative "assign" "Extension copy" in "M1" is due "in 14 days"');
        $this->step('And "Extension copy" is restricted to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('Given "student2" submitted "NC Individual" "3 days ago"');
        $this->step('And "student2" is added to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('When "student2" is removed from group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "convenor" and view my dashboard');
        $this->step('Then "NC Individual" should show a "Submitted" progress bar with "1" of "3"');
        $this->step('When I log in as "student2" and view my dashboard');
        $this->step('Then "NC Individual" should appear in the "Past" section');
    }

    /**
     * A team counts while any member can still access the assessment
     */
    public function test_a_team_counts_while_any_member_can_still_access_the_assessment(): void {
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
        $this->step('And the following "groups" exist:', [
            ['name' => 'NC', 'course' => 'M1', 'idnumber' => 'NC'],
            ['name' => 'NC extension', 'course' => 'M1', 'idnumber' => 'NCX'],
        ]);
        $this->step('And the following "group members" exist:', [
            ['user' => 'student1', 'group' => 'NC'],
            ['user' => 'student2', 'group' => 'NC'],
            ['user' => 'student3', 'group' => 'NC'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "NC Individual" in "M1" was due "2 days ago"');
        $this->step('And "NC Individual" is restricted to group "NC" and not group "NC extension"');
        $this->step('And a summative "assign" "Extension copy" in "M1" is due "in 14 days"');
        $this->step('And "Extension copy" is restricted to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('Given a summative "assign" "Team work" in "M1" with team submission in grouping "Teams" was due "2 '
            . 'days ago"');
        $this->step('And "Team work" is restricted to group "NC" and not group "NC extension"');
        $this->step('And "student1" and "student2" are team "T1" of "Team work"');
        $this->step('And "student1" has submitted "Team work"');
        $this->step('When "student2" is added to group "NC extension"');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "marker1" and view my dashboard');
        $this->step('Then "Team work" should show "1" to mark');
    }
}
