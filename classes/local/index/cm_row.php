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
 * What an adapter knows about one assessment, before any student is considered.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cm_row {
    /**
     * Constructor.
     *
     * @param int|null $opens When submissions open, if the activity has such a date.
     * @param int|null $due The default deadline. Null when the activity has none.
     * @param int|null $cutoff The default cut-off, after which nothing can be submitted.
     * @param int|null $markingdue When marking is due, if the activity has such a date.
     * @param bool $hasallocation Whether units are allocated to named markers.
     * @param string[] $stages Marking stage keys in order (see stage strings in the lang file).
     * @param bool $submissions Whether students submit (false: an assignment that only records a mark).
     */
    public function __construct(
        /** @var int|null When submissions open. */
        public ?int $opens = null,
        /** @var int|null Default deadline. */
        public ?int $due = null,
        /** @var int|null Default cut-off. */
        public ?int $cutoff = null,
        /** @var int|null Marking deadline. */
        public ?int $markingdue = null,
        /** @var bool Whether units are allocated to markers. */
        public bool $hasallocation = false,
        /** @var string[] Marking stage keys, in order. */
        public array $stages = ['marked'],
        /** @var bool Whether students submit here; false lists it like the generic tier (EC5). */
        public bool $submissions = true,
    ) {
    }
}
