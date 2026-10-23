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

namespace block_le_dashboard\fixture;

/**
 * Writes quizzes the way their users do: one essay (or short answer) question, finished and abandoned attempts,
 * manual grading of the essay, review options, overrides.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz extends base {
    #[\Override]
    public function modname(): string {
        return 'quiz';
    }

    #[\Override]
    protected function due_field(): string {
        return 'timeclose';
    }

    #[\Override]
    protected function opens_field(): ?string {
        return 'timeopen';
    }

    #[\Override]
    protected function record(array $record, ?int $due, string $opts): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $methods = ['first attempt' => QUIZ_ATTEMPTFIRST, 'last attempt' => QUIZ_ATTEMPTLAST,
            'highest grade' => QUIZ_GRADEHIGHEST];
        $record += ['timeclose' => (int)$due, 'sumgrades' => 1];
        if (preg_match('/grading method "([^"]*)"/', $opts, $m)) {
            $record['grademethod'] = $methods[strtolower($m[1])];
        }
        if (preg_match('/(\d+) attempts? allowed/', $opts, $m)) {
            $record['attempts'] = (int)$m[1];
        }
        return $record;
    }

    #[\Override]
    protected function after_create(\stdClass $instance, string $opts): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        // With "no essay question", a short answer, marked automatically when the attempt is finished.
        $qgen = self::plugin_gen('core_question');
        $cat = $qgen->create_question_category(['contextid' => \context_module::instance($instance->cmid)->id]);
        $question = $qgen->create_question(
            str_contains($opts, 'no essay question') ? 'shortanswer' : 'essay',
            null,
            ['category' => $cat->id]
        );
        quiz_add_quiz_question($question->id, $instance);
        $review = ['marks never shown' => 0, 'marks shown immediately after only' => 0x11000,
            'marks shown after close only' => 0x00010];
        foreach ($review as $phrase => $marks) {
            if (str_contains($opts, $phrase)) {
                self::update((int)$instance->id, ['reviewmarks' => $marks]);
            }
        }
        if (str_contains($opts, 'a maximum grade of 0')) {
            self::update((int)$instance->id, ['grade' => 0]);
        }
    }

    #[\Override]
    public function submit(\cm_info $cm, int $userid, ?int $time = null): void {
        if ($time !== null) {
            $this->unsupported('Dating the attempts at');
        }
        $this->attempts($cm, $userid, 1);
    }

    #[\Override]
    public function submitters(\cm_info $cm): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'quiz_attempts',
            'DISTINCT userid',
            "quiz = :id AND state = 'finished'",
            ['id' => $cm->instance]
        ));
    }

    #[\Override]
    public function attempts(\cm_info $cm, int $userid, int $count): void {
        $qgen = self::plugin_gen('mod_quiz');
        self::as_user($userid, function () use ($qgen, $cm, $userid, $count) {
            for ($i = 0; $i < $count; $i++) {
                $attempt = $qgen->create_attempt($cm->instance, $userid);
                $qgen->submit_responses($attempt->id, [1 => 'My answer'], false, true);
            }
        });
        self::queue($cm);
    }

    #[\Override]
    public function abandon_attempt(\cm_info $cm, int $userid): void {
        global $DB;
        $qgen = self::plugin_gen('mod_quiz');
        $attempt = null;
        self::as_user($userid, function () use ($qgen, $cm, $userid, &$attempt) {
            $attempt = $qgen->create_attempt($cm->instance, $userid);
        });
        $DB->update_record('quiz_attempts', (object)['id' => $attempt->id, 'state' => 'abandoned',
            'timefinish' => 0, 'timemodified' => time() - 60]);
        self::queue($cm);
    }

    #[\Override]
    public function grade_attempt(\cm_info $cm, int $userid, string $which, ?int $markerid = null): void {
        global $DB;
        $attempt = self::attempt($cm, $userid, $which);
        $grade = function () use ($DB, $cm, $attempt) {
            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            $quba->manual_grade(1, 'Good', 1, FORMAT_HTML);
            \question_engine::save_questions_usage_by_activity($quba);
            $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $attempt->id]);
            // Writes the student's quiz grade to the gradebook, as finishing a manual grade does.
            \mod_quiz\quiz_settings::create((int)$cm->instance)->get_grade_calculator()
                ->recompute_final_grade((int)$attempt->userid);
        };
        // As the marker, so the question engine records who graded it.
        $markerid === null ? $grade() : self::as_user($markerid, $grade);
        self::queue($cm);
    }

    #[\Override]
    public function attempt_ungraded(\cm_info $cm, int $userid, string $which): bool {
        return self::attempt($cm, $userid, $which)->sumgrades === null;
    }

    #[\Override]
    public function hide_marks(\cm_info $cm): void {
        self::update((int)$cm->instance, ['reviewmarks' => 0]);
        self::queue($cm);
    }

    #[\Override]
    public function override(\cm_info $cm, int $userid, int $due): void {
        global $DB;
        $DB->insert_record('quiz_overrides', ['quiz' => $cm->instance, 'userid' => $userid, 'timeclose' => $due]);
        self::queue($cm);
    }

    #[\Override]
    public function time_fields(): array {
        return ['quiz' => ['timeclose']];
    }

    /**
     * A student's first or last attempt.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param string $which
     * @return \stdClass
     */
    private static function attempt(\cm_info $cm, int $userid, string $which): \stdClass {
        global $DB;
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $cm->instance, 'userid' => $userid], 'attempt');
        if (!$attempts) {
            throw new \coding_exception("User $userid has no attempt at '{$cm->name}'");
        }
        return $which === 'first' ? reset($attempts) : end($attempts);
    }

    /**
     * Changes quiz settings in the database and updates its grade item, as saving the form would.
     *
     * @param int $quizid
     * @param array $fields
     */
    private static function update(int $quizid, array $fields): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $DB->update_record('quiz', ['id' => $quizid] + $fields);
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        $quiz->cmidnumber = '';
        quiz_grade_item_update($quiz);
    }
}
