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

namespace block_le_dashboard;

use block_le_dashboard\local\view\classifier as c;

/**
 * Tests for the section rules.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\view\classifier
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
            'reopened long after the deadline (G13)' => [[$now - 30 * $day, null, 0, $now - 60, true], c::UPCOMING,
                c::STATUS_REOPENED],
            'reopened past the cut-off (G13)' => [[$now - 2 * $day, $now - $day, 0, null, true], c::UPCOMING, c::STATUS_REOPENED],
            'no deadline, not submitted (EC2)' => [[null, null, 0, null], c::UNDATED, c::STATUS_PENDING],
            'no deadline, submitted (EC2)' => [[null, null, 1, null], c::UNDATED, c::STATUS_RECEIVED],
            'no deadline, released (EC2)' => [[null, null, 1, $now - 60], c::PAST, c::STATUS_RELEASED],
            'no deadline, reopened stays actionable (EC2)' => [[null, null, 0, null, true], c::UPCOMING, c::STATUS_REOPENED],
            'generic, no deadline (EC2)' => [[null, null, null, null], c::UNDATED, c::STATUS_OPEN],
            'generic, no deadline, graded (EC2)' => [[null, null, null, $now - 60], c::PAST, c::STATUS_RELEASED],
            'released without a submission (F1)' => [[$now - $day, null, 0, $now - 60], c::PAST, c::STATUS_RELEASED],
            'released without a submission, due ahead (F1)' => [[$now + $day, null, 0, $now - 60], c::UPCOMING,
                c::STATUS_RELEASED],
            'final before the deadline stays Upcoming (Pierre, 2026-10-09)' => [[$now + $day, $now - 60, 1, null],
                c::UPCOMING, c::STATUS_SUBMITTED],
            'final, then the deadline passed' => [[$now - $day, $now - 2 * $day, 1, null], c::PAST, c::STATUS_INMARKING],
            'closed quiz, nothing handed in: Past at once' => [[$now - 60, $now - 60, 0, null], c::PAST,
                c::STATUS_NOTSUBMITTED],
            'quiz override reopens the close' => [[$now + $day, $now + $day, 0, null], c::UPCOMING, c::STATUS_PENDING],
            'no online submission, date ahead' => [[$now + $day, null, null, null, false, ['nosubmission' => 1]],
                c::UPCOMING, c::STATUS_DUE],
            'no online submission, date passed' => [[$now - $day, null, null, null, false, ['nosubmission' => 1]],
                c::PAST, c::STATUS_AWAITING],
            'no online submission, graded' => [[$now - $day, null, null, $now - 60, false, ['nosubmission' => 1]],
                c::PAST, c::STATUS_RELEASED],
            'marks never released, marked (F10)' => [[$now - $day, $now - $day, 1, $now - 60, false, ['norelease' => 1]],
                c::PAST, c::STATUS_SUBMITTED],
            'marks never released, not marked (F10)' => [[$now - $day, $now - $day, 1, null, false, ['norelease' => 1]],
                c::PAST, c::STATUS_INMARKING],
            'marks never released, undated, marked (F10)' => [[null, null, 1, $now - 60, false, ['norelease' => 1]],
                c::PAST, c::STATUS_SUBMITTED],
        ];
    }

    /**
     * Student sections and statuses.
     *
     * @dataProvider student_provider
     * @param array $row due, cutoff, submitted, timereleased, reopened, other fields
     * @param string $section
     * @param string $status
     */
    public function test_student(array $row, string $section, string $status): void {
        [$due, $cutoff, $submitted, $released] = $row;
        $record = (object)(['due' => $due, 'cutoff' => $cutoff, 'submitted' => $submitted, 'timereleased' => $released,
            'reopened' => $row[4] ?? false] + ($row[5] ?? []));
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
     * A student who joined after the deadline (EC10): Overdue inside the grace period, then
     * Past and not submitted at once, not after the overdue threshold. An extension makes it Upcoming.
     */
    public function test_student_late_joiner(): void {
        $grace = 4 * HOURSECS;
        $row = (object)['due' => self::NOW - 3 * DAYSECS, 'cutoff' => null, 'submitted' => 0, 'timereleased' => null,
            'enrolstart' => self::NOW - DAYSECS];
        $this->assertSame([c::PAST, c::STATUS_NOTSUBMITTED], c::student($row, self::NOW, 90, $grace));
        $row->due = self::NOW - 2 * HOURSECS;
        $row->enrolstart = self::NOW - HOURSECS;
        $this->assertSame([c::OVERDUE, c::STATUS_NOTSUBMITTED], c::student($row, self::NOW, 90, $grace));
        $row->due = self::NOW + 5 * DAYSECS;
        $this->assertSame([c::UPCOMING, c::STATUS_PENDING], c::student($row, self::NOW, 90, $grace));
        // Enrolled before the deadline: Overdue as before.
        $row->due = self::NOW - 3 * DAYSECS;
        $row->enrolstart = self::NOW - 10 * DAYSECS;
        $this->assertSame([c::OVERDUE, c::STATUS_NOTSUBMITTED], c::student($row, self::NOW, 90, $grace));
    }

    /**
     * One rule decides whether work can still be handed in: the student's own cut-off, unless reopened.
     */
    public function test_submittable(): void {
        $row = (object)['cutoff' => null, 'reopened' => 0];
        $this->assertTrue(c::submittable($row, self::NOW));
        $row->cutoff = self::NOW;
        $this->assertTrue(c::submittable($row, self::NOW), 'open until the cut-off itself');
        $row->cutoff = self::NOW - 1;
        $this->assertFalse(c::submittable($row, self::NOW));
        $row->reopened = 1;
        $this->assertTrue(c::submittable($row, self::NOW));
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
        // Past the deadline with nothing of mine yet (extensions to come, or nothing handed in): nowhere.
        // EC2: no deadline and nothing to mark is Undated; work to mark still wins.
        $this->assertSame(c::UNDATED, c::staff(0, 0, null, self::NOW));
        $this->assertSame(c::COMPLETED, c::staff(0, 3, null, self::NOW), 'finished work goes to Past');
        $this->assertSame(c::TOMARK, c::staff(1, 3, null, self::NOW));
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
        // EC4: with every student gone, nothing more can arrive.
        $this->assertSame(c::CONVENOR_PAST, c::convenor(self::NOW - DAYSECS, self::NOW, 0, 0, 90, 0));
        $this->assertSame(c::INPROGRESS, c::convenor(self::NOW - DAYSECS, self::NOW, 0, 0, 90, 2));
        // EC2: no deadline is Undated unless something is outstanding.
        $this->assertSame(c::UNDATED, c::convenor(null, self::NOW, 0, 0, 90, 2));
        $this->assertSame(c::CONVENOR_PAST, c::convenor(null, self::NOW, 2, 0, 90, 2), 'finished work goes to Past');
        $this->assertSame(c::INPROGRESS, c::convenor(null, self::NOW, 2, 1, 90, 2));
        // F3: once nothing more can be handed in, nothing submitted and nothing outstanding is Past.
        $this->assertSame(c::CONVENOR_PAST, c::convenor(self::NOW - DAYSECS, self::NOW, 0, 0, 90, 2, true));
        $this->assertSame(c::INPROGRESS, c::convenor(self::NOW - DAYSECS, self::NOW, 0, 1, 90, 2, true));
    }

    /**
     * One unit, rounded down, switching before it would reach 0 (G5).
     */
    public function test_duration(): void {
        $f = fn($s) => \block_le_dashboard\local\output\format::duration($s);
        $this->assertSame('less than a minute', $f(59));
        $this->assertSame('1 min', $f(60));
        $this->assertSame('59 min', $f(HOURSECS - 1));
        $this->assertSame('1 h', $f(HOURSECS));
        $this->assertSame('23 h', $f(DAYSECS - 1));
        $this->assertSame('1 day', $f(DAYSECS));
        $this->assertSame('2 days', $f(2 * DAYSECS + HOURSECS));
        $this->assertSame('less than a minute', $f(-5));
    }
}
