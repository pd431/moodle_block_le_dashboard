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
            // Allocated only once markers are: allocation on with only moderator pairs (or none yet) would
            // leave every unit unallocated and all the work with the convenor (Pierre's data, 2026-10-08).
            hasallocation: !empty($cw->allocationenabled) && self::has_assessor_pairs((int)$cw->id),
            stages: array_keys(self::stage_map($cw)),
            submissions: !self::by_attendance($cw),
        );
    }

    /** Coursework type values meaning students attend rather than hand work in online. */
    const ATTENDANCE_TYPES = ['attendance'];

    /**
     * Whether the coursework is assessed by attendance: nothing is handed in online. Exeter's coursework
     * keeps this in courseworktype (Pierre's schema, 2026-10-07; the value is unconfirmed); the other
     * columns are kept for versions that name it differently.
     *
     * @param \stdClass $cw
     * @return bool
     */
    protected static function by_attendance(\stdClass $cw): bool {
        foreach (['courseworktype', 'submissiontype', 'submissionmethod'] as $field) {
            if (isset($cw->$field) && in_array(\core_text::strtolower((string)$cw->$field), self::ATTENDANCE_TYPES)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether students hand work in as groups: use_groups in Exeter's coursework, usegroups elsewhere.
     *
     * @param \stdClass $cw
     * @return bool
     */
    protected static function uses_groups(\stdClass $cw): bool {
        return !empty($cw->use_groups) || !empty($cw->usegroups);
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
        if (self::uses_groups($cw)) {
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
     * @param array|null $only Only these allocatables ([type, id] pairs), or null for all.
     * @return \stdClass[]
     */
    protected static function by_allocatable(
        string $table,
        int $courseworkid,
        string $fields,
        string $where = '',
        ?array $only = null
    ): array {
        global $DB;
        [$onlysql, $params] = self::allocatable_sql($only);
        $rs = $DB->get_recordset_sql("SELECT id, allocatableid, allocatabletype, $fields
                                        FROM {{$table}}
                                       WHERE courseworkid = :cwid $where $onlysql
                                    ORDER BY id", ['cwid' => $courseworkid] + $params);
        $out = [];
        foreach ($rs as $r) {
            $out[$r->allocatabletype . ':' . $r->allocatableid] = $r;
        }
        $rs->close();
        return $out;
    }

    /**
     * The allocatables to read when a call covers only some students: all of them when there are
     * at most CHUNK (one student's re-index, the per-student retry, a chunk of a full index), or
     * null to read the whole coursework in one go.
     *
     * @param array $allocatables userid => [type, id]
     * @return array|null [type, id] pairs, or null
     */
    protected static function only(array $allocatables): ?array {
        return count($allocatables) <= self::CHUNK ? array_values($allocatables) : null;
    }

    /**
     * SQL restricting a per-allocatable table to some allocatables.
     *
     * @param array|null $only [type, id] pairs, or null for no restriction.
     * @param string $alias Table alias with its dot, if any.
     * @return array [sql starting with AND, or '', params]
     */
    protected static function allocatable_sql(?array $only, string $alias = ''): array {
        global $DB;
        if ($only === null) {
            return ['', []];
        }
        $bytype = [];
        foreach ($only as [$type, $id]) {
            $bytype[$type][(int)$id] = (int)$id;
        }
        if (!$bytype) {
            return ['AND 1 = 0', []];
        }
        $parts = [];
        $params = [];
        foreach ($bytype as $type => $ids) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_values($ids), SQL_PARAMS_NAMED, 'alc');
            $typeparam = 'alt' . count($parts);
            $parts[] = "({$alias}allocatabletype = :$typeparam AND {$alias}allocatableid $insql)";
            $params += $inparams + [$typeparam => $type];
        }
        return ['AND (' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * Extensions by allocatable, from whichever table this coursework version has.
     *
     * @param int $courseworkid
     * @param array|null $only Only these allocatables, or null for all.
     * @return array[] "type:id" => [extended deadline, whether it was self-certified]
     */
    protected static function extensions(int $courseworkid, ?array $only = null): array {
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
            foreach (self::by_allocatable('coursework_mitigations', $courseworkid, $fields, $type, $only) as $k => $r) {
                $add($k, $r);
            }
        }
        if (self::column('coursework_extensions', ['extended_deadline'])) {
            foreach (self::by_allocatable('coursework_extensions', $courseworkid, 'extended_deadline', '', $only) as $k => $r) {
                $add($k, $r);
            }
        }
        return $out;
    }

    /**
     * Handed-in submissions by allocatable: those with a submission time, and those with a mark. Many
     * submissions stay unfinalised and are still marked (Pierre's schema, 2026-10-07), so finalised
     * only says the student can't change the work any more (locked), not whether it was handed in.
     *
     * @param int $courseworkid
     * @param array|null $only Only these allocatables, or null for all.
     * @return \stdClass[] "type:id" => submission, with finalised (null where the version has no such column)
     */
    protected static function submissions(int $courseworkid, ?array $only = null): array {
        global $DB;
        $finalised = self::column('coursework_submissions', ['finalisedstatus', 'finalised']);
        $marked = "f.grade IS NOT NULL AND f.grade <> ''"
            . (self::column('coursework_feedbacks', ['finalised']) ? ' AND f.finalised = 1' : '');
        $hasmark = self::column('coursework_feedbacks', ['submissionid'])
            ? " OR EXISTS (SELECT 1 FROM {coursework_feedbacks} f WHERE f.submissionid = s.id AND $marked)" : '';
        $finalisedsql = $finalised ? "s.$finalised" : 'NULL';
        [$onlysql, $params] = self::allocatable_sql($only, 's.');
        $rs = $DB->get_recordset_sql("SELECT s.id, s.allocatableid, s.allocatabletype, s.timesubmitted,
                                             s.firstpublished, s.lastpublished, $finalisedsql AS finalised
                                        FROM {coursework_submissions} s
                                       WHERE s.courseworkid = :cwid AND (s.timesubmitted > 0$hasmark) $onlysql
                                    ORDER BY s.id", ['cwid' => $courseworkid] + $params);
        $out = [];
        foreach ($rs as $r) {
            $out[$r->allocatabletype . ':' . $r->allocatableid] = $r;
        }
        $rs->close();
        return $out;
    }

    /**
     * Whether a submission's feedback is released to the student: first published (Pierre's schema),
     * or last published for versions that only set that.
     *
     * @param \stdClass $s
     * @return bool
     */
    protected static function released(\stdClass $s): bool {
        return (int)$s->firstpublished > 0 || (int)$s->lastpublished > 0;
    }

    /**
     * Whether feedback counts as marking done: a grade that isn't blank, and finalised where this
     * coursework version records it (Pierre's schema: the reliable "graded" signal).
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
        if (count($userids) > self::CHUNK) {
            // A large coursework is read a chunk of students at a time, so memory stays flat.
            $rows = [];
            foreach (array_chunk(array_values($userids), self::CHUNK) as $chunk) {
                $rows += $this->student_rows($cm, $cmrow, $chunk);
            }
            return $rows;
        }
        $cw = $this->instance($cm);
        $rows = self::default_rows($cmrow, $userids);
        $allocatables = $this->allocatables($cm, $cw, array_keys($rows));
        // Read only these students' records where there are few of them (scale: one student's
        // re-index mustn't read the whole coursework).
        $only = self::only($allocatables);

        $pdcol = self::column('coursework_person_deadlines', ['personal_deadline', 'personaldeadline']);
        $personal = $pdcol ? self::by_allocatable('coursework_person_deadlines', $cw->id, $pdcol, '', $only) : [];
        $extensions = self::extensions($cw->id, $only);
        $submissions = self::submissions($cw->id, $only);
        $finalgrades = $this->final_grades($cw, $only === null ? null : array_map(fn($s) => (int)$s->id, $submissions));
        $pairs = $cmrow->hasallocation ? self::allocation_pairs((int)$cw->id, $only) : [];

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
                // A marked draft may never have been handed in by the student: no time, so never late.
                $row->timesubmitted = (int)$s->timesubmitted ?: null;
                // Finalised work can't be changed any more.
                $row->locked = !empty($s->finalised);
                // D4: released once the final grade is published, dated by publish time.
                if (self::released($s)) {
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
     * Whether any marker (not moderator) is allocated on the coursework.
     *
     * @param int $courseworkid
     * @return bool
     */
    protected static function has_assessor_pairs(int $courseworkid): bool {
        global $DB;
        $stagecol = self::column('coursework_allocation_pairs', ['stageidentifier', 'stage_identifier']);
        if (!$stagecol) {
            return false;
        }
        $moderator = self::column('coursework_allocation_pairs', ['moderator']) ? ' AND (moderator IS NULL OR moderator = 0)' : '';
        return $DB->record_exists_select(
            'coursework_allocation_pairs',
            "courseworkid = :cwid AND NOT " . $DB->sql_like($stagecol, ':mod') . $moderator,
            ['cwid' => $courseworkid, 'mod' => self::STAGE_MODERATOR . '%']
        );
    }

    /**
     * Whether any moderator is allocated on the coursework: then moderation is a sample.
     *
     * @param int $courseworkid
     * @return bool
     */
    protected static function has_moderator_pairs(int $courseworkid): bool {
        global $DB;
        $stagecol = self::column('coursework_allocation_pairs', ['stageidentifier', 'stage_identifier']);
        if (!$stagecol) {
            return false;
        }
        $moderator = self::column('coursework_allocation_pairs', ['moderator']) ? ' OR moderator = 1' : '';
        return $DB->record_exists_select(
            'coursework_allocation_pairs',
            "courseworkid = :cwid AND (" . $DB->sql_like($stagecol, ':mod') . $moderator . ")",
            ['cwid' => $courseworkid, 'mod' => self::STAGE_MODERATOR . '%']
        );
    }

    /**
     * Allocated assessors by allocatable and stage.
     *
     * @param int $courseworkid
     * @param array|null $only Only these allocatables, or null for all.
     * @return array "type:id" => [stage => assessorid]
     */
    protected static function allocation_pairs(int $courseworkid, ?array $only = null): array {
        global $DB;
        $stagecol = self::column('coursework_allocation_pairs', ['stageidentifier', 'stage_identifier']);
        if (!$stagecol) {
            return [];
        }
        $pairs = [];
        $moderator = self::column('coursework_allocation_pairs', ['moderator']) ? ', moderator' : ', 0 AS moderator';
        [$onlysql, $params] = self::allocatable_sql($only);
        $rs = $DB->get_recordset_sql("SELECT id, allocatableid, allocatabletype, assessorid, $stagecol AS stage $moderator
                                        FROM {coursework_allocation_pairs}
                                       WHERE courseworkid = :cwid $onlysql", ['cwid' => $courseworkid] + $params);
        foreach ($rs as $p) {
            // Moderator allocations may be staged moderator_1 (Pierre's schema): any is moderation.
            $stage = !empty($p->moderator) || str_starts_with((string)$p->stage, self::STAGE_MODERATOR)
                ? self::STAGE_MODERATOR : $p->stage;
            $pairs[$p->allocatabletype . ':' . $p->allocatableid][$stage] = (int)$p->assessorid;
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
     * Whether the coursework uses plagiarism flags: off only where the setting exists and is off.
     *
     * @param \stdClass $cw
     * @return bool
     */
    protected static function flags_enabled(\stdClass $cw): bool {
        return !property_exists($cw, 'plagiarismflagenabled') || !empty($cw->plagiarismflagenabled);
    }

    /**
     * Submissions with an open plagiarism (academic conduct) flag, from mod_coursework's own table.
     * Without the table there are none.
     *
     * @param int $courseworkid
     * @param int[]|null $submissionids Only these submissions, or null for all.
     * @return array submission id => true
     */
    protected static function flagged_submissions(int $courseworkid, ?array $submissionids = null): array {
        global $DB;
        if (!self::column('coursework_plagiarism_flags', ['submissionid']) || $submissionids === []) {
            return [];
        }
        [$statussql, $params] = $DB->get_in_or_equal(self::OPEN_FLAG_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params['cwid'] = $courseworkid;
        $subsql = '';
        if ($submissionids !== null) {
            [$subsql, $subparams] = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED, 'sub');
            $subsql = "AND submissionid $subsql";
            $params += $subparams;
        }
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT submissionid
                                        FROM {coursework_plagiarism_flags}
                                       WHERE courseworkid = :cwid AND submissionid > 0 AND status IS NOT NULL $subsql
                                             AND " . $DB->sql_compare_text('status') . " $statussql", $params);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * SQL restricting a query to some submissions.
     *
     * @param int[]|null $submissionids Or null for no restriction.
     * @param string $field
     * @return array [sql starting with AND, or '', params]
     */
    protected static function submission_sql(?array $submissionids, string $field): array {
        global $DB;
        if ($submissionids === null) {
            return ['', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($submissionids ?: [0], SQL_PARAMS_NAMED, 'sub');
        return ["AND $field $insql", $params];
    }

    /**
     * Final grade per submission: the agreed grade, or the only marker's grade.
     *
     * @param \stdClass $cw
     * @param int[]|null $submissionids Only these submissions, or null for all.
     * @return float[] submissionid => grade
     */
    protected function final_grades(\stdClass $cw, ?array $submissionids = null): array {
        global $DB;
        $stagecol = self::column('coursework_feedbacks', ['stageidentifier', 'stage_identifier']);
        if (!$stagecol || $submissionids === []) {
            return [];
        }
        [$subsql, $params] = self::submission_sql($submissionids, 's.id');
        $final = (int)$cw->numberofmarkers > 1 ? self::STAGE_AGREED : self::STAGE_FIRST;
        $finalised = self::column('coursework_feedbacks', ['finalised']) ? ' AND f.finalised = 1' : '';
        return $DB->get_records_sql_menu(
            "SELECT f.submissionid, f.grade
                                            FROM {coursework_feedbacks} f
                                            JOIN {coursework_submissions} s ON s.id = f.submissionid
                                           WHERE s.courseworkid = :cwid AND f.$stagecol = :stage AND f.grade IS NOT NULL
                                                 AND f.grade <> ''$finalised $subsql",
            ['cwid' => $cw->id, 'stage' => $final] + $params
        );
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        if (count($userids) > self::CHUNK) {
            // A chunk at a time, as student_rows(). A group split across chunks gives the same unit
            // twice, identical; the indexer keeps one.
            $rows = [];
            foreach (array_chunk(array_values($userids), self::CHUNK) as $chunk) {
                array_push($rows, ...$this->marking_rows($cm, $cmrow, $chunk));
            }
            return $rows;
        }
        $cw = $this->instance($cm);
        $map = self::stage_map($cw);
        // Only units with at least one indexed student count.
        $allocatables = $this->allocatables($cm, $cw, $userids);
        $units = [];
        foreach ($allocatables as [$type, $id]) {
            $units[$type . ':' . $id] = true;
        }
        $only = self::only($allocatables);
        $submissions = self::submissions($cw->id, $only);
        if (!$submissions) {
            return [];
        }
        $subids = $only === null ? null : array_map(fn($s) => (int)$s->id, array_values($submissions));

        $stagecol = self::column('coursework_feedbacks', ['stageidentifier', 'stage_identifier']);

        // Feedback by submission and stage.
        $feedback = [];
        if ($stagecol) {
            $finalised = self::column('coursework_feedbacks', ['finalised']) ? ', f.finalised' : '';
            [$subsql, $params] = self::submission_sql($subids, 's.id');
            $rs = $DB->get_recordset_sql("SELECT f.id, f.submissionid, f.$stagecol AS stage, f.assessorid, f.grade,
                                                 f.timemodified, f.ismoderation $finalised
                                            FROM {coursework_feedbacks} f
                                            JOIN {coursework_submissions} s ON s.id = f.submissionid
                                           WHERE s.courseworkid = :cwid $subsql", ['cwid' => $cw->id] + $params);
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
            [$subsql, $params] = self::submission_sql($subids, 's.id');
            $agreed = $DB->get_records_sql_menu("SELECT DISTINCT f.submissionid, a.moderatorid
                                                   FROM {coursework_mod_agreements} a
                                                   JOIN {coursework_feedbacks} f ON f.id = a.feedbackid
                                                   JOIN {coursework_submissions} s ON s.id = f.submissionid
                                                  WHERE s.courseworkid = :cwid $subsql", ['cwid' => $cw->id] + $params);
        }
        // Moderator pairs say which units are sampled for moderation, with or without marker allocation.
        $sampling = in_array(self::STAGE_MODERATOR, $map) && self::has_moderator_pairs((int)$cw->id);
        $pairs = $cmrow->hasallocation || $sampling ? self::allocation_pairs((int)$cw->id, $only) : [];
        $flagged = self::flags_enabled($cw) ? self::flagged_submissions((int)$cw->id, $subids) : [];

        $rows = [];
        foreach ($submissions as $key => $s) {
            if (!isset($units[$key])) {
                continue;
            }
            [$type, $id] = explode(':', $key);
            $done = [];
            $times = [];
            $released = self::released($s);
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
                    // Where moderators are allocated, moderation owes only the sample: units with a moderator,
                    // or already moderated (Pierre's data, 2026-10-08: 12 moderated of 277, not 12 of 277).
                    $ready = (bool)end($done) && (!$sampling || $marked || isset($pairs[$key][self::STAGE_MODERATOR]));
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
