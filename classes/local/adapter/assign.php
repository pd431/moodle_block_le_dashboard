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
 * Adapter for mod_assign.
 *
 * Deadlines follow assign::override_exists() and update_effective_access():
 * a user override wins, otherwise the first group override by sortorder;
 * each date the override leaves empty keeps the assignment's own. An
 * extension (assign_user_flags.extensionduedate) then moves the deadline and
 * cut-off later. A unit test compares this with assign's own API.
 *
 * Moodle 5.0+ (checked by schema, not version): markers are allocated in
 * assign_allocated_marker, and an assignment can have several markers
 * (markercount). Each marker's own mark is a stage (marker1..n, from assign_mark),
 * and with the manual method someone then sets the final grade (agreed).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign extends base {
    /** Workflow states that mean this stage's marking is done. */
    const MARKED_STATES = ['readyforreview', 'inreview', 'readyforrelease', 'released'];

    #[\Override]
    public function modname(): string {
        return 'assign';
    }

    #[\Override]
    public static function events(): array {
        return [
            '\mod_assign\event\assessable_submitted',
            '\mod_assign\event\submission_status_updated',
            '\mod_assign\event\submission_removed',
            '\mod_assign\event\submission_graded',
            // A teacher's lock and unlock decide Submit or Can't submit (B4).
            '\mod_assign\event\submission_locked',
            '\mod_assign\event\submission_unlocked',
            '\mod_assign\event\extension_granted',
            '\mod_assign\event\marker_updated',
            '\mod_assign\event\workflow_state_updated',
            '\mod_assign\event\user_override_created',
            '\mod_assign\event\user_override_updated',
            '\mod_assign\event\user_override_deleted',
            '\mod_assign\event\group_override_created',
            '\mod_assign\event\group_override_updated',
            '\mod_assign\event\group_override_deleted',
        ];
    }

    #[\Override]
    public static function events_for_related_user(): array {
        // A teacher's lock or unlock is about the student it names (B4).
        return ['\mod_assign\event\submission_locked', '\mod_assign\event\submission_unlocked'];
    }

    #[\Override]
    public function brief_areas(): array {
        // The "Additional files" on the assignment's settings.
        return ['introattachment'];
    }

    #[\Override]
    public function cm_row(\cm_info $cm): ?cm_row {
        $a = $this->instance($cm);
        $markers = self::markercount($a);
        $stages = ['marked'];
        if ($markers > 1) {
            $stages = array_map(fn($i) => 'marker' . $i, range(1, $markers));
            if (($a->multimarkmethod ?? '') === 'manual') {
                $stages[] = 'agreed';
            }
        }
        return new cm_row(
            opens: self::date($a->allowsubmissionsfromdate),
            due: self::date($a->duedate),
            cutoff: self::date($a->cutoffdate),
            markingdue: self::date($a->gradingduedate),
            hasallocation: !empty($a->markingworkflow) && !empty($a->markingallocation),
            stages: $stages,
            submissions: self::submissions_enabled($a),
        );
    }

    /**
     * Whether students can submit at all: a submission plugin other than comments is enabled,
     * as assign::is_any_submission_plugin_enabled(). An assignment used only to record a mark
     * (a presentation, an exam) has none (EC5).
     *
     * @param \stdClass $a
     * @return bool
     */
    protected static function submissions_enabled(\stdClass $a): bool {
        global $DB;
        return $DB->record_exists_select(
            'assign_plugin_config',
            "assignment = :assignid AND subtype = 'assignsubmission' AND plugin <> 'comments'
             AND name = 'enabled' AND " . $DB->sql_compare_text('value', 10) . " = '1'",
            ['assignid' => $a->id]
        );
    }

    /**
     * Number of markers per submission (always 1 before Moodle 5.0).
     *
     * @param \stdClass $a
     * @return int
     */
    protected static function markercount(\stdClass $a): int {
        return max(1, (int)($a->markercount ?? 1));
    }

    /**
     * Whether markers are allocated in their own table (Moodle 5.0+).
     *
     * @return bool
     */
    protected static function allocation_table(): bool {
        global $DB;
        static $exists = null;
        if ($exists === null || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            $exists = $DB->get_manager()->table_exists('assign_allocated_marker');
        }
        return $exists;
    }

    /**
     * Allocated markers per student, in allocation order.
     *
     * @param \stdClass $a
     * @param int[] $userids
     * @param \stdClass[] $flags From flags(), used before Moodle 5.0.
     * @return int[][] Keyed by user id.
     */
    protected function allocations(\stdClass $a, array $userids, array $flags): array {
        global $DB;
        if (!self::allocation_table()) {
            $out = [];
            foreach ($userids as $userid) {
                if ($marker = (int)($flags[$userid]->allocatedmarker ?? 0)) {
                    $out[$userid] = [$marker];
                }
            }
            return $out;
        }
        $out = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['assignid'] = $a->id;
            $rs = $DB->get_recordset_select(
                'assign_allocated_marker',
                "assignment = :assignid AND student $insql",
                $params,
                'id',
                'id, student, marker'
            );
            foreach ($rs as $r) {
                $out[(int)$r->student][] = (int)$r->marker;
            }
            $rs->close();
        }
        return $out;
    }

    /**
     * Each marker's own marks (Moodle 5.0+, several markers), oldest first.
     *
     * @param \stdClass $a
     * @param int[] $userids
     * @return \stdClass[][] Keyed by user id, then marker id: mark, timemodified.
     */
    protected function marks(\stdClass $a, array $userids): array {
        global $DB;
        $out = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['assignid'] = $a->id;
            $params['assignid2'] = $a->id;
            $rs = $DB->get_recordset_sql("SELECT m.id, g.userid, g.attemptnumber, m.marker, m.mark, m.timemodified
                                            FROM {assign_mark} m
                                            JOIN {assign_grades} g ON g.id = m.gradeid
                                            JOIN (SELECT userid, MAX(attemptnumber) AS attemptnumber
                                                    FROM {assign_grades}
                                                   WHERE assignment = :assignid2
                                                GROUP BY userid) latest
                                              ON latest.userid = g.userid AND latest.attemptnumber = g.attemptnumber
                                           WHERE m.assignment = :assignid AND m.mark IS NOT NULL AND g.userid $insql
                                        ORDER BY m.timecreated, m.id", $params);
            foreach ($rs as $r) {
                $out[(int)$r->userid][(int)$r->marker] = $r;
            }
            $rs->close();
        }
        return $out;
    }

    #[\Override]
    public function student_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        $a = $this->instance($cm);
        $rows = self::default_rows($cmrow, $userids);
        if (!$rows) {
            return [];
        }

        // Overrides: user first, then the first matching group override by sortorder.
        $overrides = $DB->get_records('assign_overrides', ['assignid' => $a->id], 'sortorder ASC, id ASC');
        $useroverrides = [];
        $groupoverrides = [];
        foreach ($overrides as $o) {
            if ($o->userid) {
                $useroverrides[(int)$o->userid] = $o;
            } else if ($o->groupid) {
                $groupoverrides[] = $o;
            }
        }
        $groups = $groupoverrides ? self::user_groups($cm->course, array_keys($rows)) : [];
        $flags = $this->flags($a->id, array_keys($rows));
        // Allocated markers, so extensions still to come reach their markers (G9).
        $allocations = $cmrow->hasallocation ? $this->allocations($a, array_keys($rows), $flags) : [];

        foreach ($rows as $userid => $row) {
            $row->allocatedto = $allocations[$userid] ?? [];
            $override = $useroverrides[$userid] ?? null;
            if (!$override && !empty($groups[$userid])) {
                foreach ($groupoverrides as $o) {
                    if (in_array((int)$o->groupid, $groups[$userid])) {
                        $override = $o;
                        break;
                    }
                }
            }
            if ($override) {
                if (isset($override->duedate)) {
                    $row->due = self::date($override->duedate);
                    $row->individual = true;
                }
                if (isset($override->cutoffdate)) {
                    $row->cutoff = self::date($override->cutoffdate);
                    $row->individual = true;
                }
            }
            $extension = (int)($flags[$userid]->extensionduedate ?? 0);
            if ($extension && $extension > (int)$row->due) {
                $row->due = $extension;
                $row->individual = true;
                $row->mitigation = student_row::MITIGATION_EXTENSION;
                if ($row->cutoff && $row->cutoff < $extension) {
                    $row->cutoff = $extension;
                }
            }
        }

        if (!$cmrow->submissions) {
            // Nothing to submit: no submission state, like the generic tier (EC5).
            foreach ($rows as $row) {
                $row->submitted = null;
            }
            self::apply_gradebook_release($cm, $rows);
            return $rows;
        }
        $submissions = $this->submissions($cm, $a, array_keys($rows));
        $drafts = array_keys(array_filter($submissions, fn($sub) => $sub->status === 'draft'));
        $grades = $drafts ? $this->latest_grades($a->id, $drafts) : [];
        foreach ($submissions as $userid => $submission) {
            $rows[$userid]->submitted = $submission->status === 'submitted';
            $rows[$userid]->timesubmitted = $rows[$userid]->submitted ? (int)$submission->timemodified : null;
            // A new, empty attempt ("Allow another attempt") the student can still submit (G13), or
            // a graded attempt reverted to draft for rework, which reads the same way (EC11).
            $graded = isset($grades[$userid]) && $grades[$userid]->grade !== null && (float)$grades[$userid]->grade >= 0
                && (int)$grades[$userid]->attemptnumber === (int)$submission->attemptnumber;
            $rows[$userid]->reopened = $submission->status === 'reopened' || ($submission->status === 'draft' && $graded);
            // Final once submitted where students must click Submit, or when a teacher locked it.
            if ($rows[$userid]->submitted) {
                $rows[$userid]->lockreason = !empty($flags[$userid]->locked) ? student_row::LOCK_TEACHER
                    : (!empty($a->submissiondrafts) ? student_row::LOCK_FINALISED : null);
            }
        }
        // A teacher can lock submissions before anything is handed in: the student can't submit (B1).
        foreach ($rows as $userid => $row) {
            if (!$row->submitted && !empty($flags[$userid]->locked)) {
                $row->lockreason = student_row::LOCK_TEACHER;
            }
        }
        self::apply_gradebook_release($cm, $rows);
        // A resubmission after release is in marking again, whatever the gradebook still holds.
        foreach ($rows as $row) {
            if ($row->timereleased !== null && $row->timesubmitted !== null && $row->timesubmitted > $row->timereleased) {
                $row->timereleased = null;
                $row->gradedisplay = null;
            }
        }
        return $rows;
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        $a = $this->instance($cm);
        if ($cmrow->submissions) {
            $submissions = array_filter($this->submissions($cm, $a, $userids), fn($s) => $s->status === 'submitted');
        } else {
            // Nothing to submit: every student has a unit, ready at their deadline (EC5).
            $submissions = [];
            foreach ($this->latest_grades($a->id, $userids) + array_fill_keys($userids, null) as $userid => $g) {
                $submissions[$userid] = (object)['status' => 'submitted', 'timemodified' => 1, 'groupid' => 0,
                    'attemptnumber' => $g ? (int)$g->attemptnumber : 0];
            }
        }
        if (!$submissions) {
            return [];
        }
        $flags = $this->flags($a->id, $userids);
        $grades = $this->latest_grades($a->id, array_keys($submissions));
        $released = self::released_since($cm, $submissions);
        $allocations = $cmrow->hasallocation ? $this->allocations($a, array_keys($submissions), $flags) : [];
        $markers = self::markercount($a);
        $marks = $markers > 1 ? $this->marks($a, array_keys($submissions)) : [];

        // One unit per submission and stage: the group for team submissions, else the student.
        $units = [];
        foreach ($submissions as $userid => $s) {
            $isgroup = !empty($a->teamsubmission) && !empty($s->groupid);
            // Only a grade on this attempt counts: a reopened attempt starts unmarked.
            $grade = $grades[$userid] ?? null;
            $grade = $grade && (int)$grade->attemptnumber === (int)$s->attemptnumber ? $grade : null;
            // Core's needs-grading rule: a grade on this attempt, no older than the submission.
            // The workflow state alone isn't enough: a new attempt keeps the old state.
            $final = $grade && $grade->grade !== null && (float)$grade->grade >= 0
                && (int)$grade->timemodified >= (int)$s->timemodified;
            if ($a->markingworkflow) {
                $final = $final && in_array($flags[$userid]->workflowstate ?? '', self::MARKED_STATES);
            }
            $allocated = $allocations[$userid] ?? [];
            $stages = [];
            $submitted = (int)$s->timemodified ?: null;
            if ($markers === 1) {
                $stages['marked'] = [$allocated[0] ?? null, true, $final,
                    $final && $grade ? ((int)$grade->grader ?: null) : null,
                    $final && $grade ? (int)$grade->timemodified : null, $submitted];
            } else {
                // Each marker's own mark; unallocated stages take marks in the order given.
                $mine = array_filter($marks[$userid] ?? [], fn($m) => (int)$m->attemptnumber === (int)$s->attemptnumber);
                $loose = array_values(array_diff_key($mine, array_flip($allocated)));
                for ($i = 1; $i <= $markers; $i++) {
                    $marker = $allocated[$i - 1] ?? null;
                    $mark = $marker !== null ? ($mine[$marker] ?? null) : array_shift($loose);
                    $stages['marker' . $i] = [$marker, true, $mark !== null,
                        $mark ? (int)$mark->marker : null, $mark ? (int)$mark->timemodified : null, $submitted];
                }
                if (in_array('agreed', $cmrow->stages)) {
                    // Agreement goes to the first allocated marker, as coursework does (G8).
                    // With allocation, only the allocated markers' marks count, as assign::get_marks() (EC9).
                    $counted = $cmrow->hasallocation ? array_intersect_key($mine, array_flip($allocated)) : $mine;
                    $agreeable = count($counted) >= $markers;
                    $stages['agreed'] = [$allocated[0] ?? null, $agreeable, $final,
                        $final && $grade ? ((int)$grade->grader ?: null) : null,
                        $final && $grade ? (int)$grade->timemodified : null,
                        $agreeable ? max(array_map(fn($m) => (int)$m->timemodified, $counted)) : null];
                }
            }
            foreach ($stages as $stage => [$allocatedto, $ready, $marked, $markedby, $timemarked, $timeready]) {
                $key = ($isgroup ? 'g' . $s->groupid : 'u' . $userid) . '-' . $stage;
                if (!isset($units[$key])) {
                    $units[$key] = new marking_row(
                        unitid: $isgroup ? (int)$s->groupid : (int)$userid,
                        unittype: $isgroup ? marking_row::UNIT_GROUP : marking_row::UNIT_USER,
                        stage: $stage,
                        allocatedto: $allocatedto,
                        submitted: $ready,
                        marked: $marked,
                        markedby: $markedby,
                        timemarked: $timemarked,
                        released: isset($released[$userid]),
                        timeready: $timeready,
                    );
                    continue;
                }
                // A team: the unit is marked or released once any member is.
                $unit = $units[$key];
                $unit->submitted = $unit->submitted || $ready;
                $unit->marked = $unit->marked || $marked;
                $unit->released = $unit->released || isset($released[$userid]);
                $unit->allocatedto = $unit->allocatedto ?? $allocatedto;
                $unit->timeready = max((int)$unit->timeready, (int)$timeready) ?: null;
                if ($marked && $timemarked > (int)$unit->timemarked) {
                    $unit->markedby = $markedby;
                    $unit->timemarked = $timemarked;
                }
            }
        }
        return array_values($units);
    }

    /**
     * User flags (extensions, workflow state, allocated marker).
     *
     * @param int $assignid
     * @param int[] $userids
     * @return \stdClass[] Keyed by user id.
     */
    protected function flags(int $assignid, array $userids): array {
        global $DB;
        return self::chunked($userids, function ($chunk) use ($DB, $assignid) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['assignid'] = $assignid;
            $marker = self::allocation_table() ? '' : ', allocatedmarker';
            return $DB->get_records_sql("SELECT userid, extensionduedate, workflowstate, locked $marker
                                           FROM {assign_user_flags}
                                          WHERE assignment = :assignid AND userid $insql", $params);
        });
    }

    /**
     * Each student's latest submission (their team's, for team assignments).
     *
     * @param \cm_info $cm
     * @param \stdClass $a The assign instance.
     * @param int[] $userids
     * @return \stdClass[] Keyed by user id: status, timemodified, groupid, attemptnumber.
     */
    protected function submissions(\cm_info $cm, \stdClass $a, array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        if (empty($a->teamsubmission)) {
            return self::chunked($userids, function ($chunk) use ($DB, $a) {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
                $params['assignid'] = $a->id;
                return $DB->get_records_sql("SELECT userid, status, timemodified, groupid, attemptnumber
                                               FROM {assign_submission}
                                              WHERE assignment = :assignid AND latest = 1 AND groupid = 0
                                                AND userid $insql", $params);
            });
        }
        // Team submissions: a student's team is their only group in the submission grouping,
        // else the default team (group 0), as assign::get_submission_group() does.
        $groups = self::user_groups($cm->course, $userids, (int)$a->teamsubmissiongroupingid);
        $teams = $DB->get_records_select(
            'assign_submission',
            'assignment = :assignid AND latest = 1 AND userid = 0',
            ['assignid' => $a->id],
            '',
            'groupid, status, timemodified, attemptnumber'
        );
        $out = [];
        foreach ($userids as $userid) {
            $mine = $groups[$userid] ?? [];
            $groupid = count($mine) === 1 ? reset($mine) : 0;
            if (isset($teams[$groupid])) {
                $out[$userid] = $teams[$groupid];
            }
        }
        return $out;
    }

    /**
     * Latest grade record per student.
     *
     * @param int $assignid
     * @param int[] $userids
     * @return \stdClass[] Keyed by user id: grade, grader, timemodified, attemptnumber.
     */
    protected function latest_grades(int $assignid, array $userids): array {
        global $DB;
        return self::chunked($userids, function ($chunk) use ($DB, $assignid) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['assignid'] = $assignid;
            $params['assignid2'] = $assignid;
            return $DB->get_records_sql("SELECT g.userid, g.grade, g.grader, g.timemodified, g.attemptnumber
                                           FROM {assign_grades} g
                                           JOIN (SELECT userid, MAX(attemptnumber) AS attemptnumber
                                                   FROM {assign_grades}
                                                  WHERE assignment = :assignid2
                                               GROUP BY userid) latest
                                             ON latest.userid = g.userid AND latest.attemptnumber = g.attemptnumber
                                          WHERE g.assignment = :assignid AND g.userid $insql", $params);
        });
    }

    #[\Override]
    public function marks_without_submissions(): bool {
        // Allocation, marking workflow and flags still apply (EC5).
        return true;
    }
}
