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
 * Re-indexes one assessment, a course, or a list of assessments (a queued Refresh).
 *
 * Custom data: cmid, or courseid, or cmids plus userid (the person who pressed Refresh).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reindex extends \core\task\adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('task_reindex', 'block_le_dashboard');
    }

    #[\Override]
    public function execute() {
        $data = (array)$this->get_custom_data();
        $indexer = new indexer();
        try {
            if (!empty($data['cmid'])) {
                $indexer->index_cm((int)$data['cmid']);
            }
            if (!empty($data['courseid'])) {
                $indexer->index_course((int)$data['courseid']);
            }
            if (!empty($data['cmids'])) {
                $indexer->index_cms(array_map('intval', (array)$data['cmids']));
            }
        } finally {
            // Refresh is over whether or not every activity indexed: the button comes back (PR7).
            if (!empty($data['userid'])) {
                unset_user_preference('block_le_dashboard_refreshing', (int)$data['userid']);
            }
            registry::flush_errors();
        }
    }
}
