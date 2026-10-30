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

namespace block_le_dashboard;

use block_le_dashboard\local\adapter\registry;
use block_le_dashboard\local\index\indexer;
use block_le_dashboard\local\memo;

/**
 * Event observers: queue a re-index of whatever an event changed.
 *
 * Observers queue work (or delete rows), so they stay cheap on the request
 * that fired the event. The one exception is a student's own action on an
 * activity, whose rows are updated at once (F4). The adapters' own events are listed in
 * db/events.php from each adapter's events() list.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Whether the index is in use at all (no tags configured means nothing to index).
     *
     * @return bool
     */
    protected static function active(): bool {
        return (bool)(get_config('block_le_dashboard', 'certaintags') || get_config('block_le_dashboard', 'formativetags'));
    }

    /**
     * Staff events whose student's rows are updated at once, like a student's own action (B4): each adapter
     * names its own.
     *
     * @return string[]
     */
    protected static function for_related_user(): array {
        return memo::get('events:relateduser', function () {
            $events = [];
            foreach (registry::OWN as $class) {
                $events = array_merge($events, $class::events_for_related_user());
            }
            return $events;
        });
    }

    /**
     * An activity plugin's own event: re-index that activity.
     *
     * @param \core\event\base $event
     */
    public static function module_event(\core\event\base $event): void {
        global $DB;
        if (!self::active() || $event->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $cmid = (int)$event->contextinstanceid;
        // A student's own action (a submission, say) shows on their dashboard at once (F4): their
        // rows now, everyone else's in the queued task. So does a teacher's lock or unlock, for the
        // student it's about (B4).
        $userid = in_array('\\' . get_class($event), self::for_related_user()) ? $event->relateduserid : $event->userid;
        $delay = 0;
        if ($userid && $DB->record_exists('block_le_dashboard_stu', ['cmid' => $cmid, 'userid' => $userid])) {
            try {
                // A short wait: the student's request mustn't hang behind a slow re-index (A6).
                (new indexer())->index_users($cmid, [(int)$userid], indexer::INLINE_LOCK_WAIT);
                // Their rows are right: everyone else's can wait for the rest of the rush (deadline rush).
                $delay = indexer::COALESCE;
            } catch (\Throwable $e) {
                debugging('block_le_dashboard: updating the student\'s own rows failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        indexer::queue_cm($cmid, $delay);
    }

    /**
     * Course module created or updated.
     *
     * @param \core\event\base $event
     */
    public static function course_module_changed(\core\event\base $event): void {
        if (self::active()) {
            indexer::queue_cm((int)$event->objectid);
        }
    }

    /**
     * Course module deleted: drop its rows now.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        try {
            (new indexer())->remove_cm((int)$event->objectid);
        } catch (\moodle_exception $e) {
            // A re-index held the lock too long: the queued one finds the activity gone and removes it.
            indexer::queue_cm((int)$event->objectid);
        }
    }

    /**
     * Tag added to or removed from a course module.
     *
     * @param \core\event\base $event
     */
    public static function tag_changed(\core\event\base $event): void {
        if (($event->other['itemtype'] ?? '') === 'course_modules' && self::active()) {
            indexer::queue_cm((int)$event->other['itemid']);
        }
    }

    /**
     * Enrolment, role or group change in a course, or a role change on a category: re-index.
     *
     * @param \core\event\base $event
     */
    public static function course_people_changed(\core\event\base $event): void {
        if (!self::active()) {
            return;
        }
        if ($event->courseid && $event->courseid != SITEID) {
            indexer::queue_course((int)$event->courseid);
        } else if ($event->contextlevel == CONTEXT_COURSECAT) {
            // A role on a category counts in every course under it (A12): re-index the indexed ones.
            self::category_changed((int)$event->contextinstanceid);
        }
    }

    /**
     * Queues a re-index of every indexed course in a category and its subcategories.
     *
     * @param int $categoryid
     */
    protected static function category_changed(int $categoryid): void {
        global $DB;
        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if ($path === false) {
            return;
        }
        $courses = $DB->get_fieldset_sql(
            "SELECT DISTINCT c.id
               FROM {course} c
               JOIN {course_categories} cc ON cc.id = c.category
               JOIN {block_le_dashboard_cm} i ON i.courseid = c.id
              WHERE cc.id = :id OR " . $DB->sql_like('cc.path', ':path'),
            ['id' => $categoryid, 'path' => $path . '/%']
        );
        foreach ($courses as $courseid) {
            indexer::queue_course((int)$courseid);
        }
    }

    /**
     * Course restored: re-index it.
     *
     * @param \core\event\base $event
     */
    public static function course_restored(\core\event\base $event): void {
        if (self::active()) {
            indexer::queue_course((int)$event->objectid);
        }
    }

    /**
     * Course deleted: drop its rows.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        try {
            (new indexer())->remove_course((int)$event->objectid);
        } catch (\moodle_exception $e) {
            indexer::queue_course((int)$event->objectid);
        }
    }

    /**
     * User deleted: drop their rows.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        (new indexer())->remove_user((int)$event->objectid);
    }

    /**
     * A grade changed in the gradebook: re-index the activity if it is indexed (generic types, release).
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event): void {
        if (self::active() && ($cmid = self::cm_for_grade_item((int)($event->other['itemid'] ?? 0)))) {
            indexer::queue_cm($cmid);
        }
    }

    /**
     * A grade item changed: showing a hidden one releases its grades (F3).
     *
     * @param \core\event\grade_item_updated $event
     */
    public static function grade_item_updated(\core\event\grade_item_updated $event): void {
        if (self::active() && ($cmid = self::cm_for_grade_item((int)$event->objectid))) {
            indexer::queue_cm($cmid);
        }
    }

    /**
     * The indexed activity a grade item belongs to, if any. Looked up once per item per request,
     * since a regrade fires user_graded for every student on every item (F6).
     *
     * @param int $itemid
     * @return int|null
     */
    protected static function cm_for_grade_item(int $itemid): ?int {
        global $DB;
        return memo::get('gradeitem:' . $itemid, function () use ($DB, $itemid) {
            $cmid = $DB->get_field_sql(
                "SELECT i.cmid
                   FROM {grade_items} gi
                   JOIN {modules} m ON m.name = gi.itemmodule
                   JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = gi.iteminstance
                   JOIN {block_le_dashboard_cm} i ON i.cmid = cm.id
                  WHERE gi.id = :itemid AND gi.itemtype = 'mod'",
                ['itemid' => $itemid]
            );
            return $cmid ? (int)$cmid : null;
        });
    }

    /**
     * A calendar event changed: re-index its activity (generic types read their due date there).
     *
     * @param \core\event\base $event
     */
    public static function calendar_event_changed(\core\event\base $event): void {
        global $DB;
        if (!self::active()) {
            return;
        }
        try {
            $record = $event->get_record_snapshot('event', $event->objectid);
        } catch (\Throwable $e) {
            return;
        }
        if (empty($record->modulename) || empty($record->instance) || !registry::adapter_for($record->modulename)) {
            return;
        }
        $cm = get_coursemodule_from_instance($record->modulename, $record->instance, 0, false, IGNORE_MISSING);
        if ($cm) {
            indexer::queue_cm((int)$cm->id);
        }
    }
}
