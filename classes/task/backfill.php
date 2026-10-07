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

namespace block_le_dashboard\task;

use block_le_dashboard\local\adapter\registry;
use block_le_dashboard\local\index\indexer;

/**
 * First fill of the index after install or upgrade. The dashboard says
 * "Preparing your assessments" until this has run.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill extends \core\task\adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('task_backfill', 'block_le_dashboard');
    }

    #[\Override]
    public function execute() {
        try {
            // What is indexed changed, so every course, not only the nightly live window (F5).
            (new indexer())->reconcile(fn($line) => mtrace($line), true);
        } finally {
            registry::flush_errors();
        }
        // A backfill queued while this one ran does the rest: "Preparing" stays until it has.
        if (!self::waiting((int)$this->get_id())) {
            self::mark_done();
        }
    }

    /**
     * Queues the backfill and marks the index as being prepared. Every writer goes through here
     * (install, settings, Activity types, Rebuild). One may wait while another runs: core's own
     * duplicate check also matches the running one, which has read its data already, so a change
     * made meanwhile would be lost (A1, A3).
     */
    public static function queue(): void {
        set_config('backfillpending', time(), 'block_le_dashboard');
        if (!self::waiting()) {
            \core\task\manager::queue_adhoc_task(new self());
        }
    }

    /**
     * Whether a backfill is queued and has not started.
     *
     * @param int $except This task's own id, when it asks.
     * @return bool
     */
    protected static function waiting(int $except = 0): bool {
        global $DB;
        return $DB->record_exists_select(
            'task_adhoc',
            'classname = :classname AND timestarted IS NULL AND id <> :except',
            ['classname' => '\\' . self::class, 'except' => $except]
        );
    }

    /**
     * The index is complete: "Preparing" goes.
     */
    public static function mark_done(): void {
        unset_config('backfillpending', 'block_le_dashboard');
        set_config('backfilldone', time(), 'block_le_dashboard');
    }

    /**
     * Whether a backfill is queued or running and has not failed. A failed one waits to retry and
     * must not hold up the nightly reconcile in the meantime.
     *
     * @return bool
     */
    public static function active(): bool {
        global $DB;
        return $DB->record_exists_select(
            'task_adhoc',
            'classname = :classname AND faildelay = 0',
            ['classname' => '\\' . self::class]
        );
    }

    /**
     * Whether a backfill is queued or running.
     *
     * @return bool
     */
    public static function pending(): bool {
        global $DB;
        return $DB->record_exists('task_adhoc', ['classname' => '\\' . self::class]);
    }

    /**
     * Empties the index and queues the backfill to fill it again. Students see
     * "Preparing your assessments" until it has run.
     */
    public static function rebuild(): void {
        indexer::purge();
        self::queue();
    }

    /**
     * Admin setting callback: a tag or role setting changed, so what is indexed changed (PR5).
     *
     * @param string $name Full setting name.
     */
    public static function setting_changed(string $name = ''): void {
        self::queue();
    }
}
