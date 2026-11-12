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
 * Optional assignment markers count as Moodle counts them.
 *
 * From tests/behat/optional-markers.feature: each test is one of its scenarios, run by view_testcase one sentence at a
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
final class optional_markers_test extends view_testcase {
    /**
     * Agreement waits for an optional marker who is switched on
     */
    public function test_agreement_waits_for_an_optional_marker_who_is_switched_on(): void {
        $this->step('Given the site runs Moodle 5.3 or later');
        $this->step('And the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'marker3', 'firstname' => 'Meg', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker3', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation, 2 markers, 1 optional marker and '
            . 'manual agreement was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student1" for "A1" has "marker3" as its optional marker');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1" and "marker2"');
        $this->step('Given "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker2" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker3" and view the "My marking"');
        $this->step('Then "A1" should appear in the "Marking in progress" section with "1" to mark');
        $this->step('And I log in as "convenor" and view the "Convenor view"');
        $this->step('And "A1" should show a "Marker 2" progress bar with "1" of "1"');
        $this->step('And "A1" should show a "Marker 3" progress bar with "0" of "1"');
        $this->step('And "A1" should not show an "Agreed" progress bar');
        $this->step('And "marker3" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('And I log in as "convenor" and view the "Convenor view"');
        $this->step('And "A1" should show an "Agreed" progress bar with "0" of "1"');
    }

    /**
     * An optional marker who is switched off has no marking, and agreement doesn't wait for them
     */
    public function test_an_optional_marker_who_is_switched_off_has_no_marking_and_agreement_doesn_t_wait_for_them(): void {
        $this->step('Given the site runs Moodle 5.3 or later');
        $this->step('And the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'marker2', 'firstname' => 'Max', 'lastname' => 'Marker'],
            ['username' => 'marker3', 'firstname' => 'Meg', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker2', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'marker3', 'course' => 'M1', 'role' => 'teacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And the My assessments block treats "moduleconvenor" as a module convenor role');
        $this->step('And a summative "assign" "A1" in "M1" with marker allocation, 2 markers, 1 optional marker and '
            . 'manual agreement was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1"');
        $this->step('And the submission of "student1" for "A1" has "marker3" as its optional marker');
        $this->step('And the submission of "student1" for "A1" is allocated to "marker1" and "marker2"');
        $this->step('Given the optional marker of "student1" on "A1" is switched off');
        $this->step('And "marker1" marks the submission of "student1" for "A1"');
        $this->step('And "marker2" marks the submission of "student1" for "A1"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "marker3" and view the "My marking"');
        $this->step('Then "A1" should not appear in the "Marking in progress" section');
        $this->step('And I log in as "marker1" and view the "My marking"');
        $this->step('And "A1" should appear in the "Marking in progress" section with "1" to mark');
    }

}
