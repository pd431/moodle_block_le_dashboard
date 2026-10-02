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

namespace block_my_assessments;

use block_my_assessments\local\adapter\registry;
use block_my_assessments\local\index\indexer;

/**
 * Keeping the index in step with the site.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\local\index\indexer
 * @covers     \block_my_assessments\observer
 */
final class indexer_test extends \advanced_testcase {
    /** @var \block_my_assessments_generator */
    protected $ma;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->ma = $this->getDataGenerator()->get_plugin_generator('block_my_assessments');
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
        $this->assertTrue($DB->record_exists('block_my_assessments_cm', ['cmid' => $a1->cmid, 'assesstype' => 'summative']));
        $this->assertFalse($DB->record_exists('block_my_assessments_cm', ['cmid' => $a2->cmid]));
        $this->assertTrue($DB->record_exists('block_my_assessments_stu', ['cmid' => $a1->cmid, 'userid' => $student->id]));

        \core_tag_tag::remove_all_item_tags('core', 'course_modules', $a1->cmid);
        $this->runAdhocTasks('\block_my_assessments\task\reindex');
        $this->assertFalse($DB->record_exists('block_my_assessments_cm', ['cmid' => $a1->cmid]));
        $this->assertFalse($DB->record_exists('block_my_assessments_stu', ['cmid' => $a1->cmid]));
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
        $this->assertEquals(0, $DB->get_field('block_my_assessments_stu', 'submitted', ['cmid' => $a->cmid]));
        $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $student->id, 'cmid' => $a->cmid,
            'onlinetext' => 'Done']);
        $this->runAdhocTasks('\block_my_assessments\task\reindex');
        $this->assertEquals(1, $DB->get_field('block_my_assessments_stu', 'submitted', ['cmid' => $a->cmid]));
    }

    /**
     * R8: no due date means not listed; LTI is never listed. R3: hidden activities are flagged.
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
        $indexed = $DB->get_records_menu('block_my_assessments_cm', null, '', 'cmid, adapter');
        $this->assertArrayNotHasKey($nodue->cmid, $indexed);
        $this->assertArrayNotHasKey($forumnodue->cmid, $indexed);
        $this->assertArrayNotHasKey($lti->cmid, $indexed);
        $this->assertSame('generic', $indexed[$forum->cmid]);
        $this->assertNull(
            $DB->get_field('block_my_assessments_stu', 'submitted', ['cmid' => $forum->cmid]),
            'generic types do not know about submissions'
        );
        $this->assertEquals(1, $DB->get_field('block_my_assessments_cm', 'hiddenfromstudents', ['cmid' => $hidden->cmid]));
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
        $this->assertTrue($DB->record_exists('block_my_assessments_cm', ['cmid' => $quiz->cmid]));
        registry::set_enabled('quiz', false);
        (new indexer())->reconcile();
        $this->assertFalse($DB->record_exists('block_my_assessments_cm', ['cmid' => $quiz->cmid]));
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
        $before = $DB->count_records('block_my_assessments_stu', ['cmid' => $a->cmid]);

        set_config('testfailtypes', 'assign', 'block_my_assessments');
        $this->assertTrue((new indexer())->index_cm($a->cmid));
        $this->assertDebuggingCalled();
        $this->assertSame($before, $DB->count_records('block_my_assessments_stu', ['cmid' => $a->cmid]));
        $this->assertNotNull(registry::last_error('assign'));

        unset_config('testfailtypes', 'block_my_assessments');
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
        $access = $DB->get_records_menu('block_my_assessments_stu', ['cmid' => $a->cmid], '', 'userid, canaccess');
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
        $this->assertFalse($DB->record_exists('block_my_assessments_stu', ['userid' => $student->id]));
        delete_course($course, false);
        $this->assertFalse($DB->record_exists('block_my_assessments_cm', ['cmid' => $a->cmid]));
    }
}
