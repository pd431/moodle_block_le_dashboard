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

namespace block_le_dashboard\local\adapter;

/**
 * The quiz adapter agrees with quiz's own rules.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\adapter\quiz
 */
final class quiz_test extends \advanced_testcase {
    /**
     * Effective close dates match quiz_update_effective_access().
     */
    public function test_effective_dates_match_quiz(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $students[$i] = $gen->create_and_enrol($course, 'student');
        }
        $g1 = $gen->create_group(['courseid' => $course->id]);
        $g2 = $gen->create_group(['courseid' => $course->id]);
        foreach ([1, 2, 3] as $i) {
            $gen->create_group_member(['groupid' => $g1->id, 'userid' => $students[$i]->id]);
        }
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $students[2]->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $students[4]->id]);

        $now = time();
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => $now + 5 * DAYSECS]);
        $add = fn(array $o) => $DB->insert_record('quiz_overrides', (object)($o + ['quiz' => $quiz->id]));
        // User override with a later close.
        $add(['userid' => $students[0]->id, 'timeclose' => $now + 10 * DAYSECS]);
        // User override that leaves the close date alone: the group's applies (per setting).
        $add(['userid' => $students[3]->id, 'timelimit' => 600]);
        // Group overrides: the most lenient close wins; 0 means no close.
        $add(['groupid' => $g1->id, 'timeclose' => $now + 8 * DAYSECS]);
        $add(['groupid' => $g2->id, 'timeclose' => $now + 12 * DAYSECS]);

        $cm = get_fast_modinfo($course->id)->cms[$quiz->cmid];
        $adapter = new quiz();
        $cmrow = $adapter->cm_row($cm);
        $this->assertNull($cmrow->cutoff, 'quizzes have no cut-off: overdue until the threshold');
        $rows = $adapter->student_rows($cm, $cmrow, array_column($students, 'id'));
        $record = $DB->get_record('quiz', ['id' => $quiz->id]);
        foreach ($students as $i => $s) {
            $effective = quiz_update_effective_access(clone $record, $s->id);
            $this->assertSame((int)$effective->timeclose ?: null, $rows[$s->id]->due, "close date of student $i");
        }
    }

    /**
     * What counts as marked follows the grading method.
     */
    public function test_marking_by_grademethod(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => time() - DAYSECS,
            'grademethod' => QUIZ_ATTEMPTLAST]);
        $attempt = fn(int $n, ?float $sum) => $DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id, 'userid' => $s1->id, 'attempt' => $n, 'uniqueid' => 990000 + $n, 'layout' => '',
            'currentpage' => 0, 'preview' => 0, 'state' => 'finished', 'timestart' => time() - 100,
            'timefinish' => time() - 50, 'timemodified' => time() - 50, 'sumgrades' => $sum]);
        // First attempt needs grading; the last one is graded.
        $attempt(1, null);
        $attempt(2, 5.0);

        $cm = get_fast_modinfo($course->id)->cms[$quiz->cmid];
        $adapter = new quiz();
        $cmrow = $adapter->cm_row($cm);
        [$m] = $adapter->marking_rows($cm, $cmrow, [$s1->id]);
        $this->assertTrue($m->marked, 'last attempt counts');

        $DB->set_field('quiz', 'grademethod', QUIZ_GRADEHIGHEST, ['id' => $quiz->id]);
        [$m] = $adapter->marking_rows($cm, $cmrow, [$s1->id]);
        $this->assertFalse($m->marked, 'every attempt counts, one needs grading');

        $rows = $adapter->student_rows($cm, $cmrow, [$s1->id]);
        $this->assertTrue($rows[$s1->id]->submitted);
    }
}
