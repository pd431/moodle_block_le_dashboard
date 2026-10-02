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

use block_my_assessments\local\index\cm_row;
use block_my_assessments\local\index\marking_row;
use block_my_assessments\local\index\student_row;

/**
 * Adapter for mod_assign.
 *
 * Deadlines follow assign::override_exists() and update_effective_access():
 * a user override wins, otherwise the first group override by sortorder;
 * each date the override leaves empty keeps the assignment's own. An
 * extension (assign_user_flags.extensionduedate) then moves the deadline and
 * cut-off later. A unit test compares this with assign's own API.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
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
    public function cm_row(\cm_info $cm): ?cm_row {
        $a = $this->instance($cm);
        return new cm_row(
            opens: self::date($a->allowsubmissionsfromdate),
            due: self::date($a->duedate),
            cutoff: self::date($a->cutoffdate),
            markingdue: self::date($a->gradingduedate),
            hasallocation: !empty($a->markingworkflow) && !empty($a->markingallocation),
            stages: ['marked'],
        );
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

        foreach ($rows as $userid => $row) {
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
                if ($row->cutoff && $row->cutoff < $extension) {
                    $row->cutoff = $extension;
                }
            }
        }

        foreach ($this->submissions($cm, $a, array_keys($rows)) as $userid => $submission) {
            $rows[$userid]->submitted = $submission->status === 'submitted';
            $rows[$userid]->timesubmitted = $rows[$userid]->submitted ? (int)$submission->timemodified : null;
        }
        self::apply_gradebook_release($cm, $rows);
        return $rows;
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        global $DB;
        $a = $this->instance($cm);
        $submissions = array_filter($this->submissions($cm, $a, $userids), fn($s) => $s->status === 'submitted');
        if (!$submissions) {
            return [];
        }
        $flags = $this->flags($a->id, $userids);
        $grades = $this->latest_grades($a->id, array_keys($submissions));
        $released = self::released_now($cm, array_keys($submissions));

        // One unit per submission: the group for team submissions, else the student.
        $units = [];
        foreach ($submissions as $userid => $s) {
            $isgroup = !empty($a->teamsubmission) && !empty($s->groupid);
            $key = ($isgroup ? 'g' : 'u') . ($isgroup ? $s->groupid : $userid);
            $grade = $grades[$userid] ?? null;
            if ($a->markingworkflow) {
                $marked = in_array($flags[$userid]->workflowstate ?? '', self::MARKED_STATES);
            } else {
                // The assign needs-grading rule: a grade newer than the submission.
                $marked = $grade && $grade->grade !== null && (float)$grade->grade >= 0
                    && (int)$grade->timemodified >= (int)$s->timemodified;
            }
            $allocated = $cmrow->hasallocation ? ((int)($flags[$userid]->allocatedmarker ?? 0) ?: null) : null;
            if (!isset($units[$key])) {
                $units[$key] = new marking_row(
                    unitid: $isgroup ? (int)$s->groupid : (int)$userid,
                    unittype: $isgroup ? marking_row::UNIT_GROUP : marking_row::UNIT_USER,
                    stage: 'marked',
                    allocatedto: $allocated,
                    submitted: true,
                    marked: $marked,
                    markedby: $marked && $grade ? ((int)$grade->grader ?: null) : null,
                    timemarked: $marked && $grade ? (int)$grade->timemodified : null,
                    released: isset($released[$userid]),
                );
            } else {
                // A team: the unit is marked or released once any member is.
                $unit = $units[$key];
                $unit->marked = $unit->marked || $marked;
                $unit->released = $unit->released || isset($released[$userid]);
                $unit->allocatedto = $unit->allocatedto ?? $allocated;
                if ($marked && $grade && (int)$grade->timemodified > (int)$unit->timemarked) {
                    $unit->markedby = (int)$grade->grader ?: null;
                    $unit->timemarked = (int)$grade->timemodified;
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
            return $DB->get_records_sql("SELECT userid, extensionduedate, workflowstate, allocatedmarker
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
     * @return \stdClass[] Keyed by user id: status, timemodified, groupid.
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
                return $DB->get_records_sql("SELECT userid, status, timemodified, groupid
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
            'groupid, status, timemodified'
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
     * @return \stdClass[] Keyed by user id: grade, grader, timemodified.
     */
    protected function latest_grades(int $assignid, array $userids): array {
        global $DB;
        return self::chunked($userids, function ($chunk) use ($DB, $assignid) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['assignid'] = $assignid;
            $params['assignid2'] = $assignid;
            return $DB->get_records_sql("SELECT g.userid, g.grade, g.grader, g.timemodified
                                           FROM {assign_grades} g
                                           JOIN (SELECT userid, MAX(attemptnumber) AS attemptnumber
                                                   FROM {assign_grades}
                                                  WHERE assignment = :assignid2
                                               GROUP BY userid) latest
                                             ON latest.userid = g.userid AND latest.attemptnumber = g.attemptnumber
                                          WHERE g.assignment = :assignid AND g.userid $insql", $params);
        });
    }
}
