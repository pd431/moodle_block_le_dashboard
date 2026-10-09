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

use block_le_dashboard\local\adapter\registry;
use block_le_dashboard\local\config;
use block_le_dashboard\local\memo;
use block_le_dashboard\local\tags;

/**
 * Fills the three index tables from the adapters.
 *
 * Re-indexing is idempotent: the rows for an assessment are deleted and
 * written again in one transaction. If an adapter fails, the previous rows
 * are kept (stale is better than missing), the error is recorded for the
 * admin page, and other assessments carry on.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class indexer {
    /** Assessment table. */
    const CM = 'block_le_dashboard_cm';
    /** Student table. */
    const STU = 'block_le_dashboard_stu';
    /** Marking table. */
    const MARK = 'block_le_dashboard_mark';

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

    /**
     * Students of a course: enrolled, suspended or ended enrolments included, with one of the
     * configured student roles. Submitted work of students who left still gets marked (EC4).
     *
     * @param int $courseid
     * @param bool $onlyactive Only students whose enrolment is active now.
     * @return int[]
     */
    public static function students(int $courseid, bool $onlyactive = false): array {
        global $DB;
        $roleids = config::role_ids('studentrole');
        if (!$roleids) {
            return [];
        }
        $context = \context_course::instance($courseid);
        [$esql, $params] = get_enrolled_sql($context, '', 0, $onlyactive);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                  JOIN ($esql) je ON je.id = u.id
                  JOIN {role_assignments} ra ON ra.userid = u.id
                 WHERE u.deleted = 0 AND ra.roleid $rolesql AND ra.contextid $ctxsql";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params + $roleparams + $ctxparams));
    }

    /**
     * When each student joined the course: the start of their earliest enrolment there, or when
     * it was made if it has no start date (EC10).
     *
     * @param int $courseid
     * @param int[] $userids
     * @return int[] userid => time
     */
    public static function enrol_starts(int $courseid, array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$usql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        return array_map('intval', $DB->get_records_sql_menu("SELECT ue.userid,
                                                                     MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart
                                                                              ELSE ue.timecreated END)
                                                                FROM {user_enrolments} ue
                                                                JOIN {enrol} e ON e.id = ue.enrolid
                                                               WHERE e.courseid = :courseid AND ue.userid $usql
                                                            GROUP BY ue.userid", $params));
    }

    /**
     * Users who can mark in a course now: an active enrolment and a staff or module convenor role
     * there or above (EC1).
     *
     * @param int $courseid
     * @return int[]
     */
    public static function markers(int $courseid): array {
        global $DB;
        $roleids = array_unique(array_merge(config::role_ids('staffrole'), config::role_ids('moduleconvenorrole')));
        if (!$roleids) {
            return [];
        }
        $context = \context_course::instance($courseid);
        [$esql, $params] = get_enrolled_sql($context, '', 0, true);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                  JOIN ($esql) je ON je.id = u.id
                  JOIN {role_assignments} ra ON ra.userid = u.id
                 WHERE u.deleted = 0 AND ra.roleid $rolesql AND ra.contextid $ctxsql";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params + $roleparams + $ctxparams));
    }

    /**
     * Re-indexes one assessment, or removes it from the index if it should no longer be listed.
     *
     * One re-index of an assessment at a time (F2): a second caller waits for the lock, then
     * reads fresh data.
     *
     * @param int $cmid
     * @return bool True if the assessment is in the index afterwards.
     */
    public function index_cm(int $cmid): bool {
        return $this->locked($cmid, fn() => $this->index_rows($cmid, null));
    }

    /**
     * Re-indexes some students of one assessment: their student rows and their marking units,
     * with everyone in their groups so group units stay whole (F4). An assessment not yet in
     * the index is indexed in full.
     *
     * @param int $cmid
     * @param int[] $userids
     * @param int $wait Seconds to wait for another re-index of the assessment.
     * @return bool True if the assessment is in the index afterwards.
     */
    public function index_users(int $cmid, array $userids, int $wait = self::LOCK_WAIT): bool {
        return $this->locked($cmid, fn() => $this->index_rows($cmid, array_map('intval', $userids)), $wait);
    }

    /**
     * Runs a re-index of one assessment under its lock.
     *
     * @param int $cmid
     * @param callable $index
     * @param int $wait Seconds to wait for the lock.
     * @return bool
     */
    protected function locked(int $cmid, callable $index, int $wait = self::LOCK_WAIT): bool {
        $factory = \core\lock\lock_config::get_lock_factory('block_le_dashboard');
        $lock = $factory->get_lock('cm' . $cmid, $wait);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'block_le_dashboard', '', $cmid);
        }
        try {
            return $index();
        } finally {
            $lock->release();
        }
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

    /** Seconds a re-index waits for another re-index of the same assessment to finish. */
    const LOCK_WAIT = 60;

    /** The wait inside a web request (a submission, a student's Refresh): past it, the queued task does it (A6). */
    const INLINE_LOCK_WAIT = 2;

    /**
     * The re-index itself: all students, or only some.
     *
     * @param int $cmid
     * @param int[]|null $only Students to re-index, or null for everyone.
     * @return bool
     */
    protected function index_rows(int $cmid, ?array $only): bool {
        global $DB;
        if ($only !== null && !$DB->record_exists(self::CM, ['cmid' => $cmid])) {
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
            if ($cmrow->submissions && registry::no_submission($cm)) {
                $cmrow->submissions = false;
            }
            if (!$cmrow->submissions && !$cmrow->stages && registry::no_submission($cm)) {
                // Marked from the gradebook (base::no_submission_units), so one marking stage.
                $cmrow->stages = ['marked'];
            }
            // A first index lists the assessment before reading any student, marked as not loaded, so a
            // failure, or a process that dies part way (memory, time limit), leaves it listed for staff
            // with an honest note rather than missing (Pierre, 2026-10-08).
            if (!$DB->record_exists(self::CM, ['cmid' => $cmid])) {
                $DB->insert_record(self::CM, $this->cm_record($cm, $courseid, $types[$cmid], $cmrow, true));
            }
            $students = self::students($courseid);
            $groupids = [];
            if ($only !== null) {
                [$only, $groupids] = $this->with_group_members($cm, $only);
                $students = array_values(array_intersect($students, $only));
            }
            $students = $this->accessible($cm, $students);
            $active = array_flip(self::students($courseid, true));
            $starts = self::enrol_starts($courseid, array_keys($students));
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
            $this->load_error($cm, $courseid, $types[$cmid], $cmrow ?? null);
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

        $record = $this->cm_record($cm, $courseid, $types[$cmid], $cmrow, $failed || $markfailed);
        $markers = array_flip(self::markers($courseid));
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
                    $DB->insert_record(self::CM, $record);
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
                    $DB->insert_records(self::STU, $stu);
                }
                if ($mark) {
                    $DB->insert_records(self::MARK, $mark);
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
                    $this->load_error($cm, $courseid, $types[$cmid], $cmrow);
                }
            }
        }
        if ($only !== null && ($failed || $markfailed)) {
            $DB->set_field(self::CM, 'loaderror', 1, ['cmid' => $cmid]);
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
     * @param int $courseid
     * @param string $type summative or formative
     * @param cm_row|null $cmrow Null when the adapter couldn't even read the activity.
     * @param bool $loaderror Whether student data couldn't be read, in whole or in part.
     * @return \stdClass
     */
    protected function cm_record(\cm_info $cm, int $courseid, string $type, ?cm_row $cmrow, bool $loaderror): \stdClass {
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
            'hasbrief' => self::has_brief($cm) ? 1 : 0,
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
     * @param int $courseid
     * @param string $type
     * @param cm_row|null $cmrow
     */
    protected function load_error(\cm_info $cm, int $courseid, string $type, ?cm_row $cmrow): void {
        global $DB;
        try {
            if ($DB->record_exists(self::CM, ['cmid' => $cm->id])) {
                $DB->set_field(self::CM, 'loaderror', 1, ['cmid' => $cm->id]);
            } else {
                $DB->insert_record(self::CM, $this->cm_record($cm, $courseid, $type, $cmrow, true));
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
            // Only a teacher's lock applies before anything is handed in (B1).
            if (!$cmrow->submissions || (!$row->submitted && $row->lockreason !== student_row::LOCK_TEACHER)) {
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
        foreach ($DB->get_records(self::MARK, ['cmid' => $cmid]) as $row) {
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
        foreach ($DB->get_records_select(self::STU, "cmid = :cmid AND userid $usql", $params + ['cmid' => $cmid]) as $row) {
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
        $DB->delete_records_select(self::STU, "cmid = :cmid AND userid $usql", $params + ['cmid' => $cmid]);
        $DB->delete_records_select(
            self::MARK,
            "cmid = :cmid AND unittype = :user AND unitid $usql",
            $params + ['cmid' => $cmid, 'user' => marking_row::UNIT_USER]
        );
        if ($groupids) {
            [$gsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
            $DB->delete_records_select(
                self::MARK,
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

    /** File areas that hold an activity's brief, besides its intro. */
    const BRIEF_AREAS = ['assign' => ['introattachment']];

    /**
     * Whether the activity has a description (intro text that isn't just markup)
     * or a brief file.
     *
     * @param \cm_info $cm
     * @return bool
     */
    public static function has_brief(\cm_info $cm): bool {
        global $DB;
        $columns = $DB->get_columns($cm->modname);
        if (isset($columns['intro'])) {
            $intro = (string)$DB->get_field($cm->modname, 'intro', ['id' => $cm->instance]);
            if (trim(html_entity_decode(strip_tags($intro), ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B\xC2\xA0") !== '') {
                return true;
            }
        }
        $fs = get_file_storage();
        foreach (array_merge(['intro'], self::BRIEF_AREAS[$cm->modname] ?? []) as $area) {
            if (!$fs->is_area_empty($cm->context->id, 'mod_' . $cm->modname, $area, false, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Which students can access the activity, as far as availability can tell for a list (groups,
     * groupings, profile fields). Date and completion conditions are checked live in the student
     * view instead.
     *
     * @param \cm_info $cm
     * @param int[] $userids
     * @return bool[] userid => can access
     */
    protected function accessible(\cm_info $cm, array $userids): array {
        $result = array_fill_keys($userids, false);
        if (!$userids) {
            return $result;
        }
        $users = [];
        foreach ($userids as $id) {
            $users[$id] = (object)['id' => $id];
        }
        $info = new \core_availability\info_module($cm);
        foreach ($info->filter_user_list($users) as $id => $unused) {
            $result[$id] = true;
        }
        return $result;
    }

    /**
     * Deletes an assessment's rows from the three tables.
     *
     * @param int $cmid
     */
    protected function delete_rows(int $cmid): void {
        global $DB;
        $DB->delete_records(self::MARK, ['cmid' => $cmid]);
        $DB->delete_records(self::STU, ['cmid' => $cmid]);
        $DB->delete_records(self::CM, ['cmid' => $cmid]);
    }

    /**
     * Removes an assessment from the index.
     *
     * @param int $cmid
     */
    public function remove_cm(int $cmid): void {
        // Under the assessment's lock, so a re-index already reading it can't put the rows back (PR1).
        $this->locked($cmid, function () use ($cmid) {
            $this->drop_rows($cmid);
            return false;
        });
    }

    /**
     * Deletes an assessment's rows in one transaction. The caller holds the lock.
     *
     * @param int $cmid
     */
    protected function drop_rows(int $cmid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->delete_rows($cmid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Re-indexes every tagged assessment in a course and drops the course's rows that are no longer tagged.
     * One assessment failing doesn't stop the others (F2); the first failure is thrown at the end,
     * so a task retries.
     *
     * @param int $courseid
     */
    public function index_course(int $courseid): void {
        global $DB;
        $tagged = array_keys(tags::types($courseid));
        $indexed = $DB->get_fieldset_select(self::CM, 'cmid', 'courseid = :courseid', ['courseid' => $courseid]);
        foreach (array_diff(array_map('intval', $indexed), $tagged) as $cmid) {
            $this->remove_cm($cmid);
        }
        $this->index_cms($tagged);
    }

    /**
     * Re-indexes a list of assessments, each on its own: one failing doesn't stop the others.
     * The first failure is thrown at the end.
     *
     * @param int[] $cmids
     */
    public function index_cms(array $cmids): void {
        $failure = null;
        foreach (array_unique($cmids) as $cmid) {
            try {
                $this->index_cm((int)$cmid);
            } catch (\Throwable $e) {
                debugging("block_le_dashboard: writing the index for cm $cmid failed: " . $e->getMessage(), DEBUG_DEVELOPER);
                $failure = $failure ?? $e;
            }
        }
        if ($failure) {
            throw $failure;
        }
    }

    /**
     * Empties the index: every marking, student and assessment row.
     */
    public static function purge(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        foreach ([self::MARK, self::STU, self::CM] as $table) {
            $DB->delete_records($table);
        }
        $transaction->allow_commit();
        memo::reset();
    }

    /**
     * How many rows each index table holds.
     *
     * @return int[] Keyed cm, stu and mark.
     */
    public static function counts(): array {
        global $DB;
        return ['cm' => $DB->count_records(self::CM), 'stu' => $DB->count_records(self::STU),
            'mark' => $DB->count_records(self::MARK)];
    }

    /**
     * Removes everything for a course (course deleted).
     *
     * @param int $courseid
     */
    public function remove_course(int $courseid): void {
        global $DB;
        foreach ($DB->get_fieldset_select(self::CM, 'cmid', 'courseid = :courseid', ['courseid' => $courseid]) as $cmid) {
            $this->remove_cm((int)$cmid);
        }
    }

    /**
     * Removes a user's own rows (user deleted, privacy request).
     *
     * @param int $userid
     */
    public function remove_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::STU, ['userid' => $userid]);
        $DB->delete_records(self::MARK, ['unitid' => $userid, 'unittype' => marking_row::UNIT_USER]);
        $DB->set_field(self::MARK, 'allocatedto', null, ['allocatedto' => $userid]);
        $DB->set_field(self::MARK, 'markedby', null, ['markedby' => $userid]);
        // Allocated markers on other students' rows, stored as ",id,id," (PR13).
        $DB->execute(
            "UPDATE {" . self::STU . "} SET allocatedto = REPLACE(allocatedto, :id, ',')
              WHERE " . $DB->sql_like('allocatedto', ':like'),
            ['id' => ',' . $userid . ',', 'like' => '%,' . $userid . ',%']
        );
        $DB->set_field(self::STU, 'allocatedto', null, ['allocatedto' => ',']);
    }

    /**
     * The nightly reconcile: courses with tagged assessments or index rows are re-indexed,
     * which also catches missed events, direct database edits and restores.
     *
     * Only live courses (F5): those with an indexed deadline within the live window or ahead, or
     * none (EC2), or with tagged assessments not yet indexed. Older courses keep their rows; events and
     * Refresh still update them. One course or assessment failing doesn't stop the run; failures
     * are recorded for the activity types admin page.
     *
     * @param callable|null $trace Receives progress lines.
     * @param bool $all Every course, whatever the live window: what is indexed changed (a backfill, F5).
     */
    public function reconcile(?callable $trace = null, bool $all = false): void {
        global $DB;
        // Rows whose course module has gone.
        $orphans = $DB->get_fieldset_sql("SELECT i.cmid
                                            FROM {" . self::CM . "} i
                                       LEFT JOIN {course_modules} cm ON cm.id = i.cmid
                                           WHERE cm.id IS NULL");
        foreach ($orphans as $cmid) {
            $this->remove_cm((int)$cmid);
        }
        $courses = array_unique(array_merge(
            array_map('intval', $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {" . self::CM . "}")),
            array_map('intval', $DB->get_fieldset_sql("SELECT DISTINCT cm.course
                                                         FROM {tag_instance} ti
                                                         JOIN {course_modules} cm ON cm.id = ti.itemid
                                                        WHERE ti.component = 'core' AND ti.itemtype = 'course_modules'"))
        ));
        $live = $all ? null : $this->live_courses();
        foreach ($courses as $courseid) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                $this->remove_course($courseid);
                continue;
            }
            if ($live !== null && !isset($live[$courseid])) {
                continue;
            }
            if ($trace) {
                $trace("Indexing course $courseid");
            }
            try {
                $this->index_course($courseid);
            } catch (\Throwable $e) {
                // Recorded for the admin page; events, Refresh or the next run pick it up again.
                if ($trace) {
                    $trace("Course $courseid: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Courses the reconcile re-indexes: an indexed deadline within the live window, ahead or unset, or a
     * tagged assessment not in the index yet. Null when the window is off (0 months): every course.
     *
     * @return array|null courseid => true
     */
    protected function live_courses(): ?array {
        global $DB;
        $months = config::number('reconcilemonths');
        if (!$months) {
            return null;
        }
        $since = ($this->now ?? time()) - $months * 30 * DAYSECS;
        $live = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {" . self::CM . "} WHERE due >= :since OR due IS NULL",
            ['since' => $since]
        );
        $new = $DB->get_fieldset_sql("SELECT DISTINCT cm.course
                                        FROM {tag_instance} ti
                                        JOIN {course_modules} cm ON cm.id = ti.itemid
                                   LEFT JOIN {" . self::CM . "} i ON i.cmid = cm.id
                                       WHERE ti.component = 'core' AND ti.itemtype = 'course_modules' AND i.id IS NULL");
        return array_fill_keys(array_map('intval', array_merge($live, $new)), true);
    }

    /**
     * Queues a background re-index of one assessment.
     *
     * @param int $cmid
     * @param int $delay Seconds to hold it back, so a burst of events becomes one run (deadline rush).
     */
    public static function queue_cm(int $cmid, int $delay = 0): void {
        self::queue(['cmid' => $cmid], $delay);
    }

    /**
     * Seconds a re-index queued by a student's own action waits, so the submissions of a deadline rush
     * become one run per assessment rather than one each. The student's own rows are updated at once
     * (observer::module_event), so only staff numbers wait.
     */
    const COALESCE = 120;

    /**
     * Queues a background re-index of a course.
     *
     * @param int $courseid
     */
    public static function queue_course(int $courseid): void {
        self::queue(['courseid' => $courseid]);
    }

    /**
     * Queues a re-index unless the same one is already waiting to start (F1). Core's own check
     * also matches a task that is running, which has read its data already: a change made
     * meanwhile would be lost. So one may wait while another runs; re-indexing is idempotent.
     *
     * A waiting one due later than this one would run is brought forward instead, so a held-back
     * re-index never delays one asked for now.
     *
     * @param array $data Task custom data.
     * @param int $delay Seconds to hold it back.
     */
    protected static function queue(array $data, int $delay = 0): void {
        $runat = time() + $delay;
        if (self::$pending === null && !CLI_SCRIPT) {
            self::defer_queue();
        }
        if (self::$pending !== null) {
            $key = json_encode($data);
            self::$pending[$key] = min(self::$pending[$key] ?? $runat, $runat);
            return;
        }
        self::queue_now($data, $runat);
    }

    /**
     * Re-indexes asked for, by task data, and the earliest each should run: kept until the end of the
     * request, or null when they are queued as they come.
     *
     * @var int[]|null
     */
    protected static ?array $pending = null;

    /**
     * From now on, collects re-indexes and queues each once, at the end of the request (P6). A web request
     * does this by itself: grading 3,000 students in one go then makes one queue check, not 3,000. Its
     * tasks can't be started by cron before the request has made all its changes either. A command line
     * process (cron) queues as it goes, as it may run for hours.
     */
    public static function defer_queue(): void {
        if (self::$pending === null) {
            self::$pending = [];
            if (!self::$flushatend) {
                self::$flushatend = true;
                \core_shutdown_manager::register_function([self::class, 'flush_queue']);
            }
        }
    }

    /** @var bool Whether the end of the request flushes the queue. */
    protected static bool $flushatend = false;

    /**
     * Queues what was collected, and goes back to queueing as re-indexes are asked for.
     */
    public static function flush_queue(): void {
        $pending = self::$pending ?? [];
        self::$pending = null;
        foreach ($pending as $key => $runat) {
            self::queue_now(json_decode($key, true), $runat);
        }
    }

    /**
     * Queues one re-index now: see queue().
     *
     * @param array $data Task custom data.
     * @param int $runat The earliest it should run.
     */
    protected static function queue_now(array $data, int $runat): void {
        global $DB;
        $task = new \block_le_dashboard\task\reindex();
        $task->set_custom_data($data);
        $waiting = $DB->get_record_select(
            'task_adhoc',
            'classname = :classname AND timestarted IS NULL AND ' . $DB->sql_compare_text('customdata', 255) . ' = ' .
                $DB->sql_compare_text(':customdata', 255),
            ['classname' => '\\' . get_class($task), 'customdata' => $task->get_custom_data_as_string()],
            'id, nextruntime',
            IGNORE_MULTIPLE
        );
        if (!$waiting) {
            if ($runat > time()) {
                $task->set_next_run_time($runat);
            }
            \core\task\manager::queue_adhoc_task($task);
        } else if ((int)$waiting->nextruntime > $runat) {
            $DB->set_field('task_adhoc', 'nextruntime', $runat, ['id' => $waiting->id]);
        }
    }
}
