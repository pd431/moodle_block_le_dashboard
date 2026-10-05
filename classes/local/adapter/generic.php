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

namespace block_le_dashboard\local\adapter;

use block_le_dashboard\local\index\cm_row;

/**
 * Adapter for any activity type without its own (R5): the due date comes
 * from the module's calendar event, the grade and its release from the
 * gradebook. Submission is unknown (NULL), so these assessments are
 * bucketed by date only: Upcoming before the deadline, Past after it,
 * never Overdue. An activity with no due date in the calendar is listed
 * as Undated (EC2).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generic extends base {
    /** Calendar event types read as the deadline, in order of preference. */
    const DUE_EVENT_TYPES = ['due', 'close', 'closesubmission'];

    /**
     * Constructor.
     *
     * @param string $modname The module this instance serves.
     */
    public function __construct(
        /** @var string The module this instance serves. */
        protected string $modname
    ) {
    }

    #[\Override]
    public function modname(): string {
        return $this->modname;
    }

    #[\Override]
    public function support(): string {
        return self::SUPPORT_GENERIC;
    }

    #[\Override]
    public static function events(): array {
        return [
            '\core\event\calendar_event_created',
            '\core\event\calendar_event_updated',
            '\core\event\calendar_event_deleted',
            '\core\event\user_graded',
        ];
    }

    #[\Override]
    public function cm_row(\cm_info $cm): ?cm_row {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::DUE_EVENT_TYPES, SQL_PARAMS_NAMED, 't');
        $params += ['modulename' => $cm->modname, 'instance' => $cm->instance];
        $events = $DB->get_records_select(
            'event',
            "modulename = :modulename AND instance = :instance AND priority IS NULL AND eventtype $insql",
            $params,
            '',
            'id, eventtype, timestart'
        );
        $due = null;
        foreach (self::DUE_EVENT_TYPES as $type) {
            foreach ($events as $e) {
                if ($e->eventtype === $type && $e->timestart) {
                    $due = (int)$e->timestart;
                    break 2;
                }
            }
        }
        // No deadline event: listed as Undated (EC2).
        return new cm_row(due: $due, stages: []);
    }

    #[\Override]
    public function student_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        $rows = self::default_rows($cmrow, $userids);
        foreach ($rows as $row) {
            $row->submitted = null;
        }
        self::apply_gradebook_release($cm, $rows);
        return $rows;
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        return [];
    }
}
