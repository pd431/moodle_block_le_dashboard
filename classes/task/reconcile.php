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
use block_le_dashboard\local\config;
use block_le_dashboard\local\index\pass;

/**
 * The hourly index task. It carries on an unfinished first fill at any hour, so the
 * index fills even where ad hoc tasks don't run, and otherwise does the nightly check between 02:00 and
 * 06:00, which re-indexes live courses and catches missed events and direct database edits. Each run stops
 * at the time limit and the next one carries on (pass).
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
        $this->run(config::now());
    }

    /**
     * One run, at a given time.
     *
     * @param int $now
     * @return string|null How the pass ended (pass::DONE, LIMIT, RESTARTED, LOCKED), null when there was nothing to do.
     */
    public function run(int $now): ?string {
        $trace = fn($line) => mtrace($line);
        try {
            if (get_config('block_le_dashboard', 'backfillpending')) {
                // The first fill comes first, whatever the hour: students see "Preparing" until it is done.
                $result = pass::run(pass::FILL, $trace, pass::deadline());
                if ($result === pass::DONE && pass::complete(pass::FILL)) {
                    backfill::mark_done();
                }
                return $result;
            }
            if (!pass::night($now)) {
                mtrace('Outside the nightly check\'s hours, or tonight\'s check is complete.');
                return null;
            }
            return pass::run(pass::NIGHTLY, $trace, pass::deadline(), 0, $now);
        } finally {
            registry::flush_errors();
        }
    }
}
