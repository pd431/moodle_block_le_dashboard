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

namespace block_le_dashboard;

use block_le_dashboard\local\adapter\registry;
use block_le_dashboard\local\index\indexer;

/**
 * Writing one assessment's rows: what is kept when an adapter, a student or the database fails.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\index\writer
 */
final class writer_test extends \advanced_testcase {
    /** @var \block_le_dashboard_generator */
    protected $ma;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Index writers observe events after commit, and some tests roll back a write: no test transaction.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->ma = $this->getDataGenerator()->get_plugin_generator('block_le_dashboard');
        $this->ma->configure(['assign', 'quiz', 'forum', 'lti']);
    }

    /**
     * A failing adapter keeps the last good rows and records the error for the admin page.
     */
    public function test_failure_keeps_rows(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        $before = $DB->count_records('block_le_dashboard_stu', ['cmid' => $a->cmid]);

        set_config('testfailtypes', 'assign', 'block_le_dashboard');
        $this->assertTrue((new indexer())->index_cm($a->cmid));
        $this->assertDebuggingCalled();
        $this->assertSame($before, $DB->count_records('block_le_dashboard_stu', ['cmid' => $a->cmid]));
        $this->assertSame(1, registry::last_error('assign')->count);
        registry::flush_errors();
        $this->assertSame(1, json_decode(get_config('block_le_dashboard', 'lasterror_assign'))->count);

        unset_config('testfailtypes', 'block_le_dashboard');
        (new indexer())->index_cm($a->cmid);
        $this->assertNull(registry::last_error('assign'));
    }

    /**
     * An assessment that can't be indexed is still listed, marked as not loaded (Pierre, 2026-10-08): when the
     * adapter fails on its first index, when every student fails, and when the first write fails. A later
     * good index clears the mark.
     */
    public function test_load_error_keeps_assessment_listed(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $due = time() + DAYSECS;

        // The adapter fails before reading the activity: listed, without dates.
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => $due]);
        $this->ma->tag($a->cmid);
        $DB->delete_records(indexer::CM, ['cmid' => $a->cmid]);
        $DB->delete_records(indexer::STU, ['cmid' => $a->cmid]);
        set_config('testfailtypes', 'assign', 'block_le_dashboard');
        $this->assertTrue((new indexer())->index_cm($a->cmid));
        $this->assertDebuggingCalled();
        $row = $DB->get_record(indexer::CM, ['cmid' => $a->cmid], '*', MUST_EXIST);
        $this->assertEquals(1, $row->loaderror);
        $this->assertNull($row->due);
        unset_config('testfailtypes', 'block_le_dashboard');

        // Every student fails: listed with its dates, no student rows.
        set_config('testfailstudent', -1, 'block_le_dashboard');
        $this->assertTrue((new indexer())->index_cm($a->cmid));
        $row = $DB->get_record(indexer::CM, ['cmid' => $a->cmid], '*', MUST_EXIST);
        $this->assertEquals(1, $row->loaderror);
        $this->assertEquals($due, $row->due);
        $this->assertSame(0, $DB->count_records(indexer::STU, ['cmid' => $a->cmid]));
        unset_config('testfailstudent', 'block_le_dashboard');

        // Indexed fine: the mark goes.
        $this->assertTrue((new indexer())->index_cm($a->cmid));
        $this->assertEquals(0, $DB->get_field(indexer::CM, 'loaderror', ['cmid' => $a->cmid]));
        $this->assertSame(1, $DB->count_records(indexer::STU, ['cmid' => $a->cmid, 'userid' => $student->id]));

        // The first write fails: the row written before reading students stays, marked.
        $b = $gen->create_module('assign', ['course' => $course->id, 'duedate' => $due]);
        $this->ma->tag($b->cmid);
        $DB->delete_records(indexer::CM, ['cmid' => $b->cmid]);
        set_config('testfailwrite', $b->cmid, 'block_le_dashboard');
        try {
            (new indexer())->index_cm($b->cmid);
        } catch (\moodle_exception $e) {
            $this->assertSame('testfailure', $e->errorcode);
        }
        $row = $DB->get_record(indexer::CM, ['cmid' => $b->cmid], '*', MUST_EXIST);
        $this->assertEquals(1, $row->loaderror);
        $this->assertEquals($due, $row->due);
        registry::flush_errors();
    }

    /**
     * Deadline rush load test: a write that loses a database deadlock tries again, twice, before it counts as
     * a failure.
     */
    public function test_write_retries_deadlock(): void {
        global $DB;
        // Not inside the test's own transaction: a deadlock inside a larger transaction can't be tried again.
        $this->preventResetByRollback();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);

        set_config('testdeadlock', $a->cmid . ':2', 'block_le_dashboard');
        $DB->delete_records(indexer::STU, ['cmid' => $a->cmid]);
        (new indexer())->index_cm($a->cmid);
        $this->assertSame(1, $DB->count_records(indexer::STU, ['cmid' => $a->cmid]));
        $this->assertEquals(0, $DB->get_field(indexer::CM, 'loaderror', ['cmid' => $a->cmid]));

        set_config('testdeadlock', $a->cmid . ':3', 'block_le_dashboard');
        try {
            (new indexer())->index_cm($a->cmid);
            $this->fail('a third deadlock is an error');
        } catch (\dml_write_exception $e) {
            $this->assertStringContainsString('Deadlock', $e->getMessage());
        }
        $this->assertEquals(1, $DB->get_field(indexer::CM, 'loaderror', ['cmid' => $a->cmid]));
        $this->assertSame(1, $DB->count_records(indexer::STU, ['cmid' => $a->cmid]), 'rows as they were');
        registry::flush_errors();
    }

    /**
     * G9: a group's work is ready at its latest member deadline, so one extended member
     * holds the group back.
     */
    public function test_group_unitdue(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $group = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $s1->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $s2->id]);
        $due = time() - DAYSECS;
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => $due, 'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1, 'teamsubmission' => 1, 'requireallteammemberssubmit' => 0]);
        $extension = time() + 3 * DAYSECS;
        $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $s1->id, 'cmid' => $a->cmid,
            'onlinetext' => 'Ours']);
        $DB->insert_record('assign_user_flags', ['assignment' => $a->id, 'userid' => $s2->id, 'locked' => 0,
            'mailed' => 0, 'extensionduedate' => $extension, 'workflowstate' => '', 'allocatedmarker' => 0]);
        $this->ma->tag($a->cmid);
        $unit = $DB->get_record('block_le_dashboard_mark', ['cmid' => $a->cmid, 'unittype' => 'group']);
        $this->assertEquals($group->id, $unit->unitid);
        $this->assertEquals($extension, $unit->unitdue);
    }

    /**
     * F4: re-indexing some students rewrites their rows only.
     */
    public function test_index_users(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS,
            'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1]);
        $this->ma->tag($a->cmid);
        $agen = $gen->get_plugin_generator('mod_assign');
        $agen->create_submission(['userid' => $s1->id, 'cmid' => $a->cmid, 'onlinetext' => 'One']);
        $agen->create_submission(['userid' => $s2->id, 'cmid' => $a->cmid, 'onlinetext' => 'Two']);
        // The submit events already updated both rows; start them from a stale index.
        $DB->set_field('block_le_dashboard_stu', 'submitted', 0, ['cmid' => $a->cmid]);
        $DB->delete_records('block_le_dashboard_mark', ['cmid' => $a->cmid]);
        $this->assertTrue((new indexer())->index_users($a->cmid, [$s1->id]));
        $submitted = $DB->get_records_menu('block_le_dashboard_stu', ['cmid' => $a->cmid], '', 'userid, submitted');
        $this->assertEquals([$s1->id => 1, $s2->id => 0], $submitted);
        $this->assertSame(1, $DB->count_records('block_le_dashboard_mark', ['cmid' => $a->cmid]));
    }

    /**
     * The latest deadline any student has is indexed with the assessment (EC3), and re-indexing one student
     * moves it both ways.
     */
    public function test_latest_due(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $gen->create_and_enrol($course, 'student');
        $due = time() + DAYSECS;
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => $due]);
        $this->ma->tag($a->cmid);
        $latest = fn() => $DB->get_field('block_le_dashboard_cm', 'latestdue', ['cmid' => $a->cmid]);
        $this->assertEquals($due, $latest());
        // Written directly, so only the re-index below sees it.
        $flag = $DB->insert_record('assign_user_flags', (object)['assignment' => $a->id, 'userid' => $s1->id,
            'extensionduedate' => $due + 30 * DAYSECS]);
        $this->assertTrue((new indexer())->index_users($a->cmid, [$s1->id]));
        $this->assertEquals($due + 30 * DAYSECS, $latest());
        $DB->set_field('assign_user_flags', 'extensionduedate', 0, ['id' => $flag]);
        $this->assertTrue((new indexer())->index_users($a->cmid, [$s1->id]));
        $this->assertEquals($due, $latest());
        // Undated, with nobody given a deadline: none.
        $b = $gen->create_module('assign', ['course' => $course->id, 'duedate' => 0]);
        $this->ma->tag($b->cmid);
        $this->assertNull($DB->get_field('block_le_dashboard_cm', 'latestdue', ['cmid' => $b->cmid]));
    }

    /**
     * One student's bad data (Pierre's coursework text grade, beta14) fails only that student's row:
     * it keeps what was indexed before, everyone else is re-indexed, and the error is reported.
     */
    public function test_failing_student_keeps_last_row(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        (new indexer())->index_cm($a->cmid);
        $DB->set_field(indexer::STU, 'due', 111, ['cmid' => $a->cmid]);

        set_config('testfailstudent', $s1->id, 'block_le_dashboard');
        $this->assertTrue((new indexer())->index_cm($a->cmid));
        $this->assertEquals(111, $DB->get_field(indexer::STU, 'due', ['cmid' => $a->cmid, 'userid' => $s1->id]));
        $due = $DB->get_field(indexer::STU, 'due', ['cmid' => $a->cmid, 'userid' => $s2->id]);
        $this->assertEqualsWithDelta(time() + DAYSECS, $due, 5);
        $this->assertNotNull(registry::last_error('assign'));
        registry::flush_errors();
    }

    /**
     * A student whose marking units fail to read keeps their last indexed units (A7); student rows
     * still update.
     */
    public function test_failing_marking_keeps_last_rows(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() - DAYSECS]);
        $this->ma->tag($a->cmid);
        (new indexer())->index_cm($a->cmid);
        $marks = ['cmid' => $a->cmid, 'unitid' => $student->id, 'unittype' => 'user', 'stage' => 'kept'];
        $DB->insert_record(indexer::MARK, $marks + ['submitted' => 1, 'marked' => 0, 'released' => 0, 'flagged' => 0]);
        $DB->set_field('assign', 'duedate', time() + DAYSECS, ['id' => $a->id]);
        \course_modinfo::purge_course_cache($course->id);

        set_config('testfailmarking', $student->id, 'block_le_dashboard');
        $this->assertTrue((new indexer())->index_cm($a->cmid));
        $this->assertTrue($DB->record_exists(indexer::MARK, $marks));
        $due = $DB->get_field(indexer::STU, 'due', ['cmid' => $a->cmid, 'userid' => $student->id]);
        $this->assertEqualsWithDelta(time() + DAYSECS, $due, 5);
        $this->assertNotNull(registry::last_error('assign'));
        registry::flush_errors();
    }

    /**
     * PR2: a write that fails is rolled back inside index_cm(), keeping the old rows, and the
     * next activity's write still lands once the caller has caught the failure.
     */
    public function test_failed_write_does_not_undo_the_next(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $bad = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $good = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($bad->cmid);
        $this->ma->tag($good->cmid);
        $DB->set_field('block_le_dashboard_cm', 'timeindexed', 12345);

        set_config('testfailwrite', $bad->cmid, 'block_le_dashboard');
        try {
            (new indexer())->index_cms([$bad->cmid, $good->cmid]);
            $this->fail('the failure is thrown at the end');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cm ' . $bad->cmid, $e->getMessage());
        }
        $this->assertDebuggingCalled();
        $this->assertFalse($DB->is_transaction_started(), 'no transaction left open');
        $this->assertEquals(12345, $DB->get_field('block_le_dashboard_cm', 'timeindexed', ['cmid' => $bad->cmid]));
        $this->assertSame(1, $DB->count_records('block_le_dashboard_stu', ['cmid' => $bad->cmid]), 'old rows kept');
        $this->assertNotEquals(12345, $DB->get_field('block_le_dashboard_cm', 'timeindexed', ['cmid' => $good->cmid]));
        $this->assertSame(1, registry::last_error('assign')->count);
    }

    /**
     * No online submission: an activity tagged in-person has no submission state, and every student is a
     * marking unit, marked once the gradebook holds their grade.
     */
    public function test_in_person_assessment(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $q = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => time() - DAYSECS, 'grade' => 10]);
        set_config('inpersontags', 'inperson', 'block_le_dashboard');
        \core_tag_tag::set_item_tags(
            'core',
            'course_modules',
            $q->cmid,
            \context_module::instance($q->cmid),
            ['summative', 'inperson']
        );
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $q->id,
            'itemnumber' => 0, 'courseid' => $course->id]);
        $item->update_final_grade($s1->id, 7, 'test', false, FORMAT_MOODLE, $teacher->id);
        (new indexer())->index_cm($q->cmid);

        $cm = $DB->get_record(indexer::CM, ['cmid' => $q->cmid]);
        $this->assertEquals(1, $cm->nosubmission);
        $this->assertSame('generic', $cm->adapter, 'no Submitted bar');
        $this->assertNull($DB->get_field(indexer::STU, 'submitted', ['cmid' => $q->cmid, 'userid' => $s1->id]));
        $units = $DB->get_records(indexer::MARK, ['cmid' => $q->cmid], '', 'unitid, submitted, marked, markedby, timeready');
        $this->assertCount(2, $units);
        $this->assertEquals(1, $units[$s1->id]->marked);
        $this->assertEquals($teacher->id, $units[$s1->id]->markedby);
        $this->assertEquals(0, $units[$s2->id]->marked);
        $this->assertEquals(1, $units[$s2->id]->submitted);
        $this->assertEquals((int)$q->timeclose, (int)$units[$s2->id]->timeready, 'ready at the deadline');

        // Without the tag it is an ordinary quiz again.
        \core_tag_tag::set_item_tags('core', 'course_modules', $q->cmid, \context_module::instance($q->cmid), ['summative']);
        (new indexer())->index_cm($q->cmid);
        $this->assertEquals(0, $DB->get_field(indexer::CM, 'nosubmission', ['cmid' => $q->cmid]));
        $this->assertSame(0, $DB->count_records(indexer::MARK, ['cmid' => $q->cmid]));
    }

    /**
     * Settings a tagged assessment is missing, for the staff Check settings flag and the convenor's list.
     */
    public function test_setup_problems(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $convenor = $gen->create_and_enrol($course, 'moduleconvenor');
        $tag = function (int $cmid, array $tags): void {
            \core_tag_tag::set_item_tags('core', 'course_modules', $cmid, \context_module::instance($cmid), $tags);
        };
        $setup = function (int $cmid): ?string {
            global $DB;
            (new indexer())->index_cm($cmid);
            return $DB->get_field(indexer::CM, 'setup', ['cmid' => $cmid]);
        };
        $ok = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $tag($ok->cmid, ['summative']);
        $this->assertNull($setup($ok->cmid));

        $undated = $gen->create_module('assign', ['course' => $course->id, 'duedate' => 0, 'grade' => 0]);
        $tag($undated->cmid, ['summative', 'formative']);
        $this->assertSame('nodue,nograde,bothtags', $setup($undated->cmid));

        // Marks never shown: flagged on summative work only (coordinator, 2026-10-07).
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => time() + DAYSECS, 'grade' => 10]);
        $practice = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => time() + DAYSECS, 'grade' => 10]);
        $DB->set_field_select('quiz', 'reviewmarks', 0, 'id IN (?, ?)', [$quiz->id, $practice->id]);
        $tag($quiz->cmid, ['summative']);
        $this->assertSame('nomarks', $setup($quiz->cmid));
        $tag($practice->cmid, ['formative']);
        $this->assertNull($setup($practice->cmid));

        // The convenor's list: those above, and a tagged activity of a type that isn't enabled.
        $workshop = $gen->create_module('workshop', ['course' => $course->id]);
        $tag($workshop->cmid, ['summative']);
        $hint = \block_le_dashboard\local\setupchecks::hint($convenor->id, null, [(int)$course->id]);
        $this->assertSame('3 tagged activities need their settings checked across your courses.', $hint['label']);
        $problems = array_column($hint['courses'][0]['activities'], 'problems', 'url');
        $this->assertSame('No due date; No grade; Tagged summative and formative', $problems[(string)new \moodle_url(
            '/mod/assign/view.php',
            ['id' => $undated->cmid]
        )]);
        $this->assertSame('Not shown on dashboards: Workshop is not enabled in Le Dashboard', $problems[(string)new \moodle_url(
            '/mod/workshop/view.php',
            ['id' => $workshop->cmid]
        )]);
    }

    /**
     * A final submission keeps its cut-off and records why it can't change, so it stays in Upcoming
     * as Submitted until the deadline (Pierre, 2026-10-09); a teacher's lock says so instead.
     */
    public function test_final_submission_stays_upcoming(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $s3 = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + WEEKSECS,
            'submissiondrafts' => 1, 'assignsubmission_onlinetext_enabled' => 1]);
        \core_tag_tag::set_item_tags('core', 'course_modules', $a->cmid, \context_module::instance($a->cmid), ['summative']);
        $now = time();
        foreach ([$s1->id => 'submitted', $s2->id => 'submitted', $s3->id => 'draft'] as $userid => $status) {
            $DB->insert_record('assign_submission', ['assignment' => $a->id, 'userid' => $userid, 'status' => $status,
                'latest' => 1, 'attemptnumber' => 0, 'timecreated' => $now, 'timemodified' => $now, 'groupid' => 0]);
        }
        $DB->insert_record('assign_user_flags', ['assignment' => $a->id, 'userid' => $s2->id, 'locked' => 1,
            'mailed' => 0, 'extensionduedate' => 0, 'workflowstate' => '', 'allocatedmarker' => 0]);
        (new indexer())->index_cm($a->cmid);
        $rows = $DB->get_records(indexer::STU, ['cmid' => $a->cmid], '', 'userid, due, cutoff, submitted, timereleased,
            reopened, enrolstart, lockreason');
        $this->assertNull($rows[$s1->id]->cutoff, 'the cut-off is not ended early any more');
        $this->assertSame('finalised', $rows[$s1->id]->lockreason);
        $this->assertSame('teacher', $rows[$s2->id]->lockreason);
        $this->assertNull($rows[$s3->id]->lockreason, 'a draft is not final');
        [$section, $status] = \block_le_dashboard\local\view\classifier::student($rows[$s1->id], $now, 7);
        $this->assertSame(\block_le_dashboard\local\view\classifier::UPCOMING, $section);
        $this->assertSame(\block_le_dashboard\local\view\classifier::STATUS_SUBMITTED, $status);
    }

    /**
     * Pierre, 2026-10-09: the marking units of students an access restriction shuts out leave the index (moved
     * to an extension copy); a team's unit stays while any member can access; lifting the restriction brings
     * them back.
     */
    public function test_shut_out_students_leave_the_marking(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $s3 = $gen->create_and_enrol($course, 'student');
        $ext = $gen->create_group(['courseid' => $course->id, 'name' => 'Extension']);
        $not = \core_availability\tree::get_nested_json([\availability_group\condition::get_json($ext->id)], '!|');
        $availability = json_encode(\core_availability\tree::get_root_json([$not]));
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() - DAYSECS, 'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1, 'availability' => $availability]);
        foreach ([$s1, $s2, $s3] as $s) {
            $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $s->id, 'cmid' => $a->cmid,
                'onlinetext' => 'Mine']);
        }
        $this->ma->tag($a->cmid);
        $units = fn() => $DB->get_fieldset_select('block_le_dashboard_mark', 'unitid', 'cmid = ?', [$a->cmid]);
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id, $s3->id], $units());

        $gen->create_group_member(['groupid' => $ext->id, 'userid' => $s2->id]);
        (new indexer())->index_cm($a->cmid);
        $this->assertEqualsCanonicalizing([$s1->id, $s3->id], $units());
        $this->assertEquals(1, $DB->count_records(indexer::STU, ['cmid' => $a->cmid, 'userid' => $s2->id,
            'canaccess' => 0]), 'the student row stays, as before');
        // One student's re-index (their own Refresh) leaves them out too.
        (new indexer())->index_users($a->cmid, [$s2->id]);
        $this->assertEqualsCanonicalizing([$s1->id, $s3->id], $units());

        groups_remove_member($ext->id, $s2->id);
        (new indexer())->index_cm($a->cmid);
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id, $s3->id], $units());

        // A team counts while one member can access it, and goes once none can.
        $teams = $gen->create_grouping(['courseid' => $course->id]);
        $team = $gen->create_group(['courseid' => $course->id]);
        $gen->create_grouping_group(['groupingid' => $teams->id, 'groupid' => $team->id]);
        $gen->create_group_member(['groupid' => $team->id, 'userid' => $s1->id]);
        $gen->create_group_member(['groupid' => $team->id, 'userid' => $s2->id]);
        $t = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() - DAYSECS, 'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1, 'availability' => $availability, 'teamsubmission' => 1,
            'requireallteammemberssubmit' => 0, 'teamsubmissiongroupingid' => $teams->id]);
        $this->setUser($s1);
        $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $s1->id, 'cmid' => $t->cmid,
            'onlinetext' => 'Ours']);
        $this->setAdminUser();
        $gen->create_group_member(['groupid' => $ext->id, 'userid' => $s2->id]);
        $this->ma->tag($t->cmid);
        $teamunits = fn() => $DB->get_fieldset_select(
            'block_le_dashboard_mark',
            'unitid',
            'cmid = ? AND unittype = ?',
            [$t->cmid, 'group']
        );
        $this->assertContains((string)$team->id, array_map('strval', $teamunits()));
        $gen->create_group_member(['groupid' => $ext->id, 'userid' => $s1->id]);
        (new indexer())->index_cm($t->cmid);
        $this->assertNotContains((string)$team->id, array_map('strval', $teamunits()));
    }

    /**
     * A generic activity done in person has a marking stage, so markers see it to mark from the date.
     */
    public function test_in_person_generic_activity(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        \block_le_dashboard\local\adapter\registry::set_enabled('workshop', true);
        $w = $gen->create_module('workshop', ['course' => $course->id, 'submissionend' => time() - 2 * DAYSECS]);
        set_config('inpersontags', 'inperson', 'block_le_dashboard');
        \core_tag_tag::set_item_tags(
            'core',
            'course_modules',
            $w->cmid,
            \context_module::instance($w->cmid),
            ['summative', 'inperson']
        );
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'workshop', 'iteminstance' => $w->id,
            'itemnumber' => 0, 'courseid' => $course->id]);
        $item->update_final_grade($s1->id, 64, 'test', false, FORMAT_MOODLE, $teacher->id);
        (new indexer())->index_cm($w->cmid);

        $cm = $DB->get_record(indexer::CM, ['cmid' => $w->cmid]);
        $this->assertEquals(1, $cm->nosubmission);
        $this->assertSame('marked', $cm->stages);
        $export = (new \block_le_dashboard\local\view\staff($teacher->id))->export();
        $tomark = [];
        foreach ($export['sections'] as $section) {
            if ($section['key'] === 'tomark') {
                $tomark = array_column($section['rows'], null, 'name');
            }
        }
        $this->assertArrayHasKey($w->name, $tomark);
    }
}
