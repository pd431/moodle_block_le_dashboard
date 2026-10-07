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
 * Adapter for mod_coursework. Optional: the block works without coursework installed.
 *
 * Every coursework-specific rule lives in this file. It reads coursework's
 * tables directly because coursework's models are heavy per row, and it
 * tolerates both schemas seen so far:
 * - the ULCC 2022-era plugin the site runs today (stage_identifier,
 *   personal_deadline, coursework_mitigations with type 'extension');
 * - the UCL 5.0 plugin (stageidentifier, personaldeadline, coursework_extensions).
 *
 * Rules (design-v2 decisions):
 * - D2: a personal deadline replaces the default deadline, even when earlier;
 *   an extension can only move it later.
 * - Stages are coursework's own: assessor_1..n, final_agreed_1 when there is
 *   more than one marker, and moderator when moderation is on.
 * - D4: released means the agreed or final grade has been published to the
 *   student (submission lastpublished), dated by publish time. Individual
 *   markers' feedback never counts.
 * - A stage after the first is "ready" (marking_row::$submitted) only when
 *   the stages it depends on are marked: agreement after every assessor,
 *   moderation after the final grade.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursework extends base {
    /** Coursework's identifier for the first marker's stage. */
    const STAGE_FIRST = 'assessor_1';

    /** Coursework's identifier for the agreed grade stage. */
    const STAGE_AGREED = 'final_agreed_1';

    /** Coursework's identifier for the moderation stage. */
    const STAGE_MODERATOR = 'moderator';

    /** @var array Column-name cache per table. */
    protected static array $columns = [];

    #[\Override]
    public function modname(): string {
        return 'coursework';
    }

    #[\Override]
    public static function events(): array {
        return [
            '\mod_coursework\event\assessable_submitted',
            '\mod_coursework\event\coursework_deadline_changed',
            '\mod_coursework\event\extension_created',
            '\mod_coursework\event\extension_updated',
            '\mod_coursework\event\extension_deleted',
            '\mod_coursework\event\personaldeadline_created',
            '\mod_coursework\event\personaldeadline_updated',
            '\mod_coursework\event\feedback_changed',
        ];
    }

    /**
     * The first of the candidate columns that exists in the table, or null.
     *
     * @param string $table
     * @param string[] $candidates
     * @return string|null
     */
    protected static function column(string $table, array $candidates): ?string {
        global $DB;
        $dbman = $DB->get_manager();
        if (!isset(self::$columns[$table])) {
            self::$columns[$table] = $dbman->table_exists($table) ? array_keys($DB->get_columns($table)) : [];
        }
        foreach ($candidates as $c) {
            if (in_array($c, self::$columns[$table])) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Forgets cached column names (tests install and remove tables).
     */
    public static function reset_caches(): void {
        self::$columns = [];
    }

    /**
     * Coursework's marking stage identifiers mapped to the block's stage keys, in order.
     *
     * @param \stdClass $cw
     * @return string[] block stage key => coursework stage identifier
     */
    public static function stage_map(\stdClass $cw): array {
        $markers = max(1, (int)$cw->numberofmarkers);
        $map = [];
        if ($markers === 1) {
            $map['marked'] = self::STAGE_FIRST;
        } else {
            for ($i = 1; $i <= $markers; $i++) {
                $map['marker' . $i] = 'assessor_' . $i;
            }
            $map['agreed'] = self::STAGE_AGREED;
        }
        if (!empty($cw->moderationagreementenabled) || !empty($cw->moderationenabled)) {
            $map['moderated'] = self::STAGE_MODERATOR;
        }
        return $map;
    }

    #[\Override]
    public function cm_row(\cm_info $cm): ?cm_row {
        $cw = $this->instance($cm);
        $due = self::date($cw->deadline);
        $markingdue = null;
        if (!empty($cw->markingdeadlineenabled)) {
            $markingdue = self::date($cw->initialmarkingdeadline ?? null) ?? self::date($cw->agreedgrademarkingdeadline ?? null);
        }
        return new cm_row(
            opens: self::date($cw->startdate ?? null),
            due: $due,
            cutoff: (isset($cw->allowlatesubmissions) && empty($cw->allowlatesubmissions)) ? $due : null,
            markingdue: $markingdue,
            hasallocation: !empty($cw->allocationenabled),
            stages: array_keys(self::stage_map($cw)),
            submissions: !self::by_attendance($cw),
        );
    }

    /** Submission type values meaning students attend rather than hand work in online. */
    const ATTENDANCE_TYPES = ['attendance'];

    /**
     * Whether the coursework is assessed by attendance: nothing is handed in online. Versions name the
     * setting differently, so any of these columns counts (unverified without the plugin: Pierre tests).
     *
     * @param \stdClass $cw
     * @return bool
     */
    protected static function by_attendance(\stdClass $cw): bool {
        foreach (['submissiontype', 'submissionmethod'] as $field) {
            if (isset($cw->$field) && in_array(\core_text::strtolower((string)$cw->$field), self::ATTENDANCE_TYPES)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Each student's allocatable: themselves, or their group when the coursework uses groups.
     *
     * @param \cm_info $cm
     * @param \stdClass $cw
     * @param int[] $userids
     * @return array userid => [type, id]
     */
    protected function allocatables(\cm_info $cm, \stdClass $cw, array $userids): array {
        $out = [];
        if (!empty($cw->usegroups)) {
            $groups = self::user_groups($cm->course, $userids, (int)($cw->grouping_id ?? 0));
            foreach ($userids as $userid) {
                if (!empty($groups[$userid])) {
                    $out[$userid] = ['group', (int)reset($groups[$userid])];
                }
            }
            return $out;
        }
        foreach ($userids as $userid) {
            $out[$userid] = ['user', (int)$userid];
        }
        return $out;
    }

    /**
     * Rows of a per-allocatable table for this coursework, keyed "type:id".
     *
     * @param string $table
     * @param int $courseworkid
     * @param string $fields
     * @param string $where Extra condition.
     * @return \stdClass[]
     */
    protected static function by_allocatable(string $table, int $courseworkid, string $fields, string $where = ''): array {
        global $DB;
        $rs = $DB->get_recordset_sql("SELECT id, allocatableid, allocatabletype, $fields
                                        FROM {{$table}}
                                       WHERE courseworkid = :cwid $where
                                    ORDER BY id", ['cwid' => $courseworkid]);
        $out = [];
        foreach ($rs as $r) {
            $out[$r->allocatabletype . ':' . $r->allocatableid] = $r;
        }
        $rs->close();
        return $out;
    }

    /**
     * Extensions by allocatable, from whichever table this coursework version has.
     *
     * @param int $courseworkid
     * @return array[] "type:id" => [extended deadline, whether it was self-certified]
     */
    protected static function extensions(int $courseworkid): array {
        $out = [];
        $add = function (string $key, \stdClass $r) use (&$out) {
            $due = (int)$r->extended_deadline;
            if ($due > ($out[$key][0] ?? 0)) {
                $out[$key] = [$due, !empty($r->selfcert)];
            }
        };
        if (self::column('coursework_mitigations', ['extended_deadline'])) {
            $type = self::column('coursework_mitigations', ['type']) ? "AND type = 'extension'" : '';
            $fields = 'extended_deadline' . (self::column('coursework_mitigations', ['selfcert']) ? ', selfcert' : '');
            foreach (self::by_allocatable('coursework_mitigations', $courseworkid, $fields, $type) as $k => $r) {
                $add($k, $r);
            }
        }
        if (self::column('coursework_extensions', ['extended_deadline'])) {
            foreach (self::by_allocatable('coursework_extensions', $courseworkid, 'extended_deadline') as $k => $r) {
                $add($k, $r);
            }
        }
        return $out;
    }

    /**
     * Submitted submissions by allocatable.
     *
     * @param int $courseworkid
     * @return \stdClass[] "type:id" => submission
     */
    protected static function submissions(int $courseworkid): array {
        // A draft isn't handed in until it is finalised, by the student or at the deadline (A8).
        $finalised = self::column('coursework_submissions', ['finalisedstatus', 'finalised']);
        return self::by_allocatable(
            'coursework_submissions',
            $courseworkid,
            'timesubmitted, firstpublished, lastpublished',
            'AND timesubmitted > 0' . ($finalised ? " AND $finalised = 1" : '')
        );
    }

    /**
     * Whether feedback counts as marking done: a grade that isn't blank, and final where this
     * coursework version marks drafts (A8).
     *
     * @param \stdClass|null $f Feedback, with grade and, if the version has it, finalised.
     * @return bool
     */
    protected static function marked(?\stdClass $f): bool {
        return $f !== null && $f->grade !== null && trim((string)$f->grade) !== ''
            && (!property_exists($f, 'finalised') || !empty($f->finalised));
    }

    #[\Override]
    public function student_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        $cw = $this->instance($cm);
        $rows = self::default_rows($cmrow, $userids);
        $allocatables = $this->allocatables($cm, $cw, array_keys($rows));

        $pdcol = self::column('coursework_person_deadlines', ['personal_deadline', 'personaldeadline']);
        $personal = $pdcol ? self::by_allocatable('coursework_person_deadlines', $cw->id, $pdcol) : [];
        $extensions = self::extensions($cw->id);
        $submissions = self::submissions($cw->id);
        $finalgrades = $this->final_grades($cw);
        $pairs = $cmrow->hasallocation ? self::allocation_pairs((int)$cw->id) : [];
        $flagged = self::flagged_submissions((int)$cw->id);

        foreach ($rows as $userid => $row) {
            if (!isset($allocatables[$userid])) {
                continue;
            }
            $key = implode(':', $allocatables[$userid]);
            $row->allocatedto = array_values(array_unique($pairs[$key] ?? []));
            // D2: a personal deadline replaces the default, earlier or later.
            if (isset($personal[$key]) && !empty($personal[$key]->$pdcol)) {
                $row->due = (int)$personal[$key]->$pdcol;
                if ($row->cutoff !== null) {
                    $row->cutoff = $row->due;
                }
                $row->individual = true;
            }
            // An extension can only move it later.
            if (!empty($extensions[$key]) && $extensions[$key][0] > (int)$row->due) {
                [$row->due, $selfcert] = $extensions[$key];
                if ($row->cutoff !== null) {
                    $row->cutoff = $row->due;
                }
                $row->individual = true;
                $row->mitigation = $selfcert ? student_row::MITIGATION_SELFCERT : student_row::MITIGATION_EXTENSION;
            }
            if (isset($submissions[$key])) {
                $s = $submissions[$key];
                $row->submitted = true;
                $row->timesubmitted = (int)$s->timesubmitted;
                // Only finalised work counts as submitted, where the version records it, and that is final (A8).
                $row->locked = self::column('coursework_submissions', ['finalisedstatus', 'finalised']) !== null;
                // D4: released once the final grade is published, dated by publish time.
                if (!empty($s->lastpublished)) {
                    $row->timereleased = (int)($s->firstpublished ?: $s->lastpublished);
                    // Coursework keeps the grade as text, which can be empty: show only a number.
                    if (is_numeric($finalgrades[$s->id] ?? null) && (float)$cw->grade > 0) {
                        $row->gradedisplay = get_string('gradeoutof', 'block_le_dashboard', [
                            'grade' => format_float((float)$finalgrades[$s->id], 0),
                            'max' => format_float((float)$cw->grade, 0),
                        ]);
                    }
                }
            }
        }
        // The gradebook's own display, where coursework has published there.
        foreach (self::gradebook_release($cm, array_keys($rows)) as $userid => $release) {
            if ($rows[$userid]->timereleased) {
                $rows[$userid]->gradedisplay = $release->display;
            }
        }
        return $rows;
    }

    /**
     * Allocated assessors by allocatable and stage.
     *
     * @param int $courseworkid
     * @return array "type:id" => [stage => assessorid]
     */
    protected static function allocation_pairs(int $courseworkid): array {
        global $DB;
        $stagecol = self::column('coursework_allocation_pairs', ['stageidentifier', 'stage_identifier']);
        if (!$stagecol) {
            return [];
        }
        $pairs = [];
        $rs = $DB->get_recordset_sql("SELECT id, allocatableid, allocatabletype, assessorid, $stagecol AS stage
                                        FROM {coursework_allocation_pairs}
                                       WHERE courseworkid = :cwid", ['cwid' => $courseworkid]);
        foreach ($rs as $p) {
            $pairs[$p->allocatabletype . ':' . $p->allocatableid][$p->stage] = (int)$p->assessorid;
        }
        $rs->close();
        return $pairs;
    }

    /**
     * Plagiarism flag statuses that mean a case is open. rc6 counted 0-3; 2 is "cleared", so it isn't
     * one (Pierre, 2026-10-07, as in his rc7 commit). B42: the others are still inferred from rc6.
     */
    const OPEN_FLAG_STATUSES = ['0', '1', '3'];

    /**
     * Submissions with an open plagiarism (academic conduct) flag, from mod_coursework's own table.
     * Without the table there are none.
     *
     * @param int $courseworkid
     * @return array submission id => true
     */
    protected static function flagged_submissions(int $courseworkid): array {
        global $DB;
        if (!self::column('coursework_plagiarism_flags', ['submissionid'])) {
            return [];
        }
        [$statussql, $params] = $DB->get_in_or_equal(self::OPEN_FLAG_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params['cwid'] = $courseworkid;
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT submissionid
                                        FROM {coursework_plagiarism_flags}
                                       WHERE courseworkid = :cwid AND submissionid > 0 AND status IS NOT NULL
                                             AND " . $DB->sql_compare_text('status') . " $statussql", $params);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Final grade per submission: the agreed grade, or the only marker's grade.
     *
     * @param \stdClass $cw
     * @return float[] submissionid => grade
     */
    protected function final_grades(\stdClass $cw): array {
        global $DB;
        $stagecol = self::column('coursework_feedbacks', ['stageidentifier', 'stage_identifier']);
        if (!$stagecol) {
            return [];
        }
        $final = (int)$cw->numberofmarkers > 1 ? self::STAGE_AGREED : self::STAGE_FIRST;
        return $DB->get_records_sql_menu(
            "SELECT f.submissionid, f.grade
                                            FROM {coursework_feedbacks} f
                                            JOIN {coursework_submissions} s ON s.id = f.submissionid
                                           WHERE s.courseworkid = :cwid AND f.$stagecol = :stage AND f.grade IS NOT NULL",
            ['cwid' => $cw->id, 'stage' => $final]
        );
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        $cw = $this->instance($cm);
        $map = self::stage_map($cw);
        $submissions = self::submissions($cw->id);
        if (!$submissions) {
            return [];
        }
        // Only units with at least one indexed student count.
        $units = [];
        foreach ($this->allocatables($cm, $cw, $userids) as [$type, $id]) {
            $units[$type . ':' . $id] = true;
        }

        $stagecol = self::column('coursework_feedbacks', ['stageidentifier', 'stage_identifier']);

        // Feedback by submission and stage.
        $feedback = [];
        if ($stagecol) {
            $finalised = self::column('coursework_feedbacks', ['finalised']) ? ', f.finalised' : '';
            $rs = $DB->get_recordset_sql("SELECT f.id, f.submissionid, f.$stagecol AS stage, f.assessorid, f.grade,
                                                 f.timemodified, f.ismoderation $finalised
                                            FROM {coursework_feedbacks} f
                                            JOIN {coursework_submissions} s ON s.id = f.submissionid
                                           WHERE s.courseworkid = :cwid", ['cwid' => $cw->id]);
            foreach ($rs as $f) {
                $moderation = !empty($f->ismoderation) || str_starts_with((string)$f->stage, self::STAGE_MODERATOR);
                $stage = $moderation ? self::STAGE_MODERATOR : $f->stage;
                $feedback[(int)$f->submissionid][$stage] = $f;
            }
            $rs->close();
        }
        // Moderation agreements count as moderation done.
        $agreed = [];
        if (self::column('coursework_mod_agreements', ['feedbackid'])) {
            $agreed = $DB->get_records_sql_menu("SELECT DISTINCT f.submissionid, a.moderatorid
                                                   FROM {coursework_mod_agreements} a
                                                   JOIN {coursework_feedbacks} f ON f.id = a.feedbackid
                                                   JOIN {coursework_submissions} s ON s.id = f.submissionid
                                                  WHERE s.courseworkid = :cwid", ['cwid' => $cw->id]);
        }
        $pairs = $cmrow->hasallocation ? self::allocation_pairs((int)$cw->id) : [];
        $flagged = self::flagged_submissions((int)$cw->id);

        $rows = [];
        foreach ($submissions as $key => $s) {
            if (!isset($units[$key])) {
                continue;
            }
            [$type, $id] = explode(':', $key);
            $done = [];
            $times = [];
            $released = !empty($s->lastpublished);
            foreach ($map as $stagekey => $identifier) {
                $f = $feedback[(int)$s->id][$identifier] ?? null;
                $marked = self::marked($f);
                if ($identifier === self::STAGE_MODERATOR && !$marked && isset($agreed[(int)$s->id])) {
                    $marked = true;
                }
                // Readiness: agreement after every assessor, moderation after the final grade.
                if ($identifier === self::STAGE_AGREED) {
                    $ready = !in_array(false, $done, true);
                } else if ($identifier === self::STAGE_MODERATOR) {
                    $ready = (bool)end($done);
                } else {
                    $ready = true;
                }
                // Ready since the submission, or since the stages it waits on finished.
                if ($identifier === self::STAGE_AGREED) {
                    $timeready = $ready && $times ? max($times) : null;
                } else if ($identifier === self::STAGE_MODERATOR) {
                    $timeready = $ready ? end($times) : null;
                } else {
                    $timeready = (int)$s->timesubmitted ?: null;
                }
                $allocated = $pairs[$key][$identifier] ?? null;
                if ($allocated === null && $identifier === self::STAGE_AGREED) {
                    // Agreement is done by the first marker.
                    $allocated = $pairs[$key][self::STAGE_FIRST] ?? null;
                }
                $rows[] = new marking_row(
                    unitid: (int)$id,
                    unittype: $type === 'group' ? marking_row::UNIT_GROUP : marking_row::UNIT_USER,
                    stage: $stagekey,
                    allocatedto: $cmrow->hasallocation ? ($allocated ?: null) : null,
                    submitted: $ready,
                    marked: $marked,
                    markedby: $marked && $f ? ((int)$f->assessorid ?: null) : null,
                    timemarked: $marked && $f ? (int)$f->timemodified : null,
                    released: $released,
                    timeready: $timeready ?: null,
                    flagged: isset($flagged[(int)$s->id]),
                );
                $done[] = $marked;
                $times[] = $marked && $f ? (int)$f->timemodified : 0;
            }
        }
        return $rows;
    }
}
