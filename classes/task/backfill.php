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

namespace block_my_assessments\task;

use block_my_assessments\local\index\indexer;

/**
 * First fill of the index after install or upgrade. The dashboard says
 * "Preparing your assessments" until this has run.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill extends \core\task\adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('task_backfill', 'block_my_assessments');
    }

    #[\Override]
    public function execute() {
        (new indexer())->reconcile(fn($line) => mtrace($line));
        unset_config('backfillpending', 'block_my_assessments');
    }

    /**
     * Queues the backfill and marks the index as being prepared.
     */
    public static function queue(): void {
        set_config('backfillpending', time(), 'block_my_assessments');
        \core\task\manager::queue_adhoc_task(new self(), true);
    }
}
