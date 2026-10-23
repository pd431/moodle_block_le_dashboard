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

namespace block_le_dashboard\fixture;

/**
 * Writes peer assessments (mod_peerwork, where installed): a group's submission by one of its members.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class peerwork extends base {
    #[\Override]
    public function modname(): string {
        return 'peerwork';
    }

    #[\Override]
    protected function due_field(): string {
        return 'duedate';
    }

    #[\Override]
    protected function opens_field(): ?string {
        return 'fromdate';
    }

    #[\Override]
    public function submit_for_group(\cm_info $cm, int $userid, int $groupid): void {
        self::plugin_gen('mod_peerwork')->create_submission(['peerworkid' => $cm->instance, 'groupid' => $groupid,
            'userid' => $userid, 'timecreated' => time(), 'timemodified' => time()]);
        self::queue($cm);
    }
}
