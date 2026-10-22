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

use block_le_dashboard\local\config;

/**
 * The marking rules the staff and convenor views share, each said once (beta29, F1, F2, F4).
 *
 * Each rule comes in the two forms the views need: SQL on the mark index (alias m) joined to the assessment
 * index (alias c), for the queries that aggregate, and PHP on one row, for the code that classifies rows one at a
 * time. marking_rules_test checks the two forms agree.
 *
 * "Mine", for a marker: units allocated to me, or that I marked (EC8), in courses where I mark; unallocated
 * units of activities with allocation in courses I convene (G3; other markers never see them, D6); and every unit
 * of activities without allocation in my staff courses, except that my share of an activity with several markers
 * and no allocation is worked out per unit in PHP (G7, shared_units()).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class marking_rules {
    /**
     * SQL for the marker a mark index row (alias m) counts for: who marked it, else who it is allocated
     * to (EC8). Marking without allocation (a quiz graded by hand, F8) counts for whoever marked it.
     */
    const MARKER_SQL = "(CASE WHEN m.marked = 1 AND m.markedby IS NOT NULL THEN m.markedby ELSE m.allocatedto END)";

    /**
     * The marker a mark index row counts for, as MARKER_SQL.
     *
     * @param \stdClass $r
     * @return int|null
     */
    public static function counts_for(\stdClass $r): ?int {
        $marker = $r->marked && $r->markedby !== null ? $r->markedby : $r->allocatedto;
        return $marker === null ? null : (int)$marker;
    }

    /**
     * SQL for a mark index row (alias m) that is ready to mark at a time: submitted, and its
     * unit's deadline has passed (G9). Work already marked counts whenever it was marked.
     *
     * @param int $now
     * @return string
     */
    public static function ready_sql(int $now): string {
        // A released unit is finished: a stage that only sampled (moderation) owes it nothing.
        return "(m.submitted = 1 AND (m.marked = 1 OR (m.released = 0 AND (m.unitdue IS NULL OR m.unitdue < $now))))";
    }

    /**
     * Whether a mark index row is ready to mark at a time, as ready_sql().
     *
     * @param \stdClass $r
     * @param int $now
     * @return bool
     */
    public static function is_ready(\stdClass $r, int $now): bool {
        return $r->submitted && ($r->marked || (empty($r->released) && ($r->unitdue === null || (int)$r->unitdue < $now)));
    }

    /**
     * SQL for a mark index row's marking deadline (aliases m and c): the assessment's own, or else the unit's own
     * deadline plus the default marking period (config::markingdue()). NULL when there is none.
     *
     * @return string
     */
    public static function marking_deadline_sql(): string {
        $period = config::number('defaultmarkingdays') * DAYSECS;
        return $period ? "(CASE WHEN c.markingdue > 0 THEN c.markingdue ELSE COALESCE(m.unitdue, c.due) + $period END)"
            : "(CASE WHEN c.markingdue > 0 THEN c.markingdue ELSE NULL END)";
    }

    /**
     * A mark index row's marking deadline, as marking_deadline_sql().
     *
     * @param \stdClass $r Mark index row.
     * @param \stdClass $cm Index record.
     * @return int|null
     */
    public static function marking_deadline(\stdClass $r, \stdClass $cm): ?int {
        if (!empty($cm->markingdue)) {
            return (int)$cm->markingdue;
        }
        $due = $r->unitdue ?? $cm->due;
        return config::markingdue((object)['due' => $due])[0];
    }

    /** SQL on the assessment index (alias c) for an activity with several markers and no allocation (G7). */
    const SHARED_SQL = "(c.hasallocation = 0 AND c.stages LIKE '%marker%')";

    /**
     * Whether an activity's marking is shared between several markers with no allocation, as SHARED_SQL.
     *
     * @param \stdClass $cm Index record.
     * @return bool
     */
    public static function is_shared(\stdClass $cm): bool {
        return empty($cm->hasallocation) && str_contains((string)$cm->stages, 'marker');
    }

    /**
     * SQL on the mark index (m) and the assessment index (c) for the units that are mine.
     *
     * @param array $scope [staff courses, marker courses, convenor courses], as staff::courses().
     * @param int $me
     * @param bool $shared Include activities with several markers and no allocation, whose share
     *                     shared_units() works out separately (G7).
     * @return array [sql, params]
     */
    public static function mine_sql(array $scope, int $me, bool $shared): array {
        $notshared = $shared ? '' : " AND NOT " . self::SHARED_SQL;
        return self::scope_sql($scope, $me, [
            'marker' => fn(string $courses) => "(" . self::MARKER_SQL . " = :me AND c.courseid $courses$notshared)",
            'convenor' => fn(string $courses) => "(m.allocatedto IS NULL AND c.hasallocation = 1 AND c.courseid $courses)",
            'staff' => fn(string $courses) => "(c.hasallocation = 0 AND c.courseid $courses$notshared)",
        ]);
    }

    /**
     * SQL on the student index (s) and the assessment index (c) for the students whose marking is mine, by the
     * same rule as mine_sql(): allocated to me or with work I marked, unallocated where I convene, or anyone on
     * activities without allocation in my staff courses (every student of a shared activity is each marker's).
     *
     * @param array $scope [staff courses, marker courses, convenor courses]
     * @param int $me
     * @return array [sql, params]
     */
    public static function mine_students_sql(array $scope, int $me): array {
        global $DB;
        return self::scope_sql($scope, $me, [
            'marker' => function (string $courses, array &$params) use ($DB, $me): string {
                $params['melike'] = '%,' . $me . ',%';
                // Work I marked without being allocated it is mine too (EC8): their own unit, or their group's.
                $marked = "EXISTS (SELECT 1 FROM {block_le_dashboard_mark} mm
                                    WHERE mm.cmid = s.cmid AND mm.marked = 1 AND mm.markedby = :me
                                      AND ((mm.unittype = 'user' AND mm.unitid = s.userid)
                                           OR (mm.unittype = 'group' AND mm.unitid IN (SELECT gm.groupid
                                                                                          FROM {groups_members} gm
                                                                                         WHERE gm.userid = s.userid))))";
                return "(c.hasallocation = 1 AND c.courseid $courses AND (" . $DB->sql_like('s.allocatedto', ':melike')
                    . " OR $marked))";
            },
            'convenor' => fn(string $courses) => "(c.hasallocation = 1 AND s.allocatedto IS NULL AND c.courseid $courses)",
            'staff' => fn(string $courses) => "(c.hasallocation = 0 AND c.courseid $courses)",
        ]);
    }

    /**
     * The three clauses of "mine", one per kind of course, each bound to a table by the caller.
     *
     * @param array $scope [staff courses, marker courses, convenor courses]
     * @param int $me
     * @param callable[] $clauses marker, convenor, staff: each gets the courses' IN sql and the params by reference.
     * @return array [sql, params]
     */
    protected static function scope_sql(array $scope, int $me, array $clauses): array {
        global $DB;
        [$staff, $marker, $convenor] = $scope;
        $where = [];
        $params = ['me' => $me];
        foreach (['marker' => $marker, 'convenor' => $convenor, 'staff' => $staff] as $kind => $courses) {
            if (!$courses) {
                continue;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED, $kind[0] . 'c');
            $params += $inparams;
            $where[] = $clauses[$kind]($insql, $params);
        }
        return [$where ? '(' . implode(' OR ', $where) . ')' : '1 = 0', $params];
    }

    /**
     * My share of a unit of an activity with several markers and no allocation (G7): the marker stage I marked,
     * otherwise the first open marker stage, otherwise nothing (others have given every mark); and every other stage
     * (agreement, moderation) once it is ready. A marker who has given their mark is done with that unit.
     *
     * @param \stdClass[] $stages stage => mark index row, one unit.
     * @param int $me
     * @param int $now
     * @return \stdClass[] The rows that are mine.
     */
    public static function shared_units(array $stages, int $me, int $now): array {
        $markerstages = array_filter($stages, fn($r) => str_starts_with($r->stage, 'marker'));
        $mymark = array_filter($markerstages, fn($r) => $r->marked && (int)$r->markedby === $me);
        $open = array_filter($markerstages, fn($r) => self::is_ready($r, $now) && !$r->marked);
        $share = array_slice($mymark ?: $open, 0, 1);
        $share = array_values($share);
        foreach ($stages as $r) {
            if (!str_starts_with($r->stage, 'marker') && self::is_ready($r, $now)) {
                $share[] = $r;
            }
        }
        return $share;
    }

    /**
     * Whether a unit of mine is my own workload (Completed marking holds only that): marked by
     * me, or still to mark where I am the marker it falls to. Marking a colleague did, or agreement others did,
     * isn't mine.
     *
     * @param \stdClass $r Mark index row.
     * @param int $me
     * @param bool $shared Whether the unit is in my share of a shared activity (shared_units()), where no one is
     *                     allocated: unmarked work there falls to me.
     * @return bool
     */
    public static function is_own(\stdClass $r, int $me, bool $shared = false): bool {
        if ($r->marked) {
            return (int)$r->markedby === $me;
        }
        return $shared || (int)$r->allocatedto === $me;
    }

    /**
     * Students with an extension whose work may still arrive, per assessment (G9), for the
     * staff and convenor views. A student counts until their deadline (if they submitted early) or their deadline
     * plus the grace period (if they haven't submitted yet).
     *
     * @param string $where SQL on s (student index) and c (assessment index) choosing whose count.
     * @param array $params
     * @param int $now
     * @return \stdClass[] Keyed by cmid: students, latest (latest extended deadline).
     */
    public static function pending_extensions(string $where, array $params, int $now): array {
        global $DB;
        $params += ['now1' => $now, 'now2' => $now - config::grace()];
        $records = $DB->get_records_sql("SELECT s.cmid, COUNT(1) AS students, MAX(s.due) AS latest
                                           FROM {block_le_dashboard_stu} s
                                           JOIN {block_le_dashboard_cm} c ON c.cmid = s.cmid
                                           " . base::LIVE . "
                                          WHERE s.canaccess = 1 AND s.active = 1 AND s.due > c.due
                                                AND ((s.submitted = 1 AND s.due >= :now1)
                                                     OR (s.submitted = 0 AND s.due >= :now2))
                                                AND $where
                                       GROUP BY s.cmid", $params);
        return array_column(array_values($records), null, 'cmid');
    }

    /**
     * Whether new work arrived after marking was finished (G6): the latest mark came after the
     * deadline, and every unit still to mark became ready after it. Marking done before the
     * deadline (early work, auto-marked quiz attempts) never finished anything (F4).
     *
     * @param \stdClass[] $stages With firstready (earliest unmarked ready unit) and lastmarked.
     * @param int|null $due
     * @return bool
     */
    public static function returned(array $stages, ?int $due): bool {
        $lastmarked = 0;
        $firstready = null;
        foreach ($stages as $s) {
            $lastmarked = max($lastmarked, (int)$s->lastmarked);
            if ($s->firstready !== null) {
                $firstready = $firstready === null ? (int)$s->firstready : min($firstready, (int)$s->firstready);
            }
        }
        return $lastmarked > 0 && ($due === null || $lastmarked > $due) && $firstready !== null
            && $firstready >= $lastmarked;
    }
}
