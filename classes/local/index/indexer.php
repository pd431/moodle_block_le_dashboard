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
use block_le_dashboard\local\config;
use block_le_dashboard\local\memo;
use block_le_dashboard\local\tags;

/**
 * Keeps the three index tables in step with the site: the one entry point every caller uses.
 *
 * Behind it, writer fills an assessment's rows from its adapter, enrolment says who is in a course, and
 * queue holds the background re-indexes. This class adds the lock each re-index runs under, the course
 * and reconcile loops, and the index-wide maintenance (purge, counts, a user's rows).
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

    /** @var writer Writes each assessment's rows. */
    protected writer $writer;

    /**
     * Constructor.
     *
     * @param int|null $now Time to stamp rows with; defaults to now.
     */
    public function __construct(?int $now = null) {
        $this->now = $now;
        $this->writer = new writer($now);
    }

    /**
     * Students of a course, as enrolment::students().
     *
     * @param int $courseid
     * @param bool $onlyactive Only students whose enrolment is active now.
     * @return int[]
     */
    public static function students(int $courseid, bool $onlyactive = false): array {
        return enrolment::students($courseid, $onlyactive);
    }

    /**
     * When each student joined the course, as enrolment::enrol_starts().
     *
     * @param int $courseid
     * @param int[] $userids
     * @return int[] userid => time
     */
    public static function enrol_starts(int $courseid, array $userids): array {
        return enrolment::enrol_starts($courseid, $userids);
    }

    /**
     * Users who can mark in a course now, as enrolment::markers().
     *
     * @param int $courseid
     * @return int[]
     */
    public static function markers(int $courseid): array {
        return enrolment::markers($courseid);
    }

    /**
     * Whether the activity has a description or a brief file, as writer::has_brief().
     *
     * @param \cm_info $cm
     * @param adapter $adapter
     * @return bool
     */
    public static function has_brief(\cm_info $cm, adapter $adapter): bool {
        return writer::has_brief($cm, $adapter);
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
        return $this->locked($cmid, fn() => $this->writer->index_rows($cmid, null));
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
        return $this->locked($cmid, fn() => $this->writer->index_rows($cmid, array_map('intval', $userids)), $wait);
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

    /** Seconds a re-index waits for another re-index of the same assessment to finish. */
    const LOCK_WAIT = 60;

    /** The wait inside a web request (a submission, a student's Refresh): past it, the queued task does it (A6). */
    const INLINE_LOCK_WAIT = 2;

    /**
     * Removes an assessment from the index.
     *
     * @param int $cmid
     */
    public function remove_cm(int $cmid): void {
        // Under the assessment's lock, so a re-index already reading it can't put the rows back (PR1).
        $this->locked($cmid, function () use ($cmid) {
            $this->writer->drop_rows($cmid);
            return false;
        });
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
        foreach ($this->pass_courses($all) as $courseid) {
            $this->reconcile_course($courseid, $trace);
        }
    }

    /**
     * The courses a reconcile goes through, in course id order so a pass can carry on where it stopped
     * (pass). Rows of deleted activities and courses are removed on the way.
     *
     * @param bool $all Every course, whatever the live window.
     * @return int[]
     */
    public function pass_courses(bool $all = false): array {
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
        sort($courses);
        $live = $all ? null : $this->live_courses();
        $result = [];
        foreach ($courses as $courseid) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                $this->remove_course($courseid);
            } else if ($live === null || isset($live[$courseid])) {
                $result[] = $courseid;
            }
        }
        return $result;
    }

    /**
     * Re-indexes one course of a reconcile. A failure is recorded for the admin page and doesn't stop the run;
     * events, Refresh or the next run pick the course up again.
     *
     * @param int $courseid
     * @param callable|null $trace Receives progress lines.
     */
    public function reconcile_course(int $courseid, ?callable $trace = null): void {
        if ($trace) {
            $trace("Indexing course $courseid");
        }
        try {
            $this->index_course($courseid);
        } catch (\Throwable $e) {
            if ($trace) {
                $trace("Course $courseid: " . $e->getMessage());
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
     * Seconds a re-index queued by a student's own action waits, as queue::COALESCE.
     */
    const COALESCE = queue::COALESCE;

    /**
     * Queues a background re-index of one assessment, as queue::queue_cm().
     *
     * @param int $cmid
     * @param int $delay Seconds to hold it back, so a burst of events becomes one run (deadline rush).
     */
    public static function queue_cm(int $cmid, int $delay = 0): void {
        queue::queue_cm($cmid, $delay);
    }

    /**
     * Queues a background re-index of a course, as queue::queue_course().
     *
     * @param int $courseid
     */
    public static function queue_course(int $courseid): void {
        queue::queue_course($courseid);
    }

    /**
     * From now on, collects re-indexes and queues each once at the end of the request, as queue::defer().
     */
    public static function defer_queue(): void {
        queue::defer();
    }

    /**
     * Queues what was collected, as queue::flush().
     */
    public static function flush_queue(): void {
        queue::flush();
    }
}
