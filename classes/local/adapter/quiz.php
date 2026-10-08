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
 * (the latest close, with "no close" beating any date). The close is a hard
 * close, so it is also the cut-off. Attempt limits follow the same override
 * rules (no limit beats any number). Once a student has used every attempt and
 * none is still open, the cut-off is when the last one ended: the quiz can't be
 * attempted any more, so it goes to Past (Pierre, 2026-10-06: Upcoming and
 * Overdue hold only work that can still be submitted).
 *
 * Release follows quiz_grade_item_update(): marks shown later while open
 * release at once; shown after close only, at the close, and for each
 * student no earlier than their own close (F11). A quiz whose marks never
 * reach the gradebook (marks never shown, or after close with no close date,
 * or a maximum grade of 0) can't be released, so a fully marked attempt
 * counts as finished: Past as "Submitted", no grade claimed (F10).
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
    const INSTANCE_FIELDS = 'id, course, timeopen, timeclose, grademethod, attempts, grade, reviewmarks, reviewmaxmarks';

    /** Review times, as in mod_quiz\question\display_options. */
    const REVIEW_LATER_WHILE_OPEN = 0x00100;
    /** After the quiz closes. */
    const REVIEW_AFTER_CLOSE = 0x00010;

    #[\Override]
    public function cm_row(\cm_info $cm): ?cm_row {
        $q = $this->instance($cm);
        return new cm_row(
            opens: self::date($q->timeopen),
            due: self::date($q->timeclose),
            cutoff: self::date($q->timeclose),
            markingdue: null,
            hasallocation: false,
            stages: ['marked'],
            releases: self::release_mode($q) !== self::RELEASE_NEVER,
        );
    }

    /** Marks reach the gradebook while the quiz is open. */
    const RELEASE_OPEN = 'open';
    /** Marks reach the gradebook at the close. */
    const RELEASE_CLOSE = 'close';
    /** Marks never reach the gradebook. */
    const RELEASE_NEVER = 'never';

    /**
     * When the quiz's marks reach the gradebook, as quiz_grade_item_update() decides.
     *
     * @param \stdClass $q
     * @return string One of the RELEASE_ constants.
     */
    protected static function release_mode(\stdClass $q): string {
        $shown = fn(int $when) => ((int)$q->reviewmarks & (int)$q->reviewmaxmarks & $when) !== 0;
        if ((float)$q->grade <= 0) {
            return self::RELEASE_NEVER;
        }
        if ($shown(self::REVIEW_LATER_WHILE_OPEN)) {
            return self::RELEASE_OPEN;
        }
        return $shown(self::REVIEW_AFTER_CLOSE) && $q->timeclose ? self::RELEASE_CLOSE : self::RELEASE_NEVER;
    }

    #[\Override]
    public function student_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        $q = $this->instance($cm);
        $rows = self::default_rows($cmrow, $userids);
        if (!$rows) {
            return [];
        }

        [$closes, $limits] = self::overrides($cm, $q, array_keys($rows));
        foreach ($closes as $userid => $close) {
            $row = $rows[$userid];
            $row->due = self::date($close);
            $row->cutoff = $row->due;
            $row->individual = true;
        }
        self::close_used_up($q, $rows, $limits);

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
        $mode = self::release_mode($q);
        if ($mode === self::RELEASE_NEVER) {
            // Nothing will be released: a fully marked attempt is finished, with no grade to show (F10).
            foreach (self::units($q, array_keys($finished)) as $userid => $unit) {
                if ($unit->marked) {
                    $rows[$userid]->timereleased = $unit->timemarked ?: $unit->timeready;
                    $rows[$userid]->gradedisplay = null;
                }
            }
            return $rows;
        }
        self::apply_gradebook_release($cm, $rows);
        if ($mode === self::RELEASE_CLOSE) {
            // The gradebook shows marks at the quiz's close; the quiz page waits for the student's own (F11).
            foreach ($rows as $row) {
                if ($row->timereleased !== null && $row->due !== null && $row->due > $row->timereleased) {
                    $row->timereleased = $row->due;
                }
            }
        }
        return $rows;
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        $q = $this->instance($cm);
        $units = self::units($q, $userids);
        if (!$units) {
            return [];
        }
        $mode = self::release_mode($q);
        $released = $mode === self::RELEASE_NEVER ? [] : self::released_now($cm, array_keys($units));
        if ($mode === self::RELEASE_CLOSE) {
            $now = time();
            foreach (self::closes($cm, $q, array_keys($released)) as $userid => $close) {
                if ($close && $close > $now) {
                    unset($released[$userid]);
                }
            }
        }
        $rows = [];
        foreach ($units as $userid => $unit) {
            $rows[] = new marking_row(
                unitid: $userid,
                unittype: marking_row::UNIT_USER,
                stage: 'marked',
                allocatedto: null,
                submitted: true,
                marked: $unit->marked,
                markedby: $unit->marked ? $unit->markedby : null,
                timemarked: $unit->marked ? $unit->timemarked : null,
                // With nothing ever released, marked is as far as it goes (F10).
                released: $mode === self::RELEASE_NEVER ? $unit->marked : isset($released[$userid]),
                timeready: $unit->timeready ?: null,
            );
        }
        return $rows;
    }

    /**
     * Each student's finished attempts that count for the grade, summed up: whether all are marked,
     * when and by whom, and when the last was finished.
     *
     * @param \stdClass $q
     * @param int[] $userids
     * @return \stdClass[] userid => (object)[marked, markedby, timemarked, timeready]
     */
    protected static function units(\stdClass $q, array $userids): array {
        global $DB;
        $attempts = [];
        foreach (array_chunk(array_values($userids), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['quizid'] = $q->id;
            $rs = $DB->get_recordset_sql("SELECT id, uniqueid, userid, attempt, sumgrades, timefinish, timemodified
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
        $graders = self::graders(array_merge(...array_values($attempts)));
        $units = [];
        foreach ($attempts as $userid => $list) {
            // The attempts that count for the grade.
            if ((int)$q->grademethod === self::ATTEMPTFIRST) {
                $counted = [reset($list)];
            } else if ((int)$q->grademethod === self::ATTEMPTLAST) {
                $counted = [end($list)];
            } else {
                $counted = $list;
            }
            $unit = (object)['marked' => true, 'markedby' => null, 'timemarked' => 0, 'timeready' => 0];
            foreach ($counted as $a) {
                if ($a->sumgrades === null) {
                    $unit->marked = false;
                }
                $unit->timemarked = max($unit->timemarked, (int)$a->timemodified);
                $unit->timeready = max($unit->timeready, (int)$a->timefinish);
                $unit->markedby = $graders[(int)$a->uniqueid] ?? $unit->markedby;
            }
            $units[$userid] = $unit;
        }
        return $units;
    }

    /**
     * Each student's own close: an override's, or the quiz's.
     *
     * @param \cm_info $cm
     * @param \stdClass $q
     * @param int[] $userids
     * @return array userid => int|null
     */
    protected static function closes(\cm_info $cm, \stdClass $q, array $userids): array {
        [$closes] = self::overrides($cm, $q, $userids);
        $out = [];
        foreach ($userids as $userid) {
            $out[$userid] = array_key_exists($userid, $closes) ? $closes[$userid] : self::date($q->timeclose);
        }
        return $out;
    }

    /**
     * Overrides that apply to each student, as quiz_update_effective_access() resolves them.
     *
     * @param \cm_info $cm
     * @param \stdClass $q
     * @param int[] $userids
     * @return array [userid => close (0: none) for those overridden, userid => attempt limit (0: none)]
     */
    protected static function overrides(\cm_info $cm, \stdClass $q, array $userids): array {
        global $DB;
        $useroverrides = [];
        $groupcloses = [];
        $groupattempts = [];
        foreach ($DB->get_records('quiz_overrides', ['quiz' => $q->id]) as $o) {
            if ($o->userid) {
                $useroverrides[(int)$o->userid] = $o;
            } else if ($o->groupid) {
                if (isset($o->timeclose)) {
                    $groupcloses[(int)$o->groupid] = (int)$o->timeclose;
                }
                if (isset($o->attempts)) {
                    $groupattempts[(int)$o->groupid] = (int)$o->attempts;
                }
            }
        }
        $groups = ($groupcloses || $groupattempts) ? self::user_groups($cm->course, $userids) : [];
        $closes = [];
        $limits = [];
        foreach ($userids as $userid) {
            $mine = array_flip($groups[$userid] ?? []);
            $close = self::effective($useroverrides[$userid]->timeclose ?? null, array_intersect_key($groupcloses, $mine));
            if ($close !== null) {
                $closes[$userid] = $close;
            }
            $limit = self::effective($useroverrides[$userid]->attempts ?? null, array_intersect_key($groupattempts, $mine));
            $limits[$userid] = $limit ?? (int)$q->attempts;
        }
        return [$closes, $limits];
    }

    /**
     * A user override's value wins; otherwise the most lenient group value, where 0 (no close, no limit)
     * beats any number. Null when nothing overrides the quiz's own setting.
     *
     * @param mixed $user The user override's value, or null.
     * @param int[] $groups The values of the student's group overrides.
     * @return int|null
     */
    protected static function effective($user, array $groups): ?int {
        if ($user !== null) {
            return (int)$user;
        }
        if (!$groups) {
            return null;
        }
        return in_array(0, $groups, true) ? 0 : max($groups);
    }

    /**
     * Closes the quiz for students who have used every attempt and have none still open: their cut-off
     * becomes the end of their last attempt, or stays the close if that is earlier.
     *
     * @param \stdClass $q
     * @param student_row[] $rows
     * @param int[] $limits Attempts allowed, by userid; 0 means no limit.
     */
    protected static function close_used_up(\stdClass $q, array $rows, array $limits): void {
        global $DB;
        $limited = array_keys(array_filter($limits));
        if (!$limited) {
            return;
        }
        $used = self::chunked($limited, function ($chunk) use ($DB, $q) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['quizid'] = $q->id;
            return $DB->get_records_sql("SELECT userid, COUNT(1) AS attempts,
                                                SUM(CASE WHEN state IN ('inprogress', 'overdue') THEN 1 ELSE 0 END) AS open,
                                                MAX(CASE WHEN timefinish > 0 THEN timefinish ELSE timemodified END) AS lastend
                                           FROM {quiz_attempts}
                                          WHERE quiz = :quizid AND preview = 0 AND userid $insql
                                       GROUP BY userid", $params);
        });
        foreach ($used as $userid => $u) {
            if ((int)$u->attempts < $limits[$userid] || (int)$u->open > 0) {
                continue;
            }
            $row = $rows[$userid];
            // Closed from the moment the last attempt ended: a cut-off is inclusive, so one second before, or
            // the student's view in that same second still offers the quiz (MariaDB lane, beta18 and beta19).
            $end = (int)$u->lastend - 1;
            $row->cutoff = $row->cutoff === null ? $end : min((int)$row->cutoff, $end);
        }
    }

    /**
     * Who graded each attempt by hand: whoever saved its latest manual grade (F8). Attempts marked
     * automatically have none.
     *
     * @param \stdClass[] $attempts With uniqueid (the question usage).
     * @return int[] question usage id => user id
     */
    protected static function graders(array $attempts): array {
        global $DB;
        $out = [];
        foreach (array_chunk(array_map(fn($a) => (int)$a->uniqueid, $attempts), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'q');
            $rs = $DB->get_recordset_sql("SELECT qa.questionusageid, qas.userid
                                            FROM {question_attempts} qa
                                            JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                                           WHERE qa.questionusageid $insql AND " . $DB->sql_like('qas.state', ':mangr') . "
                                        ORDER BY qas.timecreated, qas.id", $params + ['mangr' => 'mangr%']);
            foreach ($rs as $r) {
                $out[(int)$r->questionusageid] = (int)$r->userid;
            }
            $rs->close();
        }
        return $out;
    }
}
