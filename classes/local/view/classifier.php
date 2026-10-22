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
     * pill: it is ordinary upcoming work. */
    const STATUS_REOPENED = 'reopened';
    /** Status: generic type with no deadline (EC2). */
    const STATUS_OPEN = 'open';
    /** Status: handed in, no deadline, not graded yet: stays in Undated until it is. */
    const STATUS_RECEIVED = 'received';
    /** Student status: nothing to hand in online, the date has passed and no grade is out yet. */
    const STATUS_AWAITING = 'awaiting';
    /** Statuses of the ordinary case, shown with no pill. */
    const PLAIN_STATUSES = [self::STATUS_PENDING, self::STATUS_DUE, self::STATUS_OPEN, self::STATUS_REOPENED];

    /**
     * Where a student's assessment goes, and its status.
     *
     * - Generic types (submitted unknown): Upcoming before the deadline, Past after, never Overdue (R5).
     * - Unsubmitted after the cut-off (or a quiz's close): Overdue while the student can still ask for
     *   mitigation (Mitigate is shown), else Past, "Not submitted" (R2).
     * - Unsubmitted after the deadline: Overdue for the threshold, then Past.
     * - Unsubmitted with a released grade (work handed in another way, a 0 for non-submission): as
     *   submitted and released, never Overdue (F1).
     * - Nothing to hand in online (attendance, in person, mark-only): Upcoming until the date, then Past,
     *   "Awaiting feedback" until the grade is out. Never Overdue.
     * - Work the student can no longer hand in (past the cut-off or a quiz's close, or every quiz
     *   attempt used with none finished): Past once the deadline has passed; before it, Upcoming with
     *   "Can't submit", as work a teacher locked.
     * - Submitted: Upcoming until the deadline, then Past (in marking, or released). A final submission
     *   (finalised, locked by a teacher, every quiz attempt used) too.
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
                // Handed in early: Upcoming until the deadline, even when it can't be changed any more
                // (reversing the earlier rule); the row then says why instead of offering Submit.
                return [self::UPCOMING, self::STATUS_SUBMITTED];
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
            // Can't be handed in before the deadline (locked by a teacher, B1, or every quiz attempt used with none
            // finished): Upcoming, saying why there's no Submit.
            if ($due !== null && $due >= $now) {
                return [self::UPCOMING, self::STATUS_PENDING];
            }
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
     * Whether the student can still hand the work in: Upcoming and Overdue hold only
     * work that is. The adapters fold every technical limit into the student's cut-off: an assignment's
     * cut-off, a quiz's close, overrides and extensions, and a quiz's last allowed attempt. A reopened
     * attempt is always open.
     *
     * @param \stdClass $row
     * @param int $now
     * @return bool
     */
    public static function submittable(\stdClass $row, int $now): bool {
        if (self::teacher_locked($row)) {
            return false;
        }
        return !empty($row->reopened) || $row->cutoff === null || (int)$row->cutoff >= $now;
    }

    /**
     * Whether a teacher locked submissions for a student who hasn't handed the work in (B1).
     *
     * @param \stdClass $row
     * @return bool
     */
    public static function teacher_locked(\stdClass $row): bool {
        return ($row->lockreason ?? null) === \block_le_dashboard\local\index\student_row::LOCK_TEACHER
            && empty($row->submitted);
    }

    /** Staff section: units of mine still to mark. */
    const TOMARK = 'tomark';
    /** Staff section: all my units marked. */
    const COMPLETED = 'completed';
    /** Convenor section: deadline ahead. */
    const STAFF_UPCOMING = 'upcoming';
    /** Convenor section: deadline passed, marking or release outstanding. */
    const INPROGRESS = 'inprogress';
    /** Convenor section: everything submitted is marked and released. */
    const CONVENOR_PAST = 'past';

    /**
     * Where an assessment goes in a marker's view. There is no Upcoming section: work
     * before its deadline isn't marking yet. Past the deadline with nothing of mine to mark, an assessment
     * goes to Completed marking once my share is marked, with its Extensions hint while extensions run, and
     * nowhere before that; late work brings it back to Marking in progress (G6).
     *
     * @param int $tomark My units ready and not marked.
     * @param int $own My own units: allocated to me or marked by me. Work without allocation that colleagues
     *                 marked counts as mine to mark, but isn't my own once done.
     * @param int|null $due
     * @param int $now
     * @return string|null Section, or null when it isn't shown in the marking sections.
     */
    public static function staff(int $tomark, int $own, ?int $due, int $now): ?string {
        if ($tomark > 0) {
            return self::TOMARK;
        }
        if ($due === null) {
            // No deadline: all my marking done is finished; with none yet,
            // work may still arrive (EC2).
            return $own > 0 ? self::COMPLETED : self::UNDATED;
        }
        return $own > 0 && $due < $now ? self::COMPLETED : null;
    }

    /**
     * Where an assessment goes in the convenor view. Past its deadline with nothing to mark or release, it goes to
     * Past at once, and late work brings it back to In progress.
     *
     * @param int|null $due
     * @param int $now
     * @param int $submitted Submitted units.
     * @param int $outstanding Ready units not marked at some stage, marked units not released, and extensions to come.
     * @return string
     */
    public static function convenor(?int $due, int $now, int $submitted, int $outstanding): string {
        if ($due !== null && $due >= $now) {
            return self::STAFF_UPCOMING;
        }
        if ($outstanding > 0) {
            return self::INPROGRESS;
        }
        if ($due === null) {
            // Finished work goes to Past whatever its date.
            return $submitted > 0 ? self::CONVENOR_PAST : self::UNDATED;
        }
        return self::CONVENOR_PAST;
    }
}
