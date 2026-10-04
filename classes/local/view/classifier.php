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

namespace block_my_assessments\local\view;

/**
 * The rules that put assessments into sections. Pure functions of stored
 * values and "now", so buckets change at the deadline without re-indexing.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classifier {
    /** Student section: past the deadline, not submitted, still actionable. */
    const OVERDUE = 'overdue';
    /** Student section: deadline ahead. */
    const UPCOMING = 'upcoming';
    /** Student section: everything else. */
    const PAST = 'past';
    /** Section in every view: no deadline and nothing needing action now (EC2). */
    const UNDATED = 'undated';

    /** Status: not submitted yet, deadline ahead. */
    const STATUS_PENDING = 'pending';
    /** Status: not submitted and the deadline (or cut-off) has passed. */
    const STATUS_NOTSUBMITTED = 'notsubmitted';
    /** Status: submitted, not marked or released yet. */
    const STATUS_SUBMITTED = 'submitted';
    /** Status: submitted, deadline passed, grade not released yet. */
    const STATUS_INMARKING = 'inmarking';
    /** Status: grade released. */
    const STATUS_RELEASED = 'released';
    /** Status: the block can't know about submissions (generic types). */
    const STATUS_DUE = 'due';
    /** Status: generic type past its deadline. */
    const STATUS_CLOSED = 'closed';
    /** Status: a new attempt is open (assign "Allow another attempt"), whatever the deadline. */
    const STATUS_REOPENED = 'reopened';
    /** Status: generic type with no deadline (EC2). */
    const STATUS_OPEN = 'open';
    /** Status: handed in, no deadline, not graded yet: stays in Undated until it is (Pierre, 2026-10-04). */
    const STATUS_RECEIVED = 'received';
    /** Statuses of the ordinary case, shown with no pill (Pierre, 2026-10-04). */
    const PLAIN_STATUSES = [self::STATUS_PENDING, self::STATUS_DUE, self::STATUS_OPEN];

    /**
     * Where a student's assessment goes, and its status.
     *
     * - Generic types (submitted unknown): Upcoming before the deadline, Past after, never Overdue (R5).
     * - Unsubmitted after the cut-off: Past, "Not submitted" (R2).
     * - Unsubmitted after the deadline: Overdue for the threshold, then Past.
     * - Submitted: Upcoming until the deadline, then Past (in marking, or released).
     * - A reopened attempt not yet resubmitted: Upcoming, "Resubmission open" (G13).
     * - No deadline (EC2): Undated until released ("Received" once handed in), then Past; a reopened
     *   attempt stays in Upcoming.
     * - Joined the course after the deadline (EC10): once the deadline plus the grace period has
     *   passed, Past and "Not submitted" at once; inside the grace period, Overdue as anyone else.
     *
     * @param \stdClass $row due, cutoff, submitted, timereleased, reopened, enrolstart
     * @param int $now
     * @param int $thresholddays
     * @param int $grace Grace period in seconds.
     * @return array [section, status]
     */
    public static function student(\stdClass $row, int $now, int $thresholddays, int $grace = 0): array {
        $due = $row->due === null ? null : (int)$row->due;
        $released = $row->timereleased !== null && (int)$row->timereleased <= $now;
        if ($row->submitted === null) {
            if ($due === null) {
                return $released ? [self::PAST, self::STATUS_RELEASED] : [self::UNDATED, self::STATUS_OPEN];
            }
            if ($due >= $now) {
                return [self::UPCOMING, $released ? self::STATUS_RELEASED : self::STATUS_DUE];
            }
            return [self::PAST, $released ? self::STATUS_RELEASED : self::STATUS_CLOSED];
        }
        if ((int)$row->submitted === 1) {
            if ($released) {
                return [($due !== null && $due >= $now) ? self::UPCOMING : self::PAST, self::STATUS_RELEASED];
            }
            if ($due === null) {
                return [self::UNDATED, self::STATUS_RECEIVED];
            }
            if ($due >= $now) {
                return [self::UPCOMING, self::STATUS_SUBMITTED];
            }
            return [self::PAST, self::STATUS_INMARKING];
        }
        if (!empty($row->reopened)) {
            return [self::UPCOMING, self::STATUS_REOPENED];
        }
        if ($due === null) {
            return [self::UNDATED, self::STATUS_PENDING];
        }
        if ($due >= $now) {
            return [self::UPCOMING, self::STATUS_PENDING];
        }
        if ($row->cutoff !== null && (int)$row->cutoff < $now) {
            return [self::PAST, self::STATUS_NOTSUBMITTED];
        }
        $joined = isset($row->enrolstart) ? (int)$row->enrolstart : 0;
        if ($joined > $due && $now >= $due + $grace) {
            return [self::PAST, self::STATUS_NOTSUBMITTED];
        }
        if ($due >= $now - $thresholddays * DAYSECS) {
            return [self::OVERDUE, self::STATUS_NOTSUBMITTED];
        }
        return [self::PAST, self::STATUS_NOTSUBMITTED];
    }

    /** Staff section: units of mine still to mark. */
    const TOMARK = 'tomark';
    /** Staff section: all my units marked. */
    const COMPLETED = 'completed';
    /** Staff and convenor section: deadline ahead. */
    const STAFF_UPCOMING = 'upcoming';
    /** Convenor section: deadline passed, marking or release outstanding. */
    const INPROGRESS = 'inprogress';
    /** Convenor section: everything submitted is marked and released. */
    const CONVENOR_PAST = 'past';

    /**
     * Where an assessment goes in a marker's view.
     *
     * Past the deadline with nothing to mark and extensions still to come, an assessment
     * stays in Upcoming deadlines (G9).
     *
     * @param int $tomark My units ready and not marked.
     * @param int $mine My units in total.
     * @param int|null $due
     * @param int $now
     * @param bool $pending Students whose marking is mine have extensions still to come.
     * @return string|null Section, or null when it isn't shown in the marking sections.
     */
    public static function staff(int $tomark, int $mine, ?int $due, int $now, bool $pending = false): ?string {
        if ($tomark > 0) {
            return self::TOMARK;
        }
        if ($due === null) {
            // No deadline: all my marking done is finished (Pierre, 2026-10-04); with none yet,
            // work may still arrive (EC2).
            return $mine > 0 ? self::COMPLETED : self::UNDATED;
        }
        if ($pending && $due !== null && $due < $now) {
            return self::STAFF_UPCOMING;
        }
        if ($mine > 0 && $due < $now) {
            return self::COMPLETED;
        }
        return null;
    }

    /**
     * Where an assessment goes in the convenor view.
     *
     * @param int|null $due
     * @param int $now
     * @param int $submitted Submitted units.
     * @param int $outstanding Ready units not marked at some stage, plus marked units not released.
     * @param int $thresholddays Days after the deadline an assessment with no submissions stays in progress.
     * @param int|null $students Students who can take it now, if known: with none left, nothing more can arrive (EC4).
     * @return string
     */
    public static function convenor(
        ?int $due,
        int $now,
        int $submitted,
        int $outstanding,
        int $thresholddays,
        ?int $students = null
    ): string {
        if ($due !== null && $due >= $now) {
            return self::STAFF_UPCOMING;
        }
        if ($outstanding > 0) {
            return self::INPROGRESS;
        }
        if ($due === null) {
            // Finished work goes to Past whatever its date (Pierre, 2026-10-04).
            return $submitted > 0 ? self::CONVENOR_PAST : self::UNDATED;
        }
        if ($submitted === 0 && $students !== 0 && $due >= $now - $thresholddays * DAYSECS) {
            return self::INPROGRESS;
        }
        return self::CONVENOR_PAST;
    }
}
