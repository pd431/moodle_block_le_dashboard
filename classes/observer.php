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

namespace block_my_assessments;

use block_my_assessments\local\adapter\registry;
use block_my_assessments\local\index\indexer;

/**
 * Event observers: queue a re-index of whatever an event changed.
 *
 * Observers only queue work (or delete rows), so they stay cheap on the
 * request that fired the event. The adapters' own events are listed in
 * db/events.php from each adapter's events() list.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Whether the index is in use at all (no tags configured means nothing to index).
     *
     * @return bool
     */
    protected static function active(): bool {
        return (bool)(get_config('block_my_assessments', 'certaintags') || get_config('block_my_assessments', 'formativetags'));
    }

    /**
     * An activity plugin's own event: re-index that activity.
     *
     * @param \core\event\base $event
     */
    public static function module_event(\core\event\base $event): void {
        if (self::active() && $event->contextlevel == CONTEXT_MODULE) {
            indexer::queue_cm((int)$event->contextinstanceid);
        }
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
        (new indexer())->remove_cm((int)$event->objectid);
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
     * Enrolment, role or group change in a course: re-index the course.
     *
     * @param \core\event\base $event
     */
    public static function course_people_changed(\core\event\base $event): void {
        if (self::active() && $event->courseid && $event->courseid != SITEID) {
            indexer::queue_course((int)$event->courseid);
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
        (new indexer())->remove_course((int)$event->objectid);
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
        global $DB;
        if (!self::active()) {
            return;
        }
        $cmid = $DB->get_field_sql(
            "SELECT i.cmid
                                      FROM {grade_items} gi
                                      JOIN {modules} m ON m.name = gi.itemmodule
                                      JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = gi.iteminstance
                                      JOIN {block_my_assessments_cm} i ON i.cmid = cm.id
                                     WHERE gi.id = :itemid AND gi.itemtype = 'mod'",
            ['itemid' => $event->other['itemid'] ?? 0]
        );
        if ($cmid) {
            indexer::queue_cm((int)$cmid);
        }
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
