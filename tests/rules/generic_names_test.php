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
 * Assessments with generic names get a hint for staff.
 *
 * From tests/behat/generic-names.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class generic_names_test extends view_testcase {
    /**
     * A name made only of generic words gets a hint for staff
     *
     * Example: name = Essay 1, have = have
     */
    public function test_a_name_made_only_of_generic_words_gets_a_hint_for_staff_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "Essay 1" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "Essay 1" should have a "generic name" hint');
    }

    /**
     * A name made only of generic words gets a hint for staff
     *
     * Example: name = CW2 - Summative, have = have
     */
    public function test_a_name_made_only_of_generic_words_gets_a_hint_for_staff_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "CW2 - Summative" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "CW2 - Summative" should have a "generic name" hint');
    }

    /**
     * A name made only of generic words gets a hint for staff
     *
     * Example: name = LAW1001 Assessment, have = have
     */
    public function test_a_name_made_only_of_generic_words_gets_a_hint_for_staff_3(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "LAW1001 Assessment" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "LAW1001 Assessment" should have a "generic name" hint');
    }

    /**
     * A name made only of generic words gets a hint for staff
     *
     * Example: name = Law: Coursework (Part 2), have = have
     */
    public function test_a_name_made_only_of_generic_words_gets_a_hint_for_staff_4(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "Law: Coursework (Part 2)" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "Law: Coursework (Part 2)" should have a "generic name" hint');
    }

    /**
     * A name made only of generic words gets a hint for staff
     *
     * Example: name = Essay II, have = have
     */
    public function test_a_name_made_only_of_generic_words_gets_a_hint_for_staff_5(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "Essay II" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "Essay II" should have a "generic name" hint');
    }

    /**
     * A name made only of generic words gets a hint for staff
     *
     * Example: name = Online Learning Essay, have = not have
     */
    public function test_a_name_made_only_of_generic_words_gets_a_hint_for_staff_6(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "Online Learning Essay" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "Online Learning Essay" should not have a "generic name" hint');
    }

    /**
     * A name made only of generic words gets a hint for staff
     *
     * Example: name = Society and the Law Essay, have = not have
     */
    public function test_a_name_made_only_of_generic_words_gets_a_hint_for_staff_7(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "Society and the Law Essay" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "Society and the Law Essay" should not have a "generic name" hint');
    }

    /**
     * Words of a long course name with a code in it count as generic
     */
    public function test_words_of_a_long_course_name_with_a_code_in_it_count_as_generic(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Computing in History - CSM1056_B_23_48353', 'shortname' => 'CSM1056_B_23'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'teacher1', 'course' => 'CSM1056_B_23', 'role' => 'editingteacher'],
        ]);
        $this->step('And a summative "assign" "Computing in History Essay" in "CSM1056_B_23" with no due date exists');
        $this->step('And a summative "assign" "Computing the Census, 1841-1911" in "CSM1056_B_23" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "Computing in History Essay" should have a "generic name" hint');
        $this->step('And "Computing the Census, 1841-1911" should not have a "generic name" hint');
    }

    /**
     * Convenors see the hint too
     */
    public function test_convenors_see_the_hint_too(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "Essay 1" in "LAW1001" is due "in 3 days"');
        $this->step('When I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "Essay 1" should have a "generic name" hint');
    }

    /**
     * Students don't see the hint
     */
    public function test_students_don_t_see_the_hint(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given a summative "assign" "Essay 1" in "LAW1001" is due "in 3 days"');
        $this->step('When I log in as "student1" and view my dashboard');
        $this->step('Then "Essay 1" should not have a "generic name" hint');
    }

    /**
     * An admin can turn the hint off
     */
    public function test_an_admin_can_turn_the_hint_off(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Law and Society', 'shortname' => 'LAW1001'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'teacher1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LAW1001', 'role' => 'student'],
            ['user' => 'teacher1', 'course' => 'LAW1001', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'LAW1001', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block keeps its hints and guidance from before beta27');
        $this->step('Given the following config values are set as admin:', [
            ['config' => 'genericnames', 'value' => '0', 'plugin' => 'block_le_dashboard'],
        ]);
        $this->step('And a summative "assign" "Essay 1" in "LAW1001" with no due date exists');
        $this->step('When I log in as "teacher1" and view my dashboard');
        $this->step('Then "Essay 1" should not have a "generic name" hint');
    }
}
