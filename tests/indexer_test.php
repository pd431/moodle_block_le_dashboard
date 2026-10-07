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
 * Keeping the index in step with the site.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\index\indexer
 * @covers     \block_le_dashboard\observer
 */
final class indexer_test extends \advanced_testcase {
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
     * Only tagged assessments are indexed; untagging removes them through the observer.
     */
    public function test_tags_decide(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $a1 = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $a2 = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a1->cmid, 'summative');
        (new indexer())->index_cm($a2->cmid);
        $this->assertTrue($DB->record_exists('block_le_dashboard_cm', ['cmid' => $a1->cmid, 'assesstype' => 'summative']));
        $this->assertFalse($DB->record_exists('block_le_dashboard_cm', ['cmid' => $a2->cmid]));
        $this->assertTrue($DB->record_exists('block_le_dashboard_stu', ['cmid' => $a1->cmid, 'userid' => $student->id]));

        \core_tag_tag::remove_all_item_tags('core', 'course_modules', $a1->cmid);
        $this->runAdhocTasks('\block_le_dashboard\task\reindex');
        $this->assertFalse($DB->record_exists('block_le_dashboard_cm', ['cmid' => $a1->cmid]));
        $this->assertFalse($DB->record_exists('block_le_dashboard_stu', ['cmid' => $a1->cmid]));
    }

    /**
     * A submission event re-indexes the assessment in the background.
     */
    public function test_submission_event_reindexes(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS,
            'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1]);
        $this->ma->tag($a->cmid);
        $this->assertEquals(0, $DB->get_field('block_le_dashboard_stu', 'submitted', ['cmid' => $a->cmid]));
        $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $student->id, 'cmid' => $a->cmid,
            'onlinetext' => 'Done']);
        $this->runAdhocTasks('\block_le_dashboard\task\reindex');
        $this->assertEquals(1, $DB->get_field('block_le_dashboard_stu', 'submitted', ['cmid' => $a->cmid]));
    }

    /**
     * EC2: a tagged activity with no due date is listed, with no date; LTI is never listed (R8).
     * R3: hidden activities are flagged.
     */
    public function test_listing_rules(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $nodue = $gen->create_module('assign', ['course' => $course->id, 'duedate' => 0]);
        $forum = $gen->create_module('forum', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $forumnodue = $gen->create_module('forum', ['course' => $course->id]);
        $lti = $gen->create_module('lti', ['course' => $course->id]);
        $hidden = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS, 'visible' => 0]);
        foreach ([$nodue, $forum, $forumnodue, $lti, $hidden] as $cm) {
            $this->ma->tag($cm->cmid);
        }
        $indexed = $DB->get_records_menu('block_le_dashboard_cm', null, '', 'cmid, adapter');
        $this->assertArrayHasKey($nodue->cmid, $indexed);
        $this->assertSame('generic', $indexed[$forumnodue->cmid]);
        $this->assertNull($DB->get_field('block_le_dashboard_cm', 'due', ['cmid' => $nodue->cmid]));
        $this->assertNull($DB->get_field('block_le_dashboard_cm', 'due', ['cmid' => $forumnodue->cmid]));
        $this->assertArrayNotHasKey($lti->cmid, $indexed);
        $this->assertSame('generic', $indexed[$forum->cmid]);
        $this->assertNull(
            $DB->get_field('block_le_dashboard_stu', 'submitted', ['cmid' => $forum->cmid]),
            'generic types do not know about submissions'
        );
        $this->assertEquals(1, $DB->get_field('block_le_dashboard_cm', 'hiddenfromstudents', ['cmid' => $hidden->cmid]));
    }

    /**
     * EC4: a suspended student keeps their rows, flagged inactive, so their submitted work is
     * still marked; an unenrolled one leaves. EC10: each row records when the student joined.
     */
    public function test_students_who_leave(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() - DAYSECS,
            'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1]);
        $this->ma->tag($a->cmid);
        foreach ([$s1, $s2] as $s) {
            $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $s->id, 'cmid' => $a->cmid,
                'onlinetext' => 'Done']);
        }
        $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $plugin = enrol_get_plugin('manual');
        $plugin->update_user_enrol($enrol, $s1->id, ENROL_USER_SUSPENDED);
        $plugin->unenrol_user($enrol, $s2->id);
        (new indexer())->index_cm($a->cmid);

        $rows = $DB->get_records('block_le_dashboard_stu', ['cmid' => $a->cmid], '', 'userid, active, submitted, enrolstart');
        $this->assertSame([(int)$s1->id], array_map('intval', array_keys($rows)));
        $this->assertEquals(0, $rows[$s1->id]->active);
        $this->assertEquals(1, $rows[$s1->id]->submitted);
        $this->assertNotEmpty($rows[$s1->id]->enrolstart);
        $this->assertEquals(1, $DB->count_records('block_le_dashboard_mark', ['cmid' => $a->cmid, 'submitted' => 1]));
    }

    /**
     * Disabling a type removes it at the next reconcile.
     */
    public function test_disabled_type_removed(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => time() + DAYSECS]);
        $this->ma->tag($quiz->cmid);
        $this->assertTrue($DB->record_exists('block_le_dashboard_cm', ['cmid' => $quiz->cmid]));
        registry::set_enabled('quiz', false);
        (new indexer())->reconcile();
        $this->assertFalse($DB->record_exists('block_le_dashboard_cm', ['cmid' => $quiz->cmid]));
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
     * Group restrictions: students outside the group can't access.
     */
    public function test_availability(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $in = $gen->create_and_enrol($course, 'student');
        $out = $gen->create_and_enrol($course, 'student');
        $group = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $in->id]);
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_group\condition::get_json($group->id)]
        ));
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS,
            'availability' => $availability]);
        $this->ma->tag($a->cmid);
        $access = $DB->get_records_menu('block_le_dashboard_stu', ['cmid' => $a->cmid], '', 'userid, canaccess');
        $this->assertEquals(1, $access[$in->id]);
        $this->assertEquals(0, $access[$out->id]);
    }

    /**
     * Deleting a user or course removes their rows.
     */
    public function test_deletions(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        delete_user($student);
        $this->assertFalse($DB->record_exists('block_le_dashboard_stu', ['userid' => $student->id]));
        delete_course($course, false);
        $this->assertFalse($DB->record_exists('block_le_dashboard_cm', ['cmid' => $a->cmid]));
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
     * F1: a change during a running re-index queues another; a waiting one is not doubled.
     */
    public function test_queue_dedupes_waiting_only(): void {
        global $DB;
        $params = ['classname' => '\\block_le_dashboard\\task\\reindex'];
        indexer::queue_cm(42);
        indexer::queue_cm(42);
        $this->assertSame(1, $DB->count_records('task_adhoc', $params));
        $DB->set_field('task_adhoc', 'timestarted', time(), $params);
        indexer::queue_cm(42);
        $this->assertSame(2, $DB->count_records('task_adhoc', $params), 'the running one has read its data already');
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
     * F5 and F2: the reconcile skips courses whose deadlines are all older than the live window,
     * and one failing assessment doesn't stop the others.
     */
    public function test_reconcile(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $old = $gen->create_course();
        $live = $gen->create_course();
        $gen->create_and_enrol($live, 'student');
        $z = $gen->create_module('assign', ['course' => $old->id, 'duedate' => time() - 2 * YEARSECS]);
        $a1 = $gen->create_module('assign', ['course' => $live->id, 'duedate' => time() + DAYSECS]);
        $a2 = $gen->create_module('assign', ['course' => $live->id, 'duedate' => time() + 2 * DAYSECS]);
        foreach ([$z, $a1, $a2] as $a) {
            $this->ma->tag($a->cmid);
        }
        $DB->set_field('assign', 'name', 'Renamed', ['id' => $z->id]);
        rebuild_course_cache($old->id, true);
        $DB->delete_records('block_le_dashboard_cm', ['cmid' => $a2->cmid]);
        set_config('testfailwrite', $a1->cmid, 'block_le_dashboard');
        (new indexer())->reconcile();
        $this->assertDebuggingCalled();
        $this->assertNotEquals('Renamed', $DB->get_field('block_le_dashboard_cm', 'name', ['cmid' => $z->cmid]));
        $this->assertTrue($DB->record_exists('block_le_dashboard_cm', ['cmid' => $a2->cmid]), 'carried on after a1');
        set_config('reconcilemonths', 0, 'block_le_dashboard');
        (new indexer())->reconcile();
        $this->assertEquals('Renamed', $DB->get_field('block_le_dashboard_cm', 'name', ['cmid' => $z->cmid]));
    }

    /**
     * F3: hiding or showing an activity's grade item queues a re-index.
     */
    public function test_grade_item_updated_queues(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        $this->runAdhocTasks('\\block_le_dashboard\\task\\reindex');
        local\memo::reset();
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $a->id,
            'courseid' => $course->id]);
        $item->set_hidden(1);
        $this->assertTrue($DB->record_exists_select(
            'task_adhoc',
            'classname = ? AND timestarted IS NULL',
            ['\\block_le_dashboard\\task\\reindex']
        ));
    }

    /**
     * Rebuild (Pierre, 2026-10-06): purge empties every index table and the backfill fills it again,
     * leaving out rows for activities that no longer exist.
     */
    public function test_rebuild(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        $this->runAdhocTasks('\\block_le_dashboard\\task\\reindex');
        $row = $DB->get_record(indexer::CM, ['cmid' => $a->cmid]);
        unset($row->id);
        $row->cmid += 1000;
        $DB->insert_record(indexer::CM, $row);
        $this->assertEquals(2, indexer::counts()['cm']);

        $DB->delete_records('task_adhoc', ['classname' => '\\block_le_dashboard\\task\\backfill']);
        $this->assertFalse(task\backfill::pending());
        task\backfill::rebuild();
        $this->assertSame(['cm' => 0, 'stu' => 0, 'mark' => 0], indexer::counts());
        $this->assertTrue(task\backfill::pending());
        $this->assertNotEmpty(get_config('block_le_dashboard', 'backfillpending'));

        $this->expectOutputRegex('/Indexing course/');
        $this->runAdhocTasks('\\block_le_dashboard\\task\\backfill');
        $this->assertFalse(task\backfill::pending());
        $this->assertEmpty(get_config('block_le_dashboard', 'backfillpending'));
        $this->assertEquals([$a->cmid], array_map('intval', $DB->get_fieldset_select(indexer::CM, 'cmid', '1 = 1')));
        $this->assertTrue($DB->record_exists(indexer::STU, ['cmid' => $a->cmid, 'userid' => $student->id]));
    }

    /**
     * Pierre, 2026-10-06 (beta14): the nightly reconcile ran alongside the first backfill. It now
     * leaves the work to a queued or running backfill, and leaves "Preparing" showing.
     */
    public function test_reconcile_waits_for_backfill(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        $DB->delete_records('task_adhoc');
        task\backfill::rebuild();
        $this->expectOutputRegex('/reconcile is skipped/');
        (new task\reconcile())->execute();
        $this->assertSame(0, indexer::counts()['cm']);
        $this->assertNotEmpty(get_config('block_le_dashboard', 'backfillpending'));
    }

    /**
     * A backfill that failed and waits to retry doesn't stop the nightly reconcile.
     */
    public function test_reconcile_runs_past_failed_backfill(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        $DB->delete_records('task_adhoc');
        task\backfill::rebuild();
        $DB->set_field('task_adhoc', 'faildelay', 60, ['classname' => '\\' . task\backfill::class]);
        $this->expectOutputRegex('/Indexing course/');
        (new task\reconcile())->execute();
        $this->assertSame(1, indexer::counts()['cm']);
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
     * Only site admins can open the Index page, so only they can rebuild.
     */
    public function test_rebuild_page_needs_site_config(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $page = admin_get_root(true, false)->locate('block_le_dashboard_index');
        $this->assertSame(['moodle/site:config'], $page->req_capability);
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);
        $this->assertFalse($page->check_access());
        $this->setAdminUser();
        $this->assertTrue($page->check_access());
    }

    /**
     * PR5: saving a tag or role setting queues a full re-index and shows Preparing.
     */
    public function test_settings_queue_backfill(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        $DB->delete_records('task_adhoc', ['classname' => '\\block_le_dashboard\\task\\backfill']);
        unset_config('backfillpending', 'block_le_dashboard');
        admin_write_settings((object)['s_block_le_dashboard_certaintags' => 'exam']);
        $this->assertSame('exam', get_config('block_le_dashboard', 'certaintags'));
        $this->assertTrue($DB->record_exists('task_adhoc', ['classname' => '\\block_le_dashboard\\task\\backfill']));
        $this->assertNotEmpty(get_config('block_le_dashboard', 'backfillpending'));
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
     * A1, A3: a running backfill has read its data, so a change made meanwhile queues another; only one
     * ever waits to start, whichever writer queues it.
     */
    public function test_backfill_queues_behind_a_running_one(): void {
        global $DB;
        $classname = '\\' . task\backfill::class;
        $DB->delete_records('task_adhoc', ['classname' => $classname]);
        task\backfill::queue();
        $DB->set_field('task_adhoc', 'timestarted', time(), ['classname' => $classname]);
        task\backfill::queue();
        task\backfill::rebuild();
        set_config('formativetags', 'practice', 'block_le_dashboard');
        task\backfill::setting_changed('block_le_dashboard/formativetags');
        $this->assertSame(2, $DB->count_records('task_adhoc', ['classname' => $classname]));
        $this->assertSame(1, $DB->count_records_select(
            'task_adhoc',
            'classname = :c AND timestarted IS NULL',
            ['c' => $classname]
        ));
    }

    /**
     * A12: a role given or taken away on a category re-indexes the indexed courses under it, and only those.
     */
    public function test_category_role_queues_courses(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $parent = $gen->create_category();
        $child = $gen->create_category(['parent' => $parent->id]);
        $other = $gen->create_category();
        $inside = $gen->create_course(['category' => $child->id]);
        $outside = $gen->create_course(['category' => $other->id]);
        foreach ([$inside, $outside] as $course) {
            $gen->create_and_enrol($course, 'student');
            $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
            $this->ma->tag($a->cmid);
        }
        $teacher = $gen->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $DB->delete_records('task_adhoc');
        $queued = function () use ($DB) {
            $ids = [];
            foreach ($DB->get_records('task_adhoc', ['classname' => '\\block_le_dashboard\\task\\reindex']) as $t) {
                $data = json_decode($t->customdata);
                if (isset($data->courseid)) {
                    $ids[] = (int)$data->courseid;
                }
            }
            return $ids;
        };
        role_assign($roleid, $teacher->id, \context_coursecat::instance($parent->id)->id);
        $this->assertSame([(int)$inside->id], $queued());
        $DB->delete_records('task_adhoc');
        role_unassign($roleid, $teacher->id, \context_coursecat::instance($parent->id)->id);
        $this->assertSame([(int)$inside->id], $queued());
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
     * A final submission is closed from the second it was handed in, so it is Past at once.
     */
    public function test_final_submission_is_past_at_once(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + WEEKSECS,
            'submissiondrafts' => 1, 'assignsubmission_onlinetext_enabled' => 1]);
        \core_tag_tag::set_item_tags('core', 'course_modules', $a->cmid, \context_module::instance($a->cmid), ['summative']);
        $now = time();
        $DB->insert_record('assign_submission', ['assignment' => $a->id, 'userid' => $s1->id, 'status' => 'submitted',
            'latest' => 1, 'attemptnumber' => 0, 'timecreated' => $now, 'timemodified' => $now, 'groupid' => 0]);
        (new indexer())->index_cm($a->cmid);
        $row = $DB->get_record(indexer::STU, ['cmid' => $a->cmid, 'userid' => $s1->id]);
        $this->assertLessThan($now, (int)$row->cutoff);
        [$section, $status] = \block_le_dashboard\local\view\classifier::student($row, $now, 7);
        $this->assertSame(\block_le_dashboard\local\view\classifier::PAST, $section);
        $this->assertSame(\block_le_dashboard\local\view\classifier::STATUS_SUBMITTED, $status);
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
