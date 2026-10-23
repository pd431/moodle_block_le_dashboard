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
 * Staff see only the staff views, everyone else sees the student view.
 *
 * From tests/behat/view-routing.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class view_routing_test extends view_testcase {
    /**
     * The views offered depend only on staff and convenor roles
     *
     * Example: M1 role = editingteacher, M2 role = student, marking = should, convenor = should not, student = should
     * not, opens on = My marking
     */
    public function test_the_views_offered_depend_only_on_staff_and_convenor_roles_1(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "editingteacher"');
        $this->step('And "staff1" is enrolled in "M2" as "student"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then I should be able to open the "My marking"');
        $this->step('And I should not be able to open the "Convenor view"');
        $this->step('And I should not be able to open the "Student view"');
        $this->step('And the "My marking" should be open');
    }

    /**
     * The views offered depend only on staff and convenor roles
     *
     * Example: M1 role = moduleconvenor, M2 role = student, marking = should not, convenor = should, student = should
     * not, opens on = Convenor view
     */
    public function test_the_views_offered_depend_only_on_staff_and_convenor_roles_2(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "moduleconvenor"');
        $this->step('And "staff1" is enrolled in "M2" as "student"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then I should not be able to open the "My marking"');
        $this->step('And I should be able to open the "Convenor view"');
        $this->step('And I should not be able to open the "Student view"');
        $this->step('And the "Convenor view" should be open');
    }

    /**
     * The views offered depend only on staff and convenor roles
     *
     * Example: M1 role = editingteacher, M2 role = moduleconvenor, marking = should, convenor = should, student =
     * should not, opens on = My marking
     */
    public function test_the_views_offered_depend_only_on_staff_and_convenor_roles_3(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "editingteacher"');
        $this->step('And "staff1" is enrolled in "M2" as "moduleconvenor"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then I should be able to open the "My marking"');
        $this->step('And I should be able to open the "Convenor view"');
        $this->step('And I should not be able to open the "Student view"');
        $this->step('And the "My marking" should be open');
    }

    /**
     * The views offered depend only on staff and convenor roles
     *
     * Example: M1 role = student, M2 role = student, marking = should not, convenor = should not, student = should,
     * opens on = Student view
     */
    public function test_the_views_offered_depend_only_on_staff_and_convenor_roles_4(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "student"');
        $this->step('And "staff1" is enrolled in "M2" as "student"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then I should not be able to open the "My marking"');
        $this->step('And I should not be able to open the "Convenor view"');
        $this->step('And I should be able to open the "Student view"');
        $this->step('And the "Student view" should be open');
    }

    /**
     * Someone who teaches one module and studies another sees only the staff view
     */
    public function test_someone_who_teaches_one_module_and_studies_another_sees_only_the_staff_view(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "student"');
        $this->step('And "staff1" is enrolled in "M2" as "editingteacher"');
        $this->step('And a summative "assign" "A1" in "M1" is due "tomorrow"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then I should not be able to open the "Student view"');
        $this->step('And I should not see "A1" in the "My assessments" block');
    }

    /**
     * Someone with no recognised role gets the student view with nothing to show
     */
    public function test_someone_with_no_recognised_role_gets_the_student_view_with_nothing_to_show(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "guest"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then I should see "You have no assessments to show." in the "My assessments" block');
    }

    /**
     * A staff role in a course where the enrolment has ended does not count
     */
    public function test_a_staff_role_in_a_course_where_the_enrolment_has_ended_does_not_count(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "student"');
        $this->step('And "staff1" is enrolled in "M2" as "editingteacher"');
        $this->step('And the enrolment of "staff1" in "M2" ended "yesterday"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then I should be able to open the "Student view"');
        $this->step('And I should not be able to open the "My marking"');
    }

    /**
     * A saved choice of the student view is ignored once someone holds a staff role
     */
    public function test_a_saved_choice_of_the_student_view_is_ignored_once_someone_holds_a_staff_role(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
            ['fullname' => 'Module 2', 'shortname' => 'M2'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'staff1', 'firstname' => 'Tia', 'lastname' => 'Teacher'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "editingteacher" as a staff role');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('Given "staff1" is enrolled in "M1" as "student"');
        $this->step('And "staff1" last opened the "Student view"');
        $this->step('And "staff1" is enrolled in "M2" as "editingteacher"');
        $this->step('When I log in as "staff1" and view my dashboard');
        $this->step('Then the "My marking" should be open');
    }
}
