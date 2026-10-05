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

use block_le_dashboard\local\index\cm_row;
use block_le_dashboard\local\index\marking_row;
use block_le_dashboard\local\index\student_row;

/**
 * Adapter for mod_quiz.
 *
 * Close dates follow quiz_update_effective_access(): a user override's
 * value wins; otherwise the most lenient of the student's group overrides
 * (the latest close, with "no close" beating any date). A quiz past its
 * close date is overdue, not past: the student can still act (a late
 * request), so the quiz has no separate cut-off.
 *
 * Needs marking (P9, D8): a finished attempt that counts for the grade
 * under the quiz's grading method and has no total yet (sumgrades IS NULL).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz extends base {
    /** Grading methods, as in mod/quiz/lib.php. */
    const GRADEHIGHEST = 1;
    /** Average of attempts. */
    const GRADEAVERAGE = 2;
    /** First attempt. */
    const ATTEMPTFIRST = 3;
    /** Last attempt. */
    const ATTEMPTLAST = 4;

    #[\Override]
    public function modname(): string {
        return 'quiz';
    }

    #[\Override]
    public static function events(): array {
        return [
            '\mod_quiz\event\attempt_submitted',
            '\mod_quiz\event\attempt_deleted',
            '\mod_quiz\event\attempt_regraded',
            '\mod_quiz\event\attempt_reopened',
            '\mod_quiz\event\attempt_manual_grading_completed',
            '\mod_quiz\event\question_manually_graded',
            '\mod_quiz\event\quiz_grade_updated',
            '\mod_quiz\event\user_override_created',
            '\mod_quiz\event\user_override_updated',
            '\mod_quiz\event\user_override_deleted',
            '\mod_quiz\event\group_override_created',
            '\mod_quiz\event\group_override_updated',
            '\mod_quiz\event\group_override_deleted',
        ];
    }

    /** Only the columns this adapter reads. */
    const INSTANCE_FIELDS = 'id, course, timeopen, timeclose, grademethod';

    #[\Override]
    public function cm_row(\cm_info $cm): ?cm_row {
        $q = $this->instance($cm);
        return new cm_row(
            opens: self::date($q->timeopen),
            due: self::date($q->timeclose),
            cutoff: null,
            markingdue: null,
            hasallocation: false,
            stages: ['marked'],
        );
    }

    #[\Override]
    public function student_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        $q = $this->instance($cm);
        $rows = self::default_rows($cmrow, $userids);
        if (!$rows) {
            return [];
        }

        $overrides = $DB->get_records('quiz_overrides', ['quiz' => $q->id]);
        $useroverrides = [];
        $groupoverrides = [];
        foreach ($overrides as $o) {
            if ($o->userid) {
                $useroverrides[(int)$o->userid] = $o;
            } else if ($o->groupid && isset($o->timeclose)) {
                $groupoverrides[(int)$o->groupid] = (int)$o->timeclose;
            }
        }
        $groups = $groupoverrides ? self::user_groups($cm->course, array_keys($rows)) : [];
        foreach ($rows as $userid => $row) {
            $close = null;
            if (isset($useroverrides[$userid]) && isset($useroverrides[$userid]->timeclose)) {
                $close = (int)$useroverrides[$userid]->timeclose;
            } else {
                $closes = array_intersect_key($groupoverrides, array_flip($groups[$userid] ?? []));
                if ($closes) {
                    $close = in_array(0, $closes, true) ? 0 : max($closes);
                }
            }
            if ($close !== null) {
                $row->due = self::date($close);
                $row->individual = true;
            }
        }

        $finished = self::chunked(array_keys($rows), function ($chunk) use ($DB, $q) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['quizid'] = $q->id;
            return $DB->get_records_sql("SELECT userid, MAX(timefinish) AS timefinish
                                           FROM {quiz_attempts}
                                          WHERE quiz = :quizid AND state = 'finished' AND preview = 0 AND userid $insql
                                       GROUP BY userid", $params);
        });
        foreach ($finished as $userid => $f) {
            $rows[$userid]->submitted = true;
            $rows[$userid]->timesubmitted = (int)$f->timefinish;
        }
        self::apply_gradebook_release($cm, $rows);
        return $rows;
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        $q = $this->instance($cm);
        $attempts = [];
        foreach (array_chunk(array_values($userids), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['quizid'] = $q->id;
            $rs = $DB->get_recordset_sql("SELECT id, userid, attempt, sumgrades, timefinish, timemodified
                                            FROM {quiz_attempts}
                                           WHERE quiz = :quizid AND state = 'finished' AND preview = 0 AND userid $insql
                                        ORDER BY userid, attempt", $params);
            foreach ($rs as $a) {
                $attempts[(int)$a->userid][] = $a;
            }
            $rs->close();
        }
        if (!$attempts) {
            return [];
        }
        $released = self::released_now($cm, array_keys($attempts));

        $rows = [];
        foreach ($attempts as $userid => $list) {
            // The attempts that count for the grade.
            if ((int)$q->grademethod === self::ATTEMPTFIRST) {
                $counted = [reset($list)];
            } else if ((int)$q->grademethod === self::ATTEMPTLAST) {
                $counted = [end($list)];
            } else {
                $counted = $list;
            }
            $marked = true;
            $timemarked = 0;
            $timeready = 0;
            foreach ($counted as $a) {
                if ($a->sumgrades === null) {
                    $marked = false;
                }
                $timemarked = max($timemarked, (int)$a->timemodified);
                $timeready = max($timeready, (int)$a->timefinish);
            }
            $rows[] = new marking_row(
                unitid: $userid,
                unittype: marking_row::UNIT_USER,
                stage: 'marked',
                allocatedto: null,
                submitted: true,
                marked: $marked,
                markedby: null,
                timemarked: $marked ? $timemarked : null,
                released: isset($released[$userid]),
                timeready: $timeready ?: null,
            );
        }
        return $rows;
    }
}
