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

namespace block_my_assessments\local\index;

/**
 * What an adapter knows about one student on one assessment.
 *
 * Dates are the student's effective dates: overrides, personal deadlines and
 * extensions are already applied by the adapter.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class student_row {
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
    ) {
    }
}
