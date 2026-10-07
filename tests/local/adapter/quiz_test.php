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
        $this->assertSame($cmrow->due, $cmrow->cutoff, 'a quiz closes at its close date (F2)');
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

    /**
     * Inserts a finished attempt.
     *
     * @param int $quizid
     * @param int $userid
     * @param int $n
     * @param float|null $sum
     * @param string $state
     * @return int
     */
    protected static function attempt(int $quizid, int $userid, int $n, ?float $sum, string $state = 'finished'): int {
        global $DB;
        return $DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quizid, 'userid' => $userid, 'attempt' => $n, 'uniqueid' => 980000 + $userid * 10 + $n,
            'layout' => '', 'currentpage' => 0, 'preview' => 0, 'state' => $state, 'timestart' => time() - 100,
            'timefinish' => $state === 'finished' ? time() - 50 : 0, 'timemodified' => time() - 50, 'sumgrades' => $sum]);
    }

    /**
     * Once every attempt is used and none is open, the quiz is closed for that student: the cut-off is
     * when their last attempt ended. Overrides change the limit as quiz does.
     */
    public function test_attempts_used_up_close_the_quiz(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $s3 = $gen->create_and_enrol($course, 'student');
        $s4 = $gen->create_and_enrol($course, 'student');
        $close = time() + 5 * DAYSECS;
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => $close, 'attempts' => 1]);
        self::attempt($quiz->id, $s1->id, 1, 1.0);
        self::attempt($quiz->id, $s2->id, 1, 1.0);
        self::attempt($quiz->id, $s3->id, 1, null, 'inprogress');
        // A second attempt for s2.
        $DB->insert_record('quiz_overrides', (object)['quiz' => $quiz->id, 'userid' => $s2->id, 'attempts' => 2]);

        $cm = get_fast_modinfo($course->id)->cms[$quiz->cmid];
        $adapter = new quiz();
        $cmrow = $adapter->cm_row($cm);
        $rows = $adapter->student_rows($cm, $cmrow, [$s1->id, $s2->id, $s3->id, $s4->id]);
        $this->assertLessThan(time(), $rows[$s1->id]->cutoff, 'every attempt used');
        $this->assertSame($close, $rows[$s1->id]->due, 'the deadline stays');
        $this->assertSame($close, $rows[$s2->id]->cutoff, 'an override allows another');
        $this->assertSame($close, $rows[$s3->id]->cutoff, 'an attempt still open');
        $this->assertSame($close, $rows[$s4->id]->cutoff, 'nothing tried yet');
    }

    /**
     * F10: marks never shown, or a maximum grade of 0, release nothing, so a marked attempt counts as finished.
     * F11: marks shown after close only release each student at their own close.
     */
    public function test_release_follows_review_options(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => time() - HOURSECS, 'grade' => 10]);
        self::attempt($quiz->id, $s1->id, 1, 1.0);
        self::attempt($quiz->id, $s2->id, 1, null);
        $cm = get_fast_modinfo($course->id)->cms[$quiz->cmid];
        $adapter = new quiz();
        $set = function (array $fields) use ($DB, $quiz) {
            $DB->update_record('quiz', ['id' => $quiz->id] + $fields);
            $record = $DB->get_record('quiz', ['id' => $quiz->id]);
            $record->cmidnumber = '';
            quiz_grade_item_update($record);
        };

        // Marks never shown.
        $set(['reviewmarks' => 0]);
        $cmrow = $adapter->cm_row($cm);
        $this->assertFalse($cmrow->releases);
        $rows = $adapter->student_rows($cm, $cmrow, [$s1->id, $s2->id]);
        $this->assertNotNull($rows[$s1->id]->timereleased, 'marked counts as finished');
        $this->assertNull($rows[$s1->id]->gradedisplay, 'with no grade claimed');
        $this->assertNull($rows[$s2->id]->timereleased, 'still needs grading');
        $units = [];
        foreach ($adapter->marking_rows($cm, $cmrow, [$s1->id, $s2->id]) as $m) {
            $units[$m->unitid] = $m;
        }
        $this->assertTrue($units[$s1->id]->released);
        $this->assertFalse($units[$s2->id]->released);

        // A maximum grade of 0.
        $set(['reviewmarks' => 0x11110, 'grade' => 0]);
        $this->assertFalse($adapter->cm_row($cm)->releases);

        // Marks after close only: the quiz closed an hour ago, s1's override closes tomorrow.
        $set(['reviewmarks' => 0x00010, 'grade' => 10]);
        $cmrow = $adapter->cm_row($cm);
        $this->assertTrue($cmrow->releases);
        $DB->insert_record('quiz_overrides', (object)['quiz' => $quiz->id, 'userid' => $s1->id,
            'timeclose' => time() + DAYSECS]);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'itemnumber' => 0, 'courseid' => $course->id]);
        $item->update_final_grade($s1->id, 7, 'test');
        $item->update_final_grade($s2->id, 6, 'test');
        $rows = $adapter->student_rows($cm, $cmrow, [$s1->id, $s2->id]);
        $this->assertGreaterThan(time(), $rows[$s1->id]->timereleased, 'released at their own close');
        $this->assertLessThanOrEqual(time(), $rows[$s2->id]->timereleased);
        foreach ($adapter->marking_rows($cm, $cmrow, [$s1->id]) as $m) {
            $this->assertFalse($m->released, 'not released before their own close');
        }
    }

    /**
     * F8: whoever saved the last manual grade on an attempt marked it.
     */
    public function test_marker_from_manual_grading(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $s1 = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'timeclose' => time() - DAYSECS]);
        $id = self::attempt($quiz->id, $s1->id, 1, 1.0);
        $uniqueid = (int)$DB->get_field('quiz_attempts', 'uniqueid', ['id' => $id]);
        $qa = $DB->insert_record('question_attempts', (object)['questionusageid' => $uniqueid, 'slot' => 1,
            'behaviour' => 'manualgraded', 'questionid' => 1, 'variant' => 1, 'maxmark' => 1, 'minfraction' => 0,
            'maxfraction' => 1, 'flagged' => 0, 'questionsummary' => '', 'rightanswer' => '', 'responsesummary' => '',
            'timemodified' => time()]);
        $DB->insert_record('question_attempt_steps', (object)['questionattemptid' => $qa, 'sequencenumber' => 0,
            'state' => 'needsgrading', 'fraction' => null, 'timecreated' => time() - 60, 'userid' => $s1->id]);
        $DB->insert_record('question_attempt_steps', (object)['questionattemptid' => $qa, 'sequencenumber' => 1,
            'state' => 'mangrright', 'fraction' => 1, 'timecreated' => time(), 'userid' => $teacher->id]);

        $cm = get_fast_modinfo($course->id)->cms[$quiz->cmid];
        $adapter = new quiz();
        [$m] = $adapter->marking_rows($cm, $adapter->cm_row($cm), [$s1->id]);
        $this->assertSame((int)$teacher->id, $m->markedby);
    }
}
