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

namespace block_my_assessments\local\index;

use block_my_assessments\local\adapter\registry;
use block_my_assessments\local\config;
use block_my_assessments\local\tags;

/**
 * Fills the three index tables from the adapters.
 *
 * Re-indexing is idempotent: the rows for an assessment are deleted and
 * written again in one transaction. If an adapter fails, the previous rows
 * are kept (stale is better than missing), the error is recorded for the
 * admin page, and other assessments carry on.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class indexer {
    /** Assessment table. */
    const CM = 'block_my_assessments_cm';
    /** Student table. */
    const STU = 'block_my_assessments_stu';
    /** Marking table. */
    const MARK = 'block_my_assessments_mark';

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
     * Students of a course: active enrolment and one of the configured student roles.
     *
     * @param int $courseid
     * @return int[]
     */
    public static function students(int $courseid): array {
        global $DB;
        $roleids = config::role_ids('studentrole');
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
     * @param int $cmid
     * @return bool True if the assessment is in the index afterwards.
     */
    public function index_cm(int $cmid): bool {
        global $DB;
        $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if (!$courseid) {
            $this->remove_cm($cmid);
            return false;
        }
        $types = tags::types(null, [$cmid]);
        if (!isset($types[$cmid])) {
            $this->remove_cm($cmid);
            return false;
        }
        $modinfo = get_fast_modinfo($courseid);
        $cm = $modinfo->cms[$cmid] ?? null;
        if (!$cm || $cm->deletioninprogress) {
            $this->remove_cm($cmid);
            return false;
        }
        $adapter = registry::adapter_for($cm->modname);
        if (!$adapter) {
            $this->remove_cm($cmid);
            return false;
        }

        try {
            registry::check_test_failure($cm->modname);
            $cmrow = $adapter->cm_row($cm);
            if (!$cmrow || $cmrow->due === null) {
                // Nothing to bucket it by (R8).
                $this->remove_cm($cmid);
                registry::clear_error($cm->modname);
                return false;
            }
            $students = self::students($courseid);
            $students = $this->accessible($cm, $students);
            $sturows = $adapter->student_rows($cm, $cmrow, array_keys($students));
            $markrows = $adapter->marking_rows($cm, $cmrow, array_keys($students));
        } catch (\Throwable $e) {
            registry::record_error($cm->modname, $e);
            debugging(
                "block_my_assessments: indexing cm $cmid ({$cm->modname}) failed: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return $DB->record_exists(self::CM, ['cmid' => $cmid]);
        }

        $now = $this->now ?? time();
        $section = $cm->get_section_info();
        $record = (object)[
            'cmid' => $cmid,
            'courseid' => $courseid,
            'modname' => $cm->modname,
            'adapter' => isset(registry::OWN[$cm->modname]) ? $cm->modname : 'generic',
            'name' => \core_text::substr($cm->get_name(), 0, 1333),
            'assesstype' => $types[$cmid],
            'opens' => $cmrow->opens,
            'due' => $cmrow->due,
            'cutoff' => $cmrow->cutoff,
            'markingdue' => $cmrow->markingdue,
            'hiddenfromstudents' => (!$cm->visible || ($section && !$section->visible)) ? 1 : 0,
            'hasallocation' => $cmrow->hasallocation ? 1 : 0,
            'hasbrief' => self::has_brief($cm) ? 1 : 0,
            'stages' => implode(',', $cmrow->stages),
            'timeindexed' => $now,
        ];
        $stu = [];
        foreach ($students as $userid => $canaccess) {
            $row = $sturows[$userid] ?? new student_row($userid, $cmrow->due, $cmrow->cutoff);
            $stu[] = (object)[
                'cmid' => $cmid,
                'userid' => $userid,
                'due' => $row->due,
                'cutoff' => $row->cutoff,
                'individual' => $row->individual ? 1 : 0,
                'mitigation' => $row->mitigation,
                'canaccess' => $canaccess ? 1 : 0,
                'submitted' => $row->submitted === null ? null : ($row->submitted ? 1 : 0),
                'timesubmitted' => $row->timesubmitted,
                'timereleased' => $row->timereleased,
                'gradedisplay' => $row->gradedisplay === null ? null : \core_text::substr($row->gradedisplay, 0, 255),
            ];
        }
        $mark = [];
        $seen = [];
        foreach ($markrows as $row) {
            $key = $row->unittype . ':' . $row->unitid . ':' . $row->stage;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $mark[] = (object)[
                'cmid' => $cmid,
                'unitid' => $row->unitid,
                'unittype' => $row->unittype,
                'stage' => $row->stage,
                'allocatedto' => $row->allocatedto,
                'submitted' => $row->submitted ? 1 : 0,
                'marked' => $row->marked ? 1 : 0,
                'markedby' => $row->markedby,
                'timemarked' => $row->timemarked,
                'released' => $row->released ? 1 : 0,
            ];
        }

        $transaction = $DB->start_delegated_transaction();
        $this->delete_rows($cmid);
        $DB->insert_record(self::CM, $record);
        if ($stu) {
            $DB->insert_records(self::STU, $stu);
        }
        if ($mark) {
            $DB->insert_records(self::MARK, $mark);
        }
        $transaction->allow_commit();
        registry::clear_error($cm->modname);
        return true;
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
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $this->delete_rows($cmid);
        $transaction->allow_commit();
    }

    /**
     * Re-indexes every tagged assessment in a course and drops the course's rows that are no longer tagged.
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
        foreach ($tagged as $cmid) {
            $this->index_cm($cmid);
        }
    }

    /**
     * Re-indexes a list of assessments.
     *
     * @param int[] $cmids
     */
    public function index_cms(array $cmids): void {
        foreach (array_unique($cmids) as $cmid) {
            $this->index_cm((int)$cmid);
        }
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
    }

    /**
     * The nightly reconcile: every course with tagged assessments or index rows is re-indexed,
     * which also catches missed events, direct database edits and restores.
     *
     * @param callable|null $trace Receives progress lines.
     */
    public function reconcile(?callable $trace = null): void {
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
        foreach ($courses as $courseid) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                $this->remove_course($courseid);
                continue;
            }
            if ($trace) {
                $trace("Indexing course $courseid");
            }
            $this->index_course($courseid);
        }
    }

    /**
     * Queues a background re-index of one assessment.
     *
     * @param int $cmid
     */
    public static function queue_cm(int $cmid): void {
        $task = new \block_my_assessments\task\reindex();
        $task->set_custom_data(['cmid' => $cmid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queues a background re-index of a course.
     *
     * @param int $courseid
     */
    public static function queue_course(int $courseid): void {
        $task = new \block_my_assessments\task\reindex();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
