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

namespace block_le_dashboard\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use block_le_dashboard\local\config;
use block_le_dashboard\local\index\indexer;
use block_le_dashboard\local\scope;
use block_le_dashboard\local\view\factory;

/**
 * Refresh (R4): re-indexes the viewer's own scope. Small scopes (a student,
 * most markers) are done inline; a large one (a convenor of many modules)
 * is queued, and the view says "Refreshing" until the task has run.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh extends external_api {
    /** Seconds within which a second Refresh re-indexes nothing (F9). */
    const MIN_INTERVAL = 60;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return get_view::execute_parameters();
    }

    /**
     * Refreshes and re-renders the view.
     *
     * @param string $view
     * @param int $yearid
     * @return array
     */
    public static function execute(string $view, int $yearid = -1): array {
        global $USER, $PAGE;
        ['view' => $view, 'yearid' => $yearid] = self::validate_parameters(
            self::execute_parameters(),
            ['view' => $view, 'yearid' => $yearid]
        );
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        $year = $yearid < 0 ? null : $yearid;
        $object = factory::make($view, (int)$USER->id, $year);
        $cmids = $object->refresh_cmids();
        $last = (int)get_user_preferences('block_le_dashboard_lastrefresh', 0, $USER->id);
        if ($last > time() - self::MIN_INTERVAL) {
            // Pressed again within the minute: the index is as fresh as it gets (F9).
            $status = 'done';
        } else if ($view === scope::STUDENT) {
            // A student's Refresh rewrites only their own rows (F4).
            set_user_preference('block_le_dashboard_lastrefresh', time());
            $indexer = new indexer();
            foreach ($cmids as $cmid) {
                $indexer->index_users($cmid, [(int)$USER->id]);
            }
            $status = 'done';
        } else if (count($cmids) <= config::inline_refresh_limit()) {
            set_user_preference('block_le_dashboard_lastrefresh', time());
            (new indexer())->index_cms($cmids);
            $status = 'done';
        } else {
            set_user_preference('block_le_dashboard_lastrefresh', time());
            set_user_preference('block_le_dashboard_refreshing', time());
            $task = new \block_le_dashboard\task\reindex();
            $task->set_custom_data(['cmids' => array_values($cmids), 'userid' => (int)$USER->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            $status = 'queued';
        }
        $object = factory::make($view, (int)$USER->id, $year);
        $output = $PAGE->get_renderer('core');
        return [
            'status' => $status,
            'html' => $output->render_from_template('block_le_dashboard/view', $object->export()),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'done or queued'),
            'html' => new external_value(PARAM_RAW, 'Rendered view'),
        ]);
    }
}
