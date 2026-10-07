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

namespace block_le_dashboard\local\index;

/**
 * What an adapter knows about one student on one assessment.
 *
 * Dates are the student's effective dates: overrides, personal deadlines and
 * extensions are already applied by the adapter.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class student_row {
    /** An extension was granted. */
    const MITIGATION_EXTENSION = 'extension';

    /** A self-certified extension was applied. */
    const MITIGATION_SELFCERT = 'selfcert';

    /**
     * Constructor.
     *
     * @param int $userid The student.
     * @param int|null $due Effective deadline.
     * @param int|null $cutoff Effective cut-off.
     * @param bool $individual Whether an individual date (override, extension...) applies.
     * @param bool|null $submitted Whether the student has submitted; null when the adapter cannot tell.
     * @param int|null $timesubmitted When they submitted.
     * @param int|null $timereleased When the grade is (or will be) visible to the student; null if not released.
     * @param string|null $gradedisplay The grade as the student should see it.
     * @param string|null $mitigation MITIGATION_EXTENSION or MITIGATION_SELFCERT when one applies.
     * @param bool $reopened Whether a new attempt is open and not yet submitted.
     * @param int[] $allocatedto Markers allocated to the student, on activities with allocation.
     * @param bool $locked Whether the student's submission is final and can't be changed: the indexer ends
     *                     their cut-off when they submitted, so the work leaves Upcoming (Pierre, 2026-10-06).
     */
    public function __construct(
        /** @var int The student. */
        public int $userid,
        /** @var int|null Effective deadline. */
        public ?int $due = null,
        /** @var int|null Effective cut-off. */
        public ?int $cutoff = null,
        /** @var bool An individual date applies. */
        public bool $individual = false,
        /** @var bool|null Submitted, or null when unknown. */
        public ?bool $submitted = false,
        /** @var int|null When submitted. */
        public ?int $timesubmitted = null,
        /** @var int|null When released. */
        public ?int $timereleased = null,
        /** @var string|null Grade as shown to the student. */
        public ?string $gradedisplay = null,
        /** @var string|null Mitigation that applies, if any. */
        public ?string $mitigation = null,
        /** @var bool A new attempt is open. */
        public bool $reopened = false,
        /** @var int[] Allocated markers. */
        public array $allocatedto = [],
        /** @var bool The submission is final and can't be changed. */
        public bool $locked = false,
    ) {
    }
}
