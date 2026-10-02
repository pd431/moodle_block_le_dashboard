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

namespace block_my_assessments;

use block_my_assessments\local\view\classifier as c;

/**
 * Tests for the section rules.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\local\view\classifier
 */
final class classifier_test extends \basic_testcase {
    /** @var int Fixed "now". */
    const NOW = 1790000000;

    /**
     * Student rows and where they go.
     *
     * @return array
     */
    public static function student_provider(): array {
        $now = self::NOW;
        $day = DAYSECS;
        return [
            'not submitted, due ahead' => [[$now + $day, null, 0, null], c::UPCOMING, c::STATUS_PENDING],
            'not submitted, just overdue' => [[$now - $day, null, 0, null], c::OVERDUE, c::STATUS_NOTSUBMITTED],
            'not submitted, overdue at threshold' => [[$now - 90 * $day, null, 0, null], c::OVERDUE, c::STATUS_NOTSUBMITTED],
            'not submitted, past threshold' => [[$now - 91 * $day, null, 0, null], c::PAST, c::STATUS_NOTSUBMITTED],
            'not submitted, past cut-off (R2)' => [[$now - 2 * $day, $now - $day, 0, null], c::PAST, c::STATUS_NOTSUBMITTED],
            'not submitted, before cut-off' => [[$now - 2 * $day, $now + $day, 0, null], c::OVERDUE, c::STATUS_NOTSUBMITTED],
            'submitted, due ahead' => [[$now + $day, null, 1, null], c::UPCOMING, c::STATUS_SUBMITTED],
            'submitted, due passed' => [[$now - $day, null, 1, null], c::PAST, c::STATUS_INMARKING],
            'submitted, released' => [[$now - $day, null, 1, $now - 60], c::PAST, c::STATUS_RELEASED],
            'submitted, release scheduled' => [[$now - $day, null, 1, $now + 60], c::PAST, c::STATUS_INMARKING],
            'generic, due ahead (R5)' => [[$now + $day, null, null, null], c::UPCOMING, c::STATUS_DUE],
            'generic, due passed is never overdue (R5)' => [[$now - $day, null, null, null], c::PAST, c::STATUS_CLOSED],
            'generic, graded' => [[$now - $day, null, null, $now - 60], c::PAST, c::STATUS_RELEASED],
        ];
    }

    /**
     * Student sections and statuses.
     *
     * @dataProvider student_provider
     * @param array $row due, cutoff, submitted, timereleased
     * @param string $section
     * @param string $status
     */
    public function test_student(array $row, string $section, string $status): void {
        [$due, $cutoff, $submitted, $released] = $row;
        $record = (object)['due' => $due, 'cutoff' => $cutoff, 'submitted' => $submitted, 'timereleased' => $released];
        $this->assertSame([$section, $status], c::student($record, self::NOW, 90));
    }

    /**
     * The deadline moves an assessment without re-indexing: the same row, an hour apart.
     */
    public function test_student_moves_at_deadline(): void {
        $row = (object)['due' => self::NOW, 'cutoff' => null, 'submitted' => 0, 'timereleased' => null];
        $this->assertSame(c::UPCOMING, c::student($row, self::NOW - HOURSECS, 90)[0]);
        $this->assertSame(c::OVERDUE, c::student($row, self::NOW + HOURSECS, 90)[0]);
    }

    /**
     * Marker sections.
     */
    public function test_staff(): void {
        $this->assertSame(c::TOMARK, c::staff(2, 3, self::NOW - DAYSECS, self::NOW));
        $this->assertSame(c::TOMARK, c::staff(1, 1, self::NOW + DAYSECS, self::NOW));
        $this->assertSame(c::COMPLETED, c::staff(0, 3, self::NOW - DAYSECS, self::NOW));
        $this->assertNull(c::staff(0, 3, self::NOW + DAYSECS, self::NOW));
        $this->assertNull(c::staff(0, 0, self::NOW - DAYSECS, self::NOW));
    }

    /**
     * Convenor sections.
     */
    public function test_convenor(): void {
        $this->assertSame(c::STAFF_UPCOMING, c::convenor(self::NOW + DAYSECS, self::NOW, 5, 5, 90));
        $this->assertSame(c::INPROGRESS, c::convenor(self::NOW - DAYSECS, self::NOW, 5, 1, 90));
        $this->assertSame(c::CONVENOR_PAST, c::convenor(self::NOW - DAYSECS, self::NOW, 5, 0, 90));
        // Nothing submitted yet: still in progress for the threshold, then past.
        $this->assertSame(c::INPROGRESS, c::convenor(self::NOW - DAYSECS, self::NOW, 0, 0, 90));
        $this->assertSame(c::CONVENOR_PAST, c::convenor(self::NOW - 91 * DAYSECS, self::NOW, 0, 0, 90));
    }
}
