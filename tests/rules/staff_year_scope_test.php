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
 * Staff and convenors see the assessments of the chosen academic year, by date or by course.
 *
 * From tests/behat/staff-year-scope.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class staff_year_scope_test extends view_testcase {
    /**
     * Under 2025/26 the same rule applies the other way round
     *
     * Example: user = marker1, view = My marking
     */
    public function test_under_2025_26_the_same_rule_applies_the_other_way_round_1(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking" for academic year "2025/26"');
        $this->step('Then I should see "Last year\'s essay" in the "My assessments" block');
        $this->step('And I should see "Early quiz on new module" in the "My assessments" block');
        $this->step('And I should see "Undated on old module" in the "My assessments" block');
        $this->step('And I should see "Resit on old module" in the "My assessments" block');
        $this->step('And I should not see "This year\'s essay" in the "My assessments" block');
    }

    /**
     * Under 2025/26 the same rule applies the other way round
     *
     * Example: user = convenor, view = Convenor view
     */
    public function test_under_2025_26_the_same_rule_applies_the_other_way_round_2(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view" for academic year "2025/26"');
        $this->step('Then I should see "Last year\'s essay" in the "My assessments" block');
        $this->step('And I should see "Early quiz on new module" in the "My assessments" block');
        $this->step('And I should see "Undated on old module" in the "My assessments" block');
        $this->step('And I should see "Resit on old module" in the "My assessments" block');
        $this->step('And I should not see "This year\'s essay" in the "My assessments" block');
    }

    /**
     * All years shows everything
     */
    public function test_all_years_shows_everything(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view" for academic year "All years"');
        $this->step('Then I should see "Resit on old module" in the "My assessments" block');
        $this->step('And I should see "Last year\'s essay" in the "My assessments" block');
        $this->step('And I should see "This year\'s essay" in the "My assessments" block');
        $this->step('And I should see "Undated on old module" in the "My assessments" block');
    }

    /**
     * A course spanning two years shows all its assessments in both
     */
    public function test_a_course_spanning_two_years_shows_all_its_assessments_in_both(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Long module',
                'shortname' => 'LONG',
                'startdate' => '##2026-01-15##',
                'enddate' => '##2026-12-15##',
            ],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'LONG', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'LONG', 'role' => 'editingteacher'],
        ]);
        $this->step('And a summative "assign" "Long module March" in "LONG" was due "2026-03-01 12:00"');
        $this->step('And "student1" has submitted "Long module March"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking" for academic year "2026/27"');
        $this->step('Then I should see "Long module March" in the "My assessments" block');
        $this->step('When I view the "My marking" for academic year "2025/26"');
        $this->step('Then I should see "Long module March" in the "My assessments" block');
    }

    /**
     * Unfinished marking on an out-of-year assessment is neither shown nor counted
     */
    public function test_unfinished_marking_on_an_out_of_year_assessment_is_neither_shown_nor_counted(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking" for academic year "2026/27"');
        $this->step('Then "Resit on old module" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And I should not see "Last year\'s essay" in the "My assessments" block');
        $this->step('And the "To mark" count should be "2"');
        $this->step('When I view the "My marking" for academic year "2025/26"');
        $this->step('Then "Last year\'s essay" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And "Resit on old module" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And the "To mark" count should be "3"');
        $this->step('When I view the "My marking" for academic year "All years"');
        $this->step('Then the "To mark" count should be "3"');
    }

    /**
     * The convenor no longer sees last year's unfinished marking under this year
     */
    public function test_the_convenor_no_longer_sees_last_year_s_unfinished_marking_under_this_year(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "convenor" and view the "Convenor view" for academic year "2026/27"');
        $this->step('Then I should not see "Last year\'s essay" in the "My assessments" block');
        $this->step('And I should see "This year\'s essay" in the "My assessments" block');
        $this->step('And I should not see "(2025/26)" in the "My assessments" block');
    }

    /**
     * A long extension brings last year's assessment into this year as well
     */
    public function test_a_long_extension_brings_last_year_s_assessment_into_this_year_as_well(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given "student1" has an extension on "Last year\'s essay" to "2026-10-25 12:00"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker1" and view the "My marking" for academic year "2026/27"');
        $this->step('Then I should see "Last year\'s essay" in the "My assessments" block');
        $this->step('When I view the "My marking" for academic year "2025/26"');
        $this->step('Then I should see "Last year\'s essay" in the "My assessments" block');
    }

    /**
     * An assessment indexed before the upgrade is placed by its default deadline alone
     */
    public function test_an_assessment_indexed_before_the_upgrade_is_placed_by_its_default_deadline_alone(): void {
        $this->step('Given the following "courses" exist:', [
            [
                'fullname' => 'Module 25/26',
                'shortname' => 'M2526',
                'startdate' => '##2025-09-15##',
                'enddate' => '##2026-06-30##',
            ],
            [
                'fullname' => 'Module 26/27',
                'shortname' => 'M2627',
                'startdate' => '##2026-09-14##',
                'enddate' => '##2027-06-30##',
            ],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M2526', 'role' => 'student'],
            ['user' => 'student1', 'course' => 'M2627', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M2526', 'role' => 'editingteacher'],
            ['user' => 'marker1', 'course' => 'M2627', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M2526', 'role' => 'moduleconvenor'],
            ['user' => 'convenor', 'course' => 'M2627', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the academic year "2025/26" starts on "2025-09-01"');
        $this->step('And the academic year "2026/27" starts on "2026-09-01"');
        $this->step('And it is now "Wed 28 October 2026 10:00" in "Europe/London"');
        $this->step('And a summative "assign" "Resit on old module" in "M2526" was due "2026-10-20 12:00"');
        $this->step('And a summative "assign" "Early quiz on new module" in "M2627" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "Last year\'s essay" in "M2526" was due "2025-10-20 12:00"');
        $this->step('And a summative "assign" "This year\'s essay" in "M2627" is due "2026-12-01 12:00"');
        $this->step('And a summative "assign" "Undated on old module" in "M2526" with no due date exists');
        $this->step('And "student1" has submitted "Resit on old module"');
        $this->step('And "student1" has submitted "Early quiz on new module"');
        $this->step('And "student1" has submitted "Last year\'s essay"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given the index row of "Resit on old module" has no latest individual deadline');
        $this->step('When I log in as "marker1" and view the "My marking" for academic year "2026/27"');
        $this->step('Then I should see "Resit on old module" in the "My assessments" block');
    }
}
