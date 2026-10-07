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
 * Nightly reconcile: re-indexes everything, catching missed events and direct database edits.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile extends \core\task\scheduled_task {
    #[\Override]
    public function get_name() {
        return get_string('task_reconcile', 'block_le_dashboard');
    }

    #[\Override]
    public function execute() {
        // The backfill does the same work over the whole index: running both doubles the load and
        // races it, and finishing first would hide "Preparing" before the backfill is done.
        if (backfill::active()) {
            mtrace('A backfill is queued or running; it does this work, so the reconcile is skipped.');
            return;
        }
        try {
            (new indexer())->reconcile(fn($line) => mtrace($line));
        } finally {
            registry::flush_errors();
        }
        backfill::mark_done();
    }
}
