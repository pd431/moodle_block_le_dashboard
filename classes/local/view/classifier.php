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

namespace block_le_dashboard\local\view;

/**
 * The rules that put assessments into sections. Pure functions of stored
 * values and "now", so buckets change at the deadline without re-indexing.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
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
    /** Status: a new attempt is open (assign "Allow another attempt"), whatever the deadline. Shown with no
     * pill: it is ordinary upcoming work (Pierre, 2026-10-06). */
    const STATUS_REOPENED = 'reopened';
    /** Status: generic type with no deadline (EC2). */
    const STATUS_OPEN = 'open';
    /** Status: handed in, no deadline, not graded yet: stays in Undated until it is (Pierre, 2026-10-04). */
    const STATUS_RECEIVED = 'received';
    /** Student status: nothing to hand in online, the date has passed and no grade is out yet. */
    const STATUS_AWAITING = 'awaiting';
    /** Statuses of the ordinary case, shown with no pill (Pierre, 2026-10-04). */
    const PLAIN_STATUSES = [self::STATUS_PENDING, self::STATUS_DUE, self::STATUS_OPEN, self::STATUS_REOPENED];

    /**
     * Where a student's assessment goes, and its status.
     *
     * - Generic types (submitted unknown): Upcoming before the deadline, Past after, never Overdue (R5).
     * - Unsubmitted after the cut-off (or a quiz's close): Overdue while the student can still ask for
     *   mitigation (Mitigate is shown), else Past, "Not submitted" (R2; coordinator, 2026-10-06).
     * - Unsubmitted after the deadline: Overdue for the threshold, then Past.
     * - Unsubmitted with a released grade (work handed in another way, a 0 for non-submission): as
     *   submitted and released, never Overdue (F1).
     * - Nothing to hand in online (attendance, in person, mark-only): Upcoming until the date, then Past,
     *   "Awaiting feedback" until the grade is out. Never Overdue.
     * - Work the student can no longer hand in (past the cut-off or a quiz's close, or every quiz
     *   attempt used): Past at once, whatever the deadline. Mitigate shows there while its window is open.
     * - Submitted: Upcoming until the deadline while it can still be changed, then Past (in marking, or
     *   released). A final submission (locked, every quiz attempt used) is Past as "Submitted" at once.
     * - A reopened attempt not yet resubmitted: Upcoming, with no pill (G13).
     * - No deadline (EC2): Undated until released ("Received" once handed in), then Past; a reopened
     *   attempt stays in Upcoming.
     * - Joined the course after the deadline (EC10): once the deadline plus the grace period has
     *   passed, Past and "Not submitted" at once; inside the grace period, Overdue as anyone else.
     *
     * @param \stdClass $row due, cutoff, submitted, timereleased, reopened, enrolstart, nosubmission, norelease
     * @param int $now
     * @param int $thresholddays
     * @param int $grace Grace period in seconds.
     * @return array [section, status]
     */
    public static function student(\stdClass $row, int $now, int $thresholddays, int $grace = 0): array {
        $due = $row->due === null ? null : (int)$row->due;
        $released = $row->timereleased !== null && (int)$row->timereleased <= $now;
        // Grades never released here: finished once marked, with no grade claimed (F10).
        $done = empty($row->norelease) ? self::STATUS_RELEASED : self::STATUS_SUBMITTED;
        if ($row->submitted === null) {
            if ($due === null) {
                return $released ? [self::PAST, $done] : [self::UNDATED, self::STATUS_OPEN];
            }
            if ($due >= $now) {
                return [self::UPCOMING, $released ? $done : self::STATUS_DUE];
            }
            if ($released) {
                return [self::PAST, $done];
            }
            return [self::PAST, !empty($row->nosubmission) ? self::STATUS_AWAITING : self::STATUS_CLOSED];
        }
        if ((int)$row->submitted === 1) {
            if ($released) {
                return [($due !== null && $due >= $now) ? self::UPCOMING : self::PAST, $done];
            }
            if ($due === null) {
                return [self::UNDATED, self::STATUS_RECEIVED];
            }
            if ($due >= $now) {
                // Handed in early: Upcoming while it can still be changed, else Past (Pierre, 2026-10-06).
                return [self::submittable($row, $now) ? self::UPCOMING : self::PAST, self::STATUS_SUBMITTED];
            }
            return [self::PAST, self::STATUS_INMARKING];
        }
        if (!empty($row->reopened)) {
            return [self::UPCOMING, self::STATUS_REOPENED];
        }
        if ($released) {
            return [($due !== null && $due >= $now) ? self::UPCOMING : self::PAST, $done];
        }
        if (!self::submittable($row, $now)) {
            // Nothing can be handed in any more: Past, with Mitigate while its window is open.
            return [self::PAST, self::STATUS_NOTSUBMITTED];
        }
        if ($due === null) {
            return [self::UNDATED, self::STATUS_PENDING];
        }
        if ($due >= $now) {
            return [self::UPCOMING, self::STATUS_PENDING];
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

    /**
     * Whether the student can still hand the work in (Pierre, 2026-10-06): Upcoming and Overdue hold only
     * work that is. The adapters fold every technical limit into the student's cut-off: an assignment's
     * cut-off, a quiz's close, overrides and extensions, and a quiz's last allowed attempt. A reopened
     * attempt is always open.
     *
     * @param \stdClass $row
     * @param int $now
     * @return bool
     */
    public static function submittable(\stdClass $row, int $now): bool {
        return !empty($row->reopened) || $row->cutoff === null || (int)$row->cutoff >= $now;
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
     * @param bool $closed Nothing more can be handed in: a generic type, or every cut-off has passed (F3).
     * @return string
     */
    public static function convenor(
        ?int $due,
        int $now,
        int $submitted,
        int $outstanding,
        int $thresholddays,
        ?int $students = null,
        bool $closed = false
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
        if ($submitted === 0 && $students !== 0 && !$closed && $due >= $now - $thresholddays * DAYSECS) {
            return self::INPROGRESS;
        }
        return self::CONVENOR_PAST;
    }
}
