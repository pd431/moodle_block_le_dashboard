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
 * The index never misses or fights over a change.
 *
 * From tests/behat/index-never-misses-a-change.feature: each test is one of its scenarios, run by view_testcase one
 * sentence at a time; the sentences that read the page are checked against the rendered dashboard. Every class is
 * covered: a scenario reaches the views through the adapters and the index.
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
final class index_never_misses_a_change_test extends view_testcase {
    /**
     * A submission made while the assessment is being re-indexed is not lost
     */
    public function test_a_submission_made_while_the_assessment_is_being_re_indexed_is_not_lost(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given a re-index of "A1" has started');
        $this->step('When "student2" submits "A1"');
        $this->step('And the running re-index of "A1" finishes');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "A1" should show a "Submitted" progress bar with "1" of "2"');
    }

    /**
     * Two re-indexes of the same assessment at once both succeed
     */
    public function test_two_re_indexes_of_the_same_assessment_at_once_both_succeed(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('When "A1" is re-indexed twice at the same time');
        $this->step('Then neither re-index should fail');
        $this->step('And the index for "A1" should match the activity');
    }

    /**
     * One failing assessment does not stop the nightly reconcile
     */
    public function test_one_failing_assessment_does_not_stop_the_nightly_reconcile(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given a summative "assign" "A2" in "M1" is due "in 2 days"');
        $this->step('And writing the index for "A1" fails once');
        $this->step('When the My assessments nightly reconcile runs');
        $this->step('Then "A2" should be in the index');
    }

    /**
     * Showing a hidden grade item releases feedback without waiting for the night
     */
    public function test_showing_a_hidden_grade_item_releases_feedback_without_waiting_for_the_night(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given "A1" was due "3 days ago"');
        $this->step('And "student1" has submitted "A1"');
        $this->step('And the grade item of "A1" is hidden');
        $this->step('And "marker1" grades "student1" on "A1" with "65"');
        $this->step('And the My assessments index catches up');
        $this->step('When the grade item of "A1" is shown');
        $this->step('And the My assessments index catches up');
        $this->step('And I log in as "student1" and view my dashboard');
        $this->step('Then "A1" should show the status "Feedback released"');
    }

    /**
     * The nightly reconcile leaves old courses alone
     */
    public function test_the_nightly_reconcile_leaves_old_courses_alone(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given the "Live window for the nightly reconcile" setting is "13" months');
        $this->step('And a course "OLD" with a summative "assign" "Z1" was due "2 years ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('And the deadline of "Z1" is changed directly in the database to "3 years ago"');
        $this->step('When the My assessments nightly reconcile runs');
        $this->step('Then the index should still show "Z1" due "2 years ago"');
    }

    /**
     * An event still updates an old course
     */
    public function test_an_event_still_updates_an_old_course(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given a course "OLD" with a summative "assign" "Z1" was due "2 years ago"');
        $this->step('And the My assessments index is up to date');
        $this->step('When "Z1" is renamed to "Z1 resit"');
        $this->step('And the My assessments index catches up');
        $this->step('Then the index should show "Z1 resit"');
    }

    /**
     * A file uploaded to a coursework shows as submitted straight away
     */
    public function test_a_file_uploaded_to_a_coursework_shows_as_submitted_straight_away(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given a summative "coursework" "C1" in "M1" with file submission is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1"');
        $this->step('And "student1" uploads a file to coursework "C1" and the upload event fires');
        $this->step('And I view my dashboard');
        $this->step('Then "C1" should show the status "Submitted"');
    }

    /**
     * A self-certification applied through block_rhb shows straight away
     */
    public function test_a_self_certification_applied_through_block_rhb_shows_straight_away(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given a summative "coursework" "C1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1"');
        $this->step('And "student1" self-certifies "C1" to "in 3 days" and the mitigation added event fires');
        $this->step('And I view my dashboard');
        $this->step('Then "C1" should show the due date "in 3 days"');
        $this->step('And the activity icon of "C1" should have the "selfcert" modifier');
    }

    /**
     * A self-certification removed through block_rhb shows straight away
     */
    public function test_a_self_certification_removed_through_block_rhb_shows_straight_away(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given a summative "coursework" "C1" in "M1" is due "in 1 hour"');
        $this->step('And "student1" self-certified "C1" to "in 3 days"');
        $this->step('And the My assessments index is up to date');
        $this->step('When I log in as "student1"');
        $this->step('And "student1" removes the self-certification of "C1" and the mitigation removed event fires');
        $this->step('And I view my dashboard');
        $this->step('Then "C1" should show the due date "in 1 hour"');
        $this->step('And the activity icon of "C1" should not have the "selfcert" modifier');
    }

    /**
     * After a coursework event, everyone else's rows follow in the queued task
     */
    public function test_after_a_coursework_event_everyone_elses_rows_follow_in_the_queued_task(): void {
        $this->step('Given the following "courses" exist:', [
            ['fullname' => 'Module 1', 'shortname' => 'M1'],
        ]);
        $this->step('And the following "users" exist:', [
            ['username' => 'student1', 'firstname' => 'Sam', 'lastname' => 'Student'],
            ['username' => 'student2', 'firstname' => 'Sue', 'lastname' => 'Student'],
            ['username' => 'marker1', 'firstname' => 'Mo', 'lastname' => 'Marker'],
            ['username' => 'convenor', 'firstname' => 'Cat', 'lastname' => 'Convenor'],
        ]);
        $this->step('And the following "course enrolments" exist:', [
            ['user' => 'student1', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'student2', 'course' => 'M1', 'role' => 'student'],
            ['user' => 'marker1', 'course' => 'M1', 'role' => 'editingteacher'],
            ['user' => 'convenor', 'course' => 'M1', 'role' => 'moduleconvenor'],
        ]);
        $this->step('And the My assessments block is configured with summative tag "summative" and formative tag '
            . '"formative"');
        $this->step('And a summative "assign" "A1" in "M1" is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('Given a summative "coursework" "C1" in "M1" with file submission is due "in 1 hour"');
        $this->step('And the My assessments index is up to date');
        $this->step('When "student1" uploads a file to coursework "C1" and the upload event fires');
        $this->step('Then a re-index of "C1" should be queued');
        $this->step('When the My assessments index catches up');
        $this->step('And I log in as "convenor" and view the "Convenor view"');
        $this->step('Then "C1" should show a "Submitted" progress bar with "1" of "2"');
    }
}
