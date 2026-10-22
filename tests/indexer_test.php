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
     * Deadline rush: a student's submission updates their own row at once and holds back everyone else's re-index.
     */
    public function test_submission_holds_back_reindex(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS,
            'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1]);
        $this->ma->tag($a->cmid);
        $DB->delete_records('task_adhoc', ['component' => 'block_le_dashboard']);
        $before = time();
        $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $s1->id, 'cmid' => $a->cmid,
            'onlinetext' => 'Mine']);
        $own = ['cmid' => $a->cmid, 'userid' => $s1->id];
        $this->assertEquals(1, $DB->get_field('block_le_dashboard_stu', 'submitted', $own));
        $tasks = $DB->get_records('task_adhoc', ['component' => 'block_le_dashboard']);
        $this->assertCount(1, $tasks);
        $this->assertGreaterThanOrEqual($before + indexer::COALESCE, (int)reset($tasks)->nextruntime);
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
     * Pierre, 2026-10-06 (beta14): the nightly reconcile ran alongside the first backfill, so it skipped while
     * one was queued. Where ad hoc tasks don't run that left both stuck (Pierre, 2026-10-19): the hourly task
     * now does the first fill itself, under the same lock as the backfill, and only then clears "Preparing".
     */
    public function test_reconcile_does_queued_backfill(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->ma->tag($a->cmid);
        $DB->delete_records('task_adhoc');
        task\backfill::rebuild();
        $this->expectOutputRegex('/Indexing course/');
        (new task\reconcile())->execute();
        $this->assertSame(1, indexer::counts()['cm']);
        $this->assertEmpty(get_config('block_le_dashboard', 'backfillpending'));
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
     * The convenor's settings-check line is singular for one activity (beta22: "1 tagged activities need their").
     */
    public function test_setup_count_singular(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $convenor = $gen->create_and_enrol($course, 'moduleconvenor');
        $cm = $gen->create_module('assign', ['course' => $course->id, 'duedate' => 0]);
        \core_tag_tag::set_item_tags('core', 'course_modules', $cm->cmid, \context_module::instance($cm->cmid), ['summative']);
        (new indexer())->index_cm($cm->cmid);

        $hint = \block_le_dashboard\local\setupchecks::hint($convenor->id, null, [(int)$course->id]);
        $this->assertSame('1 tagged activity needs its settings checked across your courses.', $hint['label']);
        $year = (object)['id' => 0, 'label' => '2026/27'];
        $hint = \block_le_dashboard\local\setupchecks::hint($convenor->id, $year, [(int)$course->id]);
        $this->assertSame('1 tagged activity needs its settings checked across your 2026/27 courses.', $hint['label']);
    }
}
