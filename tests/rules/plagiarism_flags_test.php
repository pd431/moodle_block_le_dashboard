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
 * Staff see how many submissions are flagged for plagiarism.
 *
 * From tests/behat/plagiarism-flags.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class plagiarism_flags_test extends view_testcase {
    /**
     * Each flag status counts as Pierre described
     *
     * Example: status = 0, meaning = under investigation, tomark = 2, flag = have, units = 2
     */
    public function test_each_flag_status_counts_as_pierre_described_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "0"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
        $this->step('And "C1" should have a plagiarism hint');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Marked" progress bar with "0" of "2"');
    }

    /**
     * Each flag status counts as Pierre described
     *
     * Example: status = 1, meaning = cleared, tomark = 2, flag = not have, units = 2
     */
    public function test_each_flag_status_counts_as_pierre_described_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "1"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
        $this->step('And "C1" should not have a plagiarism hint');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Marked" progress bar with "0" of "2"');
    }

    /**
     * Each flag status counts as Pierre described
     *
     * Example: status = 2, meaning = cleared, tomark = 2, flag = not have, units = 2
     */
    public function test_each_flag_status_counts_as_pierre_described_3(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "2"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
        $this->step('And "C1" should not have a plagiarism hint');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Marked" progress bar with "0" of "2"');
    }

    /**
     * Each flag status counts as Pierre described
     *
     * Example: status = 3, meaning = withheld, tomark = 1, flag = have, units = 1
     */
    public function test_each_flag_status_counts_as_pierre_described_4(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "3"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And "C1" should have a plagiarism hint');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Marked" progress bar with "0" of "1"');
    }

    /**
     * Each flag status counts as Pierre described
     *
     * Example: status = 7, meaning = unknown, as 0, tomark = 2, flag = have, units = 2
     */
    public function test_each_flag_status_counts_as_pierre_described_5(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "7"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
        $this->step('And "C1" should have a plagiarism hint');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Marked" progress bar with "0" of "2"');
    }

    /**
     * Withheld work leaves every marking count but the flag, and the student's own row is unchanged
     */
    public function test_withheld_work_leaves_every_marking_count_but_the_flag_and_the_student_s_own_row_is_unchanged(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "3"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Submitted" progress bar with "2" of "2"');
        $this->step('And "C1" should show a "Marked" progress bar with "0" of "1"');
        $this->step('And "C1" should show "1 flagged"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "C1" should show the status "In marking"');
        $this->step('And "C1" should not have a plagiarism hint');
    }

    /**
     * Withheld work comes back to the counts once the flag is cleared
     */
    public function test_withheld_work_comes_back_to_the_counts_once_the_flag_is_cleared(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "3"');
        $this->step('And the My assessments index is up to date');
        $this->step('When the submission of "student1" for "C1" is flagged for plagiarism with status "2"');
        $this->step('And I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "2" to mark');
        $this->step('And "C1" should not have a plagiarism hint');
    }

    /**
     * A marker whose only work is withheld keeps the assessment under Completed marking, with the flag
     */
    public function test_a_marker_whose_only_work_is_withheld_keeps_the_assessment_under_completed_marking_with_the_flag(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cara', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('Given a summative "coursework" "C1" in "M1" with marker allocation was due "2 days ago"');
        $this->step('And "student1" and "student2" have submitted "C1"');
        $this->step('And the submission of "student1" for "C1" is allocated to "marker1"');
        $this->step('And the submission of "student2" for "C1" is allocated to "marker2"');
        $this->step('And the submission of "student1" for "C1" is flagged for plagiarism with status "3"');
        $this->step('When I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Completed marking" section');
        $this->step('And "C1" should show "1 flagged"');
        $this->step('And "C1" should not show a "Marked" progress bar');
        $this->step('When the submission of "student1" for "C1" is flagged for plagiarism with status "2"');
        $this->step('And I log in as "marker1" and view the "My marking"');
        $this->step('Then "C1" should appear in the "Marking in progress" section with "1" to mark');
    }
}
