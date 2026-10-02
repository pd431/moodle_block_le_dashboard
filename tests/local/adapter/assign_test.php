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

namespace block_my_assessments\local\adapter;

use block_my_assessments\local\index\marking_row;

/**
 * The assign adapter agrees with assign's own rules.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\local\adapter\assign
 */
final class assign_test extends \advanced_testcase {
    /**
     * Effective deadlines match assign's own override rules, with extensions on top.
     */
    public function test_effective_dates_match_assign(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $agen = $gen->get_plugin_generator('mod_assign');
        $course = $gen->create_course();
        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $students[$i] = $gen->create_and_enrol($course, 'student');
        }
        $g1 = $gen->create_group(['courseid' => $course->id]);
        $g2 = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $students[1]->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $students[2]->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $students[2]->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $students[3]->id]);

        $now = time();
        $a = $agen->create_instance(['course' => $course->id, 'duedate' => $now + 5 * DAYSECS,
            'cutoffdate' => $now + 7 * DAYSECS]);
        // User override changes the deadline only.
        $agen->create_override(['assignid' => $a->id, 'userid' => $students[0]->id, 'duedate' => $now + 10 * DAYSECS]);
        // Two group overrides: sortorder decides for the student in both.
        $agen->create_override(['assignid' => $a->id, 'groupid' => $g1->id, 'duedate' => $now + 8 * DAYSECS,
            'cutoffdate' => $now + 9 * DAYSECS, 'sortorder' => 1]);
        $agen->create_override(['assignid' => $a->id, 'groupid' => $g2->id, 'duedate' => $now + 12 * DAYSECS,
            'sortorder' => 2]);
        // An extension past the cut-off moves both.
        $agen->create_extension(['cmid' => $a->cmid, 'userid' => $students[3]->id, 'extensionduedate' => $now + 20 * DAYSECS]);

        $cm = get_fast_modinfo($course->id)->cms[$a->cmid];
        $adapter = new assign();
        $cmrow = $adapter->cm_row($cm);
        $rows = $adapter->student_rows($cm, $cmrow, array_column($students, 'id'));

        $assign = new \assign(\context_module::instance($a->cmid), null, null);
        foreach ($students as $i => $s) {
            $assign->update_effective_access($s->id);
            $instance = $assign->get_instance($s->id);
            $flags = $assign->get_user_flags($s->id, false);
            $due = (int)$instance->duedate;
            $cutoff = (int)$instance->cutoffdate;
            if ($flags && $flags->extensionduedate > $due) {
                $due = (int)$flags->extensionduedate;
                $cutoff = max($cutoff, $due);
            }
            $this->assertSame($due, $rows[$s->id]->due, "deadline of student $i");
            $this->assertSame($cutoff ?: null, $rows[$s->id]->cutoff, "cut-off of student $i");
        }
        $this->assertSame($now + 20 * DAYSECS, $rows[$students[3]->id]->due);
        $this->assertTrue($rows[$students[0]->id]->individual);
    }

    /**
     * Submission, marking and release.
     */
    public function test_submission_marking_release(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $agen = $gen->get_plugin_generator('mod_assign');
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $s3 = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $a = $agen->create_instance(['course' => $course->id, 'duedate' => time() - DAYSECS,
            'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1]);
        $agen->create_submission(['userid' => $s1->id, 'cmid' => $a->cmid, 'onlinetext' => 'Mine']);
        $agen->create_submission(['userid' => $s2->id, 'cmid' => $a->cmid, 'onlinetext' => 'Mine too']);

        $this->setUser($teacher);
        $assign = new \assign(\context_module::instance($a->cmid), null, null);
        $grade = $assign->get_user_grade($s1->id, true);
        $grade->grade = 65;
        $grade->grader = $teacher->id;
        $grade->timemodified = time() + 1;
        $assign->update_grade($grade);

        $cm = get_fast_modinfo($course->id)->cms[$a->cmid];
        $adapter = new assign();
        $cmrow = $adapter->cm_row($cm);
        $userids = [$s1->id, $s2->id, $s3->id];
        $rows = $adapter->student_rows($cm, $cmrow, $userids);
        $this->assertTrue($rows[$s1->id]->submitted);
        $this->assertTrue($rows[$s2->id]->submitted);
        $this->assertFalse($rows[$s3->id]->submitted);
        $this->assertNotNull($rows[$s1->id]->timereleased);
        $this->assertNotEmpty($rows[$s1->id]->gradedisplay);
        $this->assertNull($rows[$s2->id]->timereleased);

        $marking = [];
        foreach ($adapter->marking_rows($cm, $cmrow, $userids) as $m) {
            $marking[$m->unitid] = $m;
        }
        $this->assertCount(2, $marking, 'only submitted work is a marking unit');
        $this->assertSame(marking_row::UNIT_USER, $marking[$s1->id]->unittype);
        $this->assertTrue($marking[$s1->id]->marked);
        $this->assertSame((int)$teacher->id, $marking[$s1->id]->markedby);
        $this->assertTrue($marking[$s1->id]->released);
        $this->assertFalse($marking[$s2->id]->marked);
        $this->assertFalse($cmrow->hasallocation);
    }

    /**
     * With marking workflow, release waits for the workflow; allocation needs both settings.
     */
    public function test_workflow_and_allocation(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $agen = $gen->get_plugin_generator('mod_assign');
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $marker = $gen->create_and_enrol($course, 'teacher');
        $a = $agen->create_instance(['course' => $course->id, 'duedate' => time() - DAYSECS, 'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1, 'markingworkflow' => 1, 'markingallocation' => 1]);
        $agen->create_submission(['userid' => $s1->id, 'cmid' => $a->cmid, 'onlinetext' => 'Mine']);
        $this->setAdminUser();
        $assign = new \assign(\context_module::instance($a->cmid), null, null);
        $flags = $assign->get_user_flags($s1->id, true);
        $flags->allocatedmarker = $marker->id;
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_INMARKING;
        $assign->update_user_flags($flags);

        $cm = get_fast_modinfo($course->id)->cms[$a->cmid];
        $adapter = new assign();
        $cmrow = $adapter->cm_row($cm);
        $this->assertTrue($cmrow->hasallocation);
        [$m] = $adapter->marking_rows($cm, $cmrow, [$s1->id]);
        $this->assertSame((int)$marker->id, $m->allocatedto);
        $this->assertFalse($m->marked);

        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE;
        $assign->update_user_flags($flags);
        [$m] = $adapter->marking_rows($cm, $cmrow, [$s1->id]);
        $this->assertTrue($m->marked);
        $this->assertFalse($m->released);
    }
}
