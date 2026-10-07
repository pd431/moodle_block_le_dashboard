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
use block_le_dashboard\local\index\marking_row;
use block_le_dashboard\local\index\student_row;

/**
 * An adapter turns one activity plugin's own data into index rows.
 *
 * Adapters run in the background (events, Refresh, nightly reconcile), never
 * on page load, so they may use the plugin's own API. They must batch by
 * assessment, not by student.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface adapter {
    /** Full support: the adapter knows submissions, marking and release. */
    const SUPPORT_FULL = 'full';
    /** Generic support: due date and gradebook only. */
    const SUPPORT_GENERIC = 'generic';

    /**
     * The module this adapter serves.
     *
     * @return string
     */
    public function modname(): string;

    /**
     * How much the block can know about this activity type.
     *
     * @return string One of the SUPPORT_ constants.
     */
    public function support(): string;

    /**
     * Dates and features of one assessment.
     *
     * @param \cm_info $cm
     * @return cm_row|null Null when the assessment cannot be listed. A null due date lists it as Undated (EC2).
     */
    public function cm_row(\cm_info $cm): ?cm_row;

    /**
     * Effective dates, submission and release for the given students.
     *
     * @param \cm_info $cm
     * @param cm_row $cmrow The row cm_row() returned.
     * @param int[] $userids Students to consider.
     * @return student_row[] Keyed by user id. Students left out get the default dates and "not submitted".
     */
    public function student_rows(\cm_info $cm, cm_row $cmrow, array $userids): array;

    /**
     * Marking units and their state, one row per unit per stage.
     *
     * @param \cm_info $cm
     * @param cm_row $cmrow The row cm_row() returned.
     * @param int[] $userids Students to consider.
     * @return marking_row[]
     */
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array;

    /**
     * Whether marking_rows() handles an assessment where students hand nothing in online. When not,
     * every student gets one unit, marked from the gradebook (base::no_submission_units()).
     *
     * @return bool
     */
    public function marks_without_submissions(): bool;

    /**
     * Event classes after which an assessment of this type should be re-indexed.
     *
     * Every class listed here must also be observed in db/events.php; a unit test checks this.
     *
     * @return string[]
     */
    public static function events(): array;
}
