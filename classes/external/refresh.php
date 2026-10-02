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

namespace block_my_assessments\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use block_my_assessments\local\config;
use block_my_assessments\local\index\indexer;
use block_my_assessments\local\view\factory;

/**
 * Refresh (R4): re-indexes the viewer's own scope. Small scopes (a student,
 * most markers) are done inline; a large one (a convenor of many modules)
 * is queued, and the view says "Refreshing" until the task has run.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh extends external_api {
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
        $cmids = $object->scope_cmids();
        if (count($cmids) <= config::inline_refresh_limit()) {
            (new indexer())->index_cms($cmids);
            $status = 'done';
        } else {
            set_user_preference('block_my_assessments_refreshing', time());
            $task = new \block_my_assessments\task\reindex();
            $task->set_custom_data(['cmids' => array_values($cmids), 'userid' => (int)$USER->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            $status = 'queued';
        }
        $object = factory::make($view, (int)$USER->id, $year);
        $output = $PAGE->get_renderer('core');
        return [
            'status' => $status,
            'html' => $output->render_from_template('block_my_assessments/view', $object->export()),
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
