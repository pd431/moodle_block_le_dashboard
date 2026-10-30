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

/**
 * The queue of background re-indexes: one ad hoc task per assessment or course asked for, held back
 * or brought forward as needed, and collected to the end of a web request.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class queue {
    /**
     * Queues a background re-index of one assessment.
     *
     * @param int $cmid
     * @param int $delay Seconds to hold it back, so a burst of events becomes one run (deadline rush).
     */
    public static function queue_cm(int $cmid, int $delay = 0): void {
        self::add(['cmid' => $cmid], $delay);
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
        self::add(['courseid' => $courseid]);
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
    protected static function add(array $data, int $delay = 0): void {
        // Due now is a second ago, as core queues it: cron runs only tasks due before the time it starts.
        $runat = $delay ? time() + $delay : time() - 1;
        if (self::$pending === null && !CLI_SCRIPT) {
            self::defer();
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
    public static function defer(): void {
        if (self::$pending === null) {
            self::$pending = [];
            if (!self::$flushatend) {
                self::$flushatend = true;
                \core_shutdown_manager::register_function([self::class, 'flush']);
            }
        }
    }

    /** @var bool Whether the end of the request flushes the queue. */
    protected static bool $flushatend = false;

    /**
     * Queues what was collected, and goes back to queueing as re-indexes are asked for.
     */
    public static function flush(): void {
        $pending = self::$pending ?? [];
        self::$pending = null;
        foreach ($pending as $key => $runat) {
            self::queue_now(json_decode($key, true), $runat);
        }
    }

    /**
     * Queues one re-index now: see add().
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
