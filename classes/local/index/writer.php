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

namespace block_le_dashboard\local\index;

use block_le_dashboard\local\adapter\adapter;
use block_le_dashboard\local\adapter\registry;
use block_le_dashboard\local\config;
use block_le_dashboard\local\memo;
use block_le_dashboard\local\tags;

/**
 * Writes one assessment's rows to the three index tables from its adapter: the re-index itself.
 *
 * Re-indexing is idempotent: the rows for an assessment are deleted and written again in one
 * transaction. If an adapter fails, the previous rows are kept (stale is better than missing) and the
 * error is recorded for the admin page. The caller (indexer) holds the assessment's lock.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class writer {
    /** @var int|null Fixed time, for tests. */
    protected ?int $now;

    /**
     * Constructor.
     *
     * @param int|null $now Time to stamp rows with; defaults to now.
     */
    public function __construct(?int $now = null) {
        $this->now = $now;
    }

    /** Tries at writing an assessment's rows when the database reports a deadlock. */
    const WRITE_ATTEMPTS = 3;

    /**
     * Whether an error is the database giving up a transaction in a deadlock (MySQL and MariaDB 1213,
     * PostgreSQL 40P01), after which trying again is safe.
     *
     * @param \Throwable $e
     * @return bool
     */
    protected static function deadlock(\Throwable $e): bool {
        $text = $e->getMessage() . ' ' . ($e->debuginfo ?? '');
        return $e instanceof \dml_exception && stripos($text, 'deadlock') !== false;
    }

    /**
     * The re-index itself: all students, or only some.
     *
     * @param int $cmid
     * @param int[]|null $only Students to re-index, or null for everyone.
     * @return bool
     */
    public function index_rows(int $cmid, ?array $only): bool {
        global $DB;
        if ($only !== null && !$DB->record_exists(indexer::CM, ['cmid' => $cmid])) {
            $only = null;
        }
        $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if (!$courseid) {
            $this->drop_rows($cmid);
            return false;
        }
        $types = tags::types(null, [$cmid]);
        if (!isset($types[$cmid])) {
            $this->drop_rows($cmid);
            return false;
        }
        $modinfo = get_fast_modinfo($courseid);
        $cm = $modinfo->cms[$cmid] ?? null;
        if (!$cm || $cm->deletioninprogress) {
            $this->drop_rows($cmid);
            return false;
        }
        $adapter = registry::adapter_for($cm->modname);
        if (!$adapter) {
            $this->drop_rows($cmid);
            return false;
        }

        try {
            registry::check_test_failure($cm->modname);
            $cmrow = $adapter->cm_row($cm);
            if (!$cmrow) {
                $this->drop_rows($cmid);
                registry::clear_error($cm->modname);
                return false;
            }
            if ($cmrow->submissions && registry::no_submission($cm, $adapter)) {
                $cmrow->submissions = false;
            }
            if (!$cmrow->submissions && !$cmrow->stages && registry::no_submission($cm, $adapter)) {
                // Marked from the gradebook (base::no_submission_units), so one marking stage.
                $cmrow->stages = ['marked'];
            }
            // A first index lists the assessment before reading any student, marked as not loaded, so a
            // failure, or a process that dies part way (memory, time limit), leaves it listed for staff
            // with an honest note rather than missing (Pierre, 2026-10-08).
            if (!$DB->record_exists(indexer::CM, ['cmid' => $cmid])) {
                $DB->insert_record(indexer::CM, $this->cm_record($cm, $adapter, $courseid, $types[$cmid], $cmrow, true));
            }
            $students = enrolment::students($courseid);
            $groupids = [];
            if ($only !== null) {
                [$only, $groupids] = $this->with_group_members($cm, $only);
                $students = array_values(array_intersect($students, $only));
            }
            $students = enrolment::accessible($cm, $students);
            $active = array_flip(enrolment::students($courseid, true));
            $starts = enrolment::enrol_starts($courseid, array_keys($students));
            // One student's bad data mustn't fail the whole activity: their rows and marking units keep
            // what was last indexed and the error is reported; the rest is indexed as usual (A7).
            [$sturows, $failed] = $this->student_rows($adapter, $cm, $cmrow, array_keys($students));
            [$markrows, $markfailed] = $this->marking_rows($adapter, $cm, $cmrow, array_keys($students));
        } catch (\Throwable $e) {
            registry::record_error($cm->modname, $e);
            debugging(
                "block_le_dashboard: indexing cm $cmid ({$cm->modname}) failed: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            $this->load_error($cm, $adapter, $courseid, $types[$cmid], $cmrow ?? null);
            return true;
        }

        // A group unit outside the groups gathered can't be re-indexed in part: do it all.
        if ($only !== null) {
            foreach ($markrows as $row) {
                if ($row->unittype === marking_row::UNIT_GROUP && !in_array($row->unitid, $groupids)) {
                    return $this->index_rows($cmid, null);
                }
            }
        }

        $record = $this->cm_record($cm, $adapter, $courseid, $types[$cmid], $cmrow, $failed || $markfailed);
        $markers = array_flip(enrolment::markers($courseid));
        $stu = [];
        $dues = [];
        $kept = $this->kept_student_rows($cmid, array_keys($failed));
        foreach ($students as $userid => $canaccess) {
            $row = $sturows[$userid] ?? null;
            // Each adapter row is dropped once written out, so a large activity isn't held twice (scale).
            unset($sturows[$userid]);
            if (isset($failed[$userid])) {
                if (isset($kept[$userid])) {
                    $dues[$userid] = $kept[$userid]->due ?? $cmrow->due;
                    $stu[] = $kept[$userid];
                }
                continue;
            }
            $row = $row ?? new student_row($userid, $cmrow->due, $cmrow->cutoff);
            $dues[$userid] = $row->due ?? $cmrow->due;
            // Markers who left the module no longer hold work still to come (EC1).
            $allocated = array_unique(array_filter(array_map('intval', $row->allocatedto), fn($id) => isset($markers[$id])));
            $stu[] = (object)[
                'cmid' => $cmid,
                'userid' => $userid,
                'due' => $row->due,
                'cutoff' => $row->cutoff,
                'individual' => $row->individual ? 1 : 0,
                'mitigation' => $row->mitigation,
                'reopened' => $row->reopened ? 1 : 0,
                'lockreason' => $row->lockreason,
                'allocatedto' => $allocated ? ',' . implode(',', $allocated) . ',' : null,
                'canaccess' => $canaccess ? 1 : 0,
                'active' => isset($active[$userid]) ? 1 : 0,
                'enrolstart' => $starts[$userid] ?? null,
                'submitted' => $row->submitted === null ? null : ($row->submitted ? 1 : 0),
                'timesubmitted' => $row->timesubmitted,
                'timereleased' => $row->timereleased,
                'gradedisplay' => $row->gradedisplay === null ? null : \core_text::substr($row->gradedisplay, 0, 255),
            ];
        }
        $mark = [];
        $seen = [];
        [$groupdues, $shutout] = $this->group_dues($markrows, $dues, $cmrow->due, $students);
        // Work of a student an access restriction now shuts out (moved to an extension copy) no longer
        // counts here; a group's counts while any member can access it. Nothing in the activity changes,
        // so lifting the restriction brings it back at the next index (Pierre, 2026-10-09).
        $isout = fn($unit) => $unit->unittype === marking_row::UNIT_GROUP ? isset($shutout[$unit->unitid])
            : array_key_exists($unit->unitid, $students) && !$students[$unit->unitid];
        foreach (array_keys($markrows) as $i) {
            $row = $markrows[$i];
            unset($markrows[$i]);
            if ($isout($row)) {
                continue;
            }
            $key = $row->unittype . ':' . $row->unitid . ':' . $row->stage;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            // Work is ready to mark once the unit's own deadline has passed (G9).
            $unitdue = $row->unitdue ?? ($row->unittype === marking_row::UNIT_GROUP
                ? ($groupdues[$row->unitid] ?? $cmrow->due) : ($dues[$row->unitid] ?? $cmrow->due));
            $timeready = $row->timeready === null ? null : max($row->timeready, (int)$unitdue);
            $mark[] = (object)[
                'cmid' => $cmid,
                'unitid' => $row->unitid,
                'unittype' => $row->unittype,
                'stage' => $row->stage,
                // Unmarked work of a marker who left the module is unallocated, so it falls to the
                // convenor (G3); work they marked stays theirs (EC1).
                'allocatedto' => $row->allocatedto !== null && !$row->marked && !isset($markers[$row->allocatedto])
                    ? null : $row->allocatedto,
                'submitted' => $row->submitted ? 1 : 0,
                'timeready' => $timeready,
                'unitdue' => $unitdue,
                'marked' => $row->marked ? 1 : 0,
                'markedby' => $row->markedby,
                'timemarked' => $row->timemarked,
                'released' => $row->released ? 1 : 0,
                'flagged' => $row->flagged ? 1 : 0,
            ];
        }

        foreach ($this->kept_marking_rows($cmid, array_keys($markfailed), $only, $groupids) as $old) {
            $key = $old->unittype . ':' . $old->unitid . ':' . $old->stage;
            if (!isset($seen[$key]) && !$isout($old)) {
                $seen[$key] = true;
                $mark[] = $old;
            }
        }

        $failwrite = registry::take_test_write_failure($cmid);
        // Re-indexes of different assessments can deadlock in the database (MariaDB's gap locks, seen in the
        // deadline rush load test): the loser tries again, unless this is part of a larger transaction, which
        // the database has already undone.
        $retry = !$DB->is_transaction_started();
        for ($attempt = 1;; $attempt++) {
            $transaction = $DB->start_delegated_transaction();
            try {
                if ($only === null) {
                    $this->delete_rows($cmid);
                    $DB->insert_record(indexer::CM, $record);
                } else {
                    $this->delete_unit_rows($cmid, $only, $groupids);
                }
                if ($failwrite) {
                    throw new \moodle_exception('testfailure', 'block_le_dashboard', '', 'cm ' . $cmid);
                }
                if (registry::take_test_deadlock($cmid)) {
                    throw new \dml_write_exception('Deadlock found when trying to get lock (test)');
                }
                if ($stu) {
                    $DB->insert_records(indexer::STU, $stu);
                }
                if ($mark) {
                    $DB->insert_records(indexer::MARK, $mark);
                }
                $transaction->allow_commit();
                break;
            } catch (\Throwable $e) {
                if ($retry && $attempt < self::WRITE_ATTEMPTS && self::deadlock($e)) {
                    try {
                        $transaction->rollback($e);
                    } catch (\Throwable $undone) {
                        // Rolled back: try again shortly.
                        usleep(random_int(50000, 250000) * $attempt);
                        continue;
                    }
                }
                // Undo the half-written rows and close this transaction before anyone catches the error:
                // a caught error with the transaction still open would silently undo every later write
                // in the process (PR2). The course and reconcile loops then carry on.
                try {
                    $transaction->rollback($e);
                } finally {
                    registry::record_error($cm->modname, $e);
                    $this->load_error($cm, $adapter, $courseid, $types[$cmid], $cmrow);
                }
            }
        }
        if ($only !== null && ($failed || $markfailed)) {
            $DB->set_field(indexer::CM, 'loaderror', 1, ['cmid' => $cmid]);
        }
        if (!$failed && !$markfailed) {
            registry::clear_error($cm->modname);
        }
        if ($only === null) {
            // What is indexed changed: forget memoised grade item lookups in this process (a cron run is long).
            memo::reset();
        }
        return true;
    }

    /**
     * The assessment's index row.
     *
     * @param \cm_info $cm
     * @param adapter $adapter
     * @param int $courseid
     * @param string $type summative or formative
     * @param cm_row|null $cmrow Null when the adapter couldn't even read the activity.
     * @param bool $loaderror Whether student data couldn't be read, in whole or in part.
     * @return \stdClass
     */
    protected function cm_record(
        \cm_info $cm,
        adapter $adapter,
        int $courseid,
        string $type,
        ?cm_row $cmrow,
        bool $loaderror
    ): \stdClass {
        $section = $cm->get_section_info();
        return (object)[
            'cmid' => $cm->id,
            'courseid' => $courseid,
            'modname' => $cm->modname,
            // Without submissions it is shown like the generic tier: no Submitted bar, never Overdue (EC5).
            'adapter' => isset(registry::OWN[$cm->modname]) && $cmrow && $cmrow->submissions ? $cm->modname : 'generic',
            'name' => \core_text::substr($cm->get_name(), 0, 1333),
            'assesstype' => $type,
            'opens' => $cmrow?->opens,
            'due' => $cmrow?->due,
            'cutoff' => $cmrow?->cutoff,
            'markingdue' => $cmrow?->markingdue,
            'hiddenfromstudents' => (!$cm->visible || ($section && !$section->visible)) ? 1 : 0,
            'hasallocation' => $cmrow && $cmrow->hasallocation ? 1 : 0,
            'hasbrief' => self::has_brief($cm, $adapter) ? 1 : 0,
            'stages' => $cmrow ? implode(',', $cmrow->stages) : '',
            'nosubmission' => !$cmrow || $cmrow->submissions ? 0 : 1,
            'norelease' => !$cmrow || $cmrow->releases ? 0 : 1,
            'setup' => $cmrow ? (implode(',', self::setup_problems($cm, $cmrow, $type)) ?: null) : null,
            'loaderror' => $loaderror ? 1 : 0,
            'timeindexed' => $this->now ?? time(),
        ];
    }

    /**
     * Marks the assessment's row as not loaded, writing the row first if it has none, so it stays listed.
     *
     * @param \cm_info $cm
     * @param adapter $adapter
     * @param int $courseid
     * @param string $type
     * @param cm_row|null $cmrow
     */
    protected function load_error(\cm_info $cm, adapter $adapter, int $courseid, string $type, ?cm_row $cmrow): void {
        global $DB;
        try {
            if ($DB->record_exists(indexer::CM, ['cmid' => $cm->id])) {
                $DB->set_field(indexer::CM, 'loaderror', 1, ['cmid' => $cm->id]);
            } else {
                $DB->insert_record(indexer::CM, $this->cm_record($cm, $adapter, $courseid, $type, $cmrow, true));
            }
        } catch (\Throwable $e) {
            debugging('block_le_dashboard: marking cm ' . $cm->id . ' as not loaded failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Students plus everyone who shares a group with them on this activity (its grouping, or
     * the course), so team submissions are re-indexed whole.
     *
     * @param \cm_info $cm
     * @param int[] $userids
     * @return array [user ids, group ids]
     */
    protected function with_group_members(\cm_info $cm, array $userids): array {
        global $DB;
        $groupids = [];
        foreach ($userids as $userid) {
            $groups = groups_get_user_groups($cm->course, $userid);
            $groupids = array_merge($groupids, $groups[(int)$cm->groupingid] ?? []);
        }
        $groupids = array_values(array_unique(array_map('intval', $groupids)));
        if ($groupids) {
            [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
            $members = $DB->get_fieldset_select('groups_members', 'userid', "groupid $insql", $params);
            $userids = array_merge($userids, array_map('intval', $members));
        }
        return [array_values(array_unique($userids)), $groupids];
    }

    /**
     * The adapter's student rows. If the batch fails, each student is tried alone so one student's
     * bad data fails only their row.
     *
     * @param \block_le_dashboard\local\adapter\adapter $adapter
     * @param \cm_info $cm
     * @param cm_row $cmrow
     * @param int[] $userids
     * @return array [student_row[] keyed by user id, true[] keyed by the user ids that failed]
     */
    protected function student_rows($adapter, \cm_info $cm, cm_row $cmrow, array $userids): array {
        try {
            registry::check_test_part_failure('student', $userids);
            return [self::without_submissions($cmrow, $adapter->student_rows($cm, $cmrow, $userids)), []];
        } catch (\Throwable $e) {
            registry::record_error($cm->modname, $e);
        }
        $rows = [];
        $failed = [];
        foreach ($userids as $userid) {
            try {
                registry::check_test_part_failure('student', [$userid]);
                $rows += self::without_submissions($cmrow, $adapter->student_rows($cm, $cmrow, [$userid]));
            } catch (\Throwable $e) {
                $failed[$userid] = true;
            }
        }
        return [$rows, $failed];
    }

    /**
     * Where students hand nothing in online, there is no submission state to show. A lock counts only on
     * work handed in and not reopened: the row then stays in Upcoming until the deadline with a "Can't
     * resubmit" control (Pierre, 2026-10-09, reversing the 2026-10-06 rule that moved it to Past at once).
     *
     * @param cm_row $cmrow
     * @param student_row[] $rows Changed in place.
     * @return student_row[]
     */
    protected static function without_submissions(cm_row $cmrow, array $rows): array {
        foreach ($rows as $row) {
            if (!$cmrow->submissions) {
                $row->submitted = null;
                $row->timesubmitted = null;
                $row->reopened = false;
            }
            // Before anything is handed in, only a teacher's lock (B1) or every quiz attempt used (beta28) applies.
            $before = [student_row::LOCK_TEACHER, student_row::LOCK_ATTEMPTS];
            if (!$cmrow->submissions || (!$row->submitted && !in_array($row->lockreason, $before, true))) {
                $row->lockreason = null;
            }
        }
        return $rows;
    }

    /**
     * The adapter's marking rows. If the batch fails, each student is tried alone (their own unit,
     * or their group's), so one bad record fails only its unit (A7).
     *
     * @param \block_le_dashboard\local\adapter\adapter $adapter
     * @param \cm_info $cm
     * @param cm_row $cmrow
     * @param int[] $userids
     * @return array [marking_row[], true[] keyed by the user ids that failed]
     */
    protected function marking_rows($adapter, \cm_info $cm, cm_row $cmrow, array $userids): array {
        // Where students hand nothing in online, every student is ready at the deadline (shared rule).
        $read = ($cmrow->submissions || $adapter->marks_without_submissions())
            ? fn(array $ids) => $adapter->marking_rows($cm, $cmrow, $ids)
            : fn(array $ids) => \block_le_dashboard\local\adapter\base::no_submission_units($cm, $ids);
        try {
            registry::check_test_part_failure('marking', $userids);
            return [$read($userids), []];
        } catch (\Throwable $e) {
            registry::record_error($cm->modname, $e);
        }
        $rows = [];
        $failed = [];
        foreach ($userids as $userid) {
            try {
                registry::check_test_part_failure('marking', [$userid]);
                array_push($rows, ...$read([$userid]));
            } catch (\Throwable $e) {
                $failed[$userid] = true;
            }
        }
        return [$rows, $failed];
    }

    /**
     * Settings a tagged assessment is missing, for the staff "Check settings" flag (Pierre, 2026-10-07).
     * The grade checks are for summative work only: formative quizzes often hide marks on purpose.
     *
     * @param \cm_info $cm
     * @param cm_row $cmrow
     * @param string $type summative or formative
     * @return string[] Problems in format::SETUP_PROBLEMS.
     */
    protected static function setup_problems(\cm_info $cm, cm_row $cmrow, string $type): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $problems = [];
        if ($cmrow->due === null) {
            $problems[] = 'nodue';
        }
        if ($type === tags::SUMMATIVE) {
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance, 'itemnumber' => 0, 'courseid' => $cm->course]);
            $graded = $item && (int)$item->gradetype !== GRADE_TYPE_NONE
                && ((int)$item->gradetype !== GRADE_TYPE_VALUE || (float)$item->grademax > 0);
            if (!$graded) {
                $problems[] = 'nograde';
            } else if (!$cmrow->releases) {
                $problems[] = 'nomarks';
            }
            if (tags::has_any($cm->id, config::formative_tags())) {
                $problems[] = 'bothtags';
            }
        }
        return $problems;
    }

    /**
     * The indexed marking units of students whose re-index failed, to write back unchanged: their
     * own units, and every group unit (which group failed isn't known). Only units this re-index
     * replaces: all of them, or those of the students and groups it covers.
     *
     * @param int $cmid
     * @param int[] $userids
     * @param int[]|null $only
     * @param int[] $groupids
     * @return \stdClass[] without ids
     */
    protected function kept_marking_rows(int $cmid, array $userids, ?array $only, array $groupids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        $out = [];
        foreach ($DB->get_records(indexer::MARK, ['cmid' => $cmid]) as $row) {
            $id = (int)$row->unitid;
            $user = $row->unittype === marking_row::UNIT_USER;
            if (
                $user ? !in_array($id, $userids) || ($only !== null && !in_array($id, $only))
                    : $only !== null && !in_array($id, $groupids)
            ) {
                continue;
            }
            unset($row->id);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The indexed rows of students whose re-index failed, to write back unchanged.
     *
     * @param int $cmid
     * @param int[] $userids
     * @return \stdClass[] keyed by user id, without ids
     */
    protected function kept_student_rows(int $cmid, array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$usql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $out = [];
        foreach ($DB->get_records_select(indexer::STU, "cmid = :cmid AND userid $usql", $params + ['cmid' => $cmid]) as $row) {
            unset($row->id);
            $out[(int)$row->userid] = $row;
        }
        return $out;
    }

    /**
     * Deletes some students' rows of an assessment: their student rows, their own marking
     * units, and their groups' units.
     *
     * @param int $cmid
     * @param int[] $userids
     * @param int[] $groupids
     */
    protected function delete_unit_rows(int $cmid, array $userids, array $groupids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$usql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $DB->delete_records_select(indexer::STU, "cmid = :cmid AND userid $usql", $params + ['cmid' => $cmid]);
        $DB->delete_records_select(
            indexer::MARK,
            "cmid = :cmid AND unittype = :user AND unitid $usql",
            $params + ['cmid' => $cmid, 'user' => marking_row::UNIT_USER]
        );
        if ($groupids) {
            [$gsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
            $DB->delete_records_select(
                indexer::MARK,
                "cmid = :cmid AND unittype = :group AND unitid $gsql",
                $gparams + ['cmid' => $cmid, 'group' => marking_row::UNIT_GROUP]
            );
        }
    }

    /**
     * Each group unit's deadline: the latest effective deadline among its indexed members,
     * so a group with one extended member is not ready before that member's deadline. Also the
     * groups an access restriction shuts out: every indexed member is shut out.
     *
     * @param marking_row[] $markrows
     * @param array $dues userid => effective deadline
     * @param int|null $default The activity's deadline, for groups with no indexed member.
     * @param bool[] $students userid => whether they can access the activity
     * @return array [groupid => deadline, groupid => true for each group shut out]
     */
    protected function group_dues(array $markrows, array $dues, ?int $default, array $students = []): array {
        global $DB;
        $groupids = [];
        foreach ($markrows as $row) {
            if ($row->unittype === marking_row::UNIT_GROUP) {
                $groupids[$row->unitid] = true;
            }
        }
        if (!$groupids) {
            return [[], []];
        }
        $out = [];
        $access = [];
        [$insql, $params] = $DB->get_in_or_equal(array_keys($groupids), SQL_PARAMS_NAMED);
        $rs = $DB->get_recordset_select('groups_members', "groupid $insql", $params, '', 'id, groupid, userid');
        foreach ($rs as $m) {
            if (array_key_exists((int)$m->userid, $dues) && $dues[(int)$m->userid] !== null) {
                $out[(int)$m->groupid] = max($out[(int)$m->groupid] ?? 0, (int)$dues[(int)$m->userid]);
            }
            if (array_key_exists((int)$m->userid, $students)) {
                $access[(int)$m->groupid] = ($access[(int)$m->groupid] ?? false) || $students[(int)$m->userid];
            }
        }
        $rs->close();
        return [$out + array_fill_keys(array_keys($groupids), $default), array_filter($access, fn($can) => !$can)];
    }

    /**
     * Whether the activity has a description (intro text that isn't just markup)
     * or a brief file, in its intro or in the file areas its adapter names.
     *
     * @param \cm_info $cm
     * @param adapter $adapter
     * @return bool
     */
    public static function has_brief(\cm_info $cm, adapter $adapter): bool {
        global $DB;
        $columns = $DB->get_columns($cm->modname);
        if (isset($columns['intro'])) {
            $intro = (string)$DB->get_field($cm->modname, 'intro', ['id' => $cm->instance]);
            if (trim(html_entity_decode(strip_tags($intro), ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B\xC2\xA0") !== '') {
                return true;
            }
        }
        $fs = get_file_storage();
        foreach (array_merge(['intro'], $adapter->brief_areas()) as $area) {
            if (!$fs->is_area_empty($cm->context->id, 'mod_' . $cm->modname, $area, false, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deletes an assessment's rows from the three tables.
     *
     * @param int $cmid
     */
    protected function delete_rows(int $cmid): void {
        global $DB;
        $DB->delete_records(indexer::MARK, ['cmid' => $cmid]);
        $DB->delete_records(indexer::STU, ['cmid' => $cmid]);
        $DB->delete_records(indexer::CM, ['cmid' => $cmid]);
    }

    /**
     * Deletes an assessment's rows in one transaction. The caller holds the lock.
     *
     * @param int $cmid
     */
    public function drop_rows(int $cmid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->delete_rows($cmid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }
}
