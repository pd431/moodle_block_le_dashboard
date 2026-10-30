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
 * Staff are told when a tagged assessment is missing a setting.
 *
 * From tests/behat/setup-flags.feature: each test is one of its scenarios, run by view_testcase one sentence at a time;
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
final class setup_flags_test extends view_testcase {
    /**
     * A tagged assignment with no due date is flagged for its settings
     */
    public function test_a_tagged_assignment_with_no_due_date_is_flagged_for_its_settings(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should have a "setup" hint');
        $this->step('And the "setup" flag of "A1" should be labelled "Check settings"');
        $this->step('And "A1" should not have a "nodeadline" hint');
    }

    /**
     * A quiz whose marks are never shown is flagged
     */
    public function test_a_quiz_whose_marks_are_never_shown_is_flagged(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "quiz" "Q1" in "M1" with marks never shown is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Convenor view"');
        $this->step('Then "Q1" should have a "setup" hint');
    }

    /**
     * A formative quiz whose marks are never shown is not flagged
     */
    public function test_a_formative_quiz_whose_marks_are_never_shown_is_not_flagged(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a formative "quiz" "F1" in "M1" with marks never shown is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Convenor view"');
        $this->step('Then "F1" should not have a "setup" hint');
    }

    /**
     * An activity tagged both summative and formative is flagged
     */
    public function test_an_activity_tagged_both_summative_and_formative_is_flagged(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" tagged "formative" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should have a "setup" hint');
    }

    /**
     * A complete activity has no flag
     */
    public function test_a_complete_activity_has_no_flag(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor1" and view the "Module convenor view"');
        $this->step('Then "A1" should not have a "setup" hint');
    }

    /**
     * Students never see the flag
     */
    public function test_students_never_see_the_flag(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tim', 'lastname' => 'Teacher'],
            ['username' => 'convenor1', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor1', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given a summative "assign" "A1" in "M1" with no due date exists');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should not have a "setup" hint');
    }
}
