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
 * One marking unit (a student or a group) at one marking stage.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class marking_row {
    /** Unit is a single student. */
    const UNIT_USER = 'user';
    /** Unit is a group (one submission per group). */
    const UNIT_GROUP = 'group';

    /**
     * Constructor.
     *
     * @param int $unitid User or group id.
     * @param string $unittype One of the UNIT_ constants.
     * @param string $stage Stage key, one of the cm_row's stages.
     * @param int|null $allocatedto Marker allocated to this unit at this stage, if any.
     * @param bool $submitted Whether the unit is ready to be marked at this stage: it has submitted, and
     *                        any stage this one depends on (agreement, moderation) is done.
     * @param bool $marked Whether this stage's marking is done.
     * @param int|null $markedby Who marked it, when known.
     * @param int|null $timemarked When it was marked, when known.
     * @param bool $released Whether the agreed or final grade is published to the student (D4).
     */
    public function __construct(
        /** @var int User or group id. */
        public int $unitid,
        /** @var string user or group. */
        public string $unittype,
        /** @var string Stage key. */
        public string $stage,
        /** @var int|null Allocated marker. */
        public ?int $allocatedto = null,
        /** @var bool Submitted. */
        public bool $submitted = true,
        /** @var bool Marked at this stage. */
        public bool $marked = false,
        /** @var int|null Who marked. */
        public ?int $markedby = null,
        /** @var int|null When marked. */
        public ?int $timemarked = null,
        /** @var bool Final grade published. */
        public bool $released = false,
    ) {
    }
}
