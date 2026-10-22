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

use block_le_dashboard\local\index\enrolment;
use block_le_dashboard\local\index\indexer;

/**
 * Who is in a course, for the index: students, markers, when they joined, who an activity lets in.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\index\enrolment
 */
final class enrolment_test extends \advanced_testcase {
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
     * The students and the markers of a course come from one query: students whatever their enrolment's
     * state (EC4), active students, and the staff and convenors with an active enrolment (EC1).
     */
    public function test_students_and_markers_of_a_course(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $convenorrole = $this->ma->configure(['assign']);
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $marker1 = $gen->create_and_enrol($course, 'teacher');
        $marker2 = $gen->create_and_enrol($course, 'editingteacher');
        $convenor = $gen->create_user();
        $gen->enrol_user($convenor->id, $course->id, $convenorrole);
        $gone = $gen->create_and_enrol($course, 'teacher');
        $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $plugin = enrol_get_plugin('manual');
        $plugin->update_user_enrol($enrol, $s1->id, ENROL_USER_SUSPENDED);
        $plugin->update_user_enrol($enrol, $gone->id, ENROL_USER_SUSPENDED);
        $ids = fn(array $users) => array_map('intval', array_column($users, 'id'));
        $sorted = function (array $ids) {
            sort($ids);
            return $ids;
        };

        $this->assertSame($sorted($ids([$s1, $s2])), $sorted(enrolment::students((int)$course->id)));
        $this->assertSame($ids([$s2]), enrolment::students((int)$course->id, true));
        $this->assertSame($sorted($ids([$marker1, $marker2, $convenor])), $sorted(enrolment::markers((int)$course->id)));
        $this->assertSame(enrolment::students((int)$course->id), indexer::students((int)$course->id));
        $this->assertSame(enrolment::markers((int)$course->id), indexer::markers((int)$course->id));
    }
}
