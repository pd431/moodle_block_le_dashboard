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

    /**
     * Those of events() that a member of staff fires about one student (a lock, an unlock), whose rows are
     * updated at once as the student's own action would be (B4). The event's relateduserid is the student.
     *
     * @return string[]
     */
    public static function events_for_related_user(): array;

    /**
     * File areas of the activity, besides its intro, that hold its brief: a file there means it has one.
     *
     * @return string[]
     */
    public function brief_areas(): array;

    /**
     * Whether students of this type ever hand anything in online: false for a type that never takes
     * submissions (attendance), null to go by each activity (cm_row::$submissions).
     *
     * @return bool|null
     */
    public function takes_submissions(): ?bool;

    /**
     * Whether this type takes self-certified extensions (block_rhb): coursework only.
     *
     * @return bool
     */
    public function takes_selfcert(): bool;

    /**
     * The object block_rhb's self-certification link takes for one activity, or null when there is none
     * (the type takes no self-certification, or the activity can't be found).
     *
     * @param int $instanceid
     * @return object|null
     */
    public function selfcert_subject(int $instanceid): ?object;

    /**
     * Self-certified extensions a student has used since a time, by this type's own records, or null when
     * this type (or this site's schema) can't say.
     *
     * @param int $userid
     * @param int $since
     * @return int|null
     */
    public function selfcert_used(int $userid, int $since): ?int;
}
