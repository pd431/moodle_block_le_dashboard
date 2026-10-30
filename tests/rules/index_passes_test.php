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
 * The index stops at a time limit, carries on where it stopped, and shows its progress.
 *
 * From tests/behat/index-passes.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class index_passes_test extends view_testcase {
    /**
     * A run that reaches its time limit stops after the course it is on, and the next run carries on
     */
    public function test_a_run_that_reaches_its_time_limit_stops_after_the_course_it_is_on_and_the_next_run_carries_on(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
            ['fullname' => 'Module 3', 'shortname' => 'M3'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M3', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And a summative "assign" "A2" in "M2" is due "tomorrow"');
        $this->step('And a summative "assign" "A3" in "M3" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given every My assessments index run has a time limit of 1 course');
        $this->step('When the nightly check runs');
        $this->step('Then the last index run should have done "M1" only');
        $this->step('When the nightly check runs');
        $this->step('Then the last index run should have done "M2" only');
        $this->step('When the nightly check runs');
        $this->step('Then the last index run should have done "M3" only');
    }

    /**
     * A run that died part-way carries on from the last course it saved
     */
    public function test_a_run_that_died_part_way_carries_on_from_the_last_course_it_saved(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
            ['fullname' => 'Module 3', 'shortname' => 'M3'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M3', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And a summative "assign" "A2" in "M2" is due "tomorrow"');
        $this->step('And a summative "assign" "A3" in "M3" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given the nightly check died after "M1"');
        $this->step('When the nightly check runs');
        $this->step('Then the last index run should have done "M2" and "M3" only');
    }

    /**
     * The nightly check only works between 02:00 and 06:00
     */
    public function test_the_nightly_check_only_works_between_02_00_and_06_00(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
            ['fullname' => 'Module 3', 'shortname' => 'M3'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M3', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And a summative "assign" "A2" in "M2" is due "tomorrow"');
        $this->step('And a summative "assign" "A3" in "M3" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given every My assessments index run has a time limit of 1 course');
        $this->step('When the hourly index task runs at "14:00"');
        $this->step('Then the nightly check should have done no courses so far');
        $this->step('When the hourly index task runs at "02:00"');
        $this->step('And the hourly index task runs at "03:00"');
        $this->step('Then the nightly check should have done "M1" and "M2" so far');
        $this->step('When the hourly index task runs at "07:00"');
        $this->step('Then the nightly check should have done "M1" and "M2" so far');
        $this->step('When the hourly index task runs at "02:00"');
        $this->step('Then the nightly check should have done "M1", "M2" and "M3" so far');
    }

    /**
     * The command-line rebuild works with a first fill stuck in the queue
     */
    public function test_the_command_line_rebuild_works_with_a_first_fill_stuck_in_the_queue(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
            ['fullname' => 'Module 3', 'shortname' => 'M3'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M3', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And a summative "assign" "A2" in "M2" is due "tomorrow"');
        $this->step('And a summative "assign" "A3" in "M3" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given the My assessments index is rebuilt');
        $this->step('And ad hoc tasks don\'t run');
        $this->step('When I run the command-line rebuild');
        $this->step('Then it should finish with every course done');
        $this->step('And no first fill should be queued');
    }

    /**
     * Rebuild starts the first fill again from the first course
     */
    public function test_rebuild_starts_the_first_fill_again_from_the_first_course(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
            ['fullname' => 'Module 3', 'shortname' => 'M3'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M3', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And a summative "assign" "A2" in "M2" is due "tomorrow"');
        $this->step('And a summative "assign" "A3" in "M3" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given every My assessments index run has a time limit of 1 course');
        $this->step('And ad hoc tasks don\'t run');
        $this->step('And the My assessments index is rebuilt');
        $this->step('And the hourly index task runs at "10:00"');
        $this->step('When the My assessments index is rebuilt');
        $this->step('And the hourly index task runs at "11:00"');
        $this->step('Then the first fill should have done "M1" only');
    }
}
