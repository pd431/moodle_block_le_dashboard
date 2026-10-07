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
use block_le_dashboard\local\output\format;
use block_le_dashboard\local\scope;

/**
 * The marker view: marking that is mine, whatever the plugin (R3: hidden activities included, flagged).
 *
 * "Mine" is the units allocated to me, plus every unit of activities without
 * allocation in courses where I hold a staff role. Unallocated units of
 * activities with allocation are for convenors only (D6).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staff extends base {
    /** Due-soon windows in days. */
    const WINDOWS = [1, 3, 5, 7, 15];
    /** Default due-soon window. */
    const DEFAULT_WINDOW = 5;
    /** Work-marked ranges in days (0 = ever). */
    const RANGES = ['1week' => 7, '2weeks' => 14, 'month' => 30, 'year' => 365, 'ever' => 0];
    /** Default work-marked range. */
    const DEFAULT_RANGE = '2weeks';

    /** @var array Course ids once loaded, for the selected year and for all years. */
    protected array $courses = [];

    #[\Override]
    public function key(): string {
        return scope::STAFF;
    }

    /**
     * Courses in scope: [staff role courses, courses where I may have allocated work,
     * convenor role courses].
     *
     * @param bool $allyears Every year, for marking still to do (EC3), instead of the selected one.
     * @return array
     */
    protected function courses(bool $allyears = false): array {
        $key = $allyears ? 'all' : 'year';
        if (!isset($this->courses[$key])) {
            $year = $allyears ? null : $this->year();
            $staff = scope::courses_with_roles($this->userid, config::role_ids('staffrole'), $year);
            $convenor = scope::courses_with_roles($this->userid, config::role_ids('moduleconvenorrole'), $year);
            $this->courses[$key] = [$staff, array_values(array_unique(array_merge($staff, $convenor))), $convenor];
        }
        return $this->courses[$key];
    }

    /**
     * My marking per assessment and stage: tomark, mine, marked, unallocated (units counted
     * because they fall to me as convenor), isgroup (units are groups), firstready (earliest
     * time an unmarked unit became ready) and lastmarked (my latest mark here).
     *
     * Mine is: units allocated to me; unallocated units on activities with allocation in
     * courses I convene (G3; other markers never see them, D6); every unit of single-marker
     * activities without allocation in my staff courses; and my share of activities with
     * several markers and no allocation (G7, see shared_marking()).
     *
     * Work counts once the unit's own deadline has passed (G9); see ready_sql().
     *
     * @return \stdClass[]
     */
    protected function marking(): array {
        global $DB;
        [$staff, $marker] = $this->courses(true);
        if (!$marker) {
            return [];
        }
        [$where, $params] = $this->mine_sql(false);
        $shared = $staff ? $this->shared_marking($staff) : [];
        $params += ['me2' => $this->userid];
        $ready = self::ready_sql($this->now);
        $records = $DB->get_records_sql("SELECT " . $DB->sql_concat('m.cmid', "'-'", 'm.stage') . " AS id, m.cmid, m.stage,
                                            SUM(CASE WHEN $ready AND m.marked = 0 THEN 1 ELSE 0 END) AS tomark,
                                            SUM(CASE WHEN $ready THEN 1 ELSE 0 END) AS mine,
                                            SUM(CASE WHEN $ready AND m.marked = 1 THEN 1 ELSE 0 END) AS marked,
                                            SUM(CASE WHEN $ready AND m.allocatedto IS NULL AND c.hasallocation = 1
                                                     THEN 1 ELSE 0 END) AS unallocated,
                                            MAX(CASE WHEN m.unittype = 'group' THEN 1 ELSE 0 END) AS isgroup,
                                            MIN(CASE WHEN $ready AND m.marked = 0 THEN m.timeready ELSE NULL END)
                                                AS firstready,
                                            MAX(CASE WHEN m.markedby = :me2 THEN m.timemarked ELSE NULL END) AS lastmarked
                                       FROM {block_le_dashboard_mark} m
                                       JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                       " . base::LIVE . "
                                      WHERE $where
                                   GROUP BY m.cmid, m.stage", $params);
        return array_merge(array_values($records), array_values($shared));
    }

    /**
     * SQL on m (mark index) and c (assessment index) for the units that are mine: allocated to me
     * (or marked by me, EC8), unallocated where I convene (G3), and every unit of activities without
     * allocation in my staff courses.
     *
     * @param bool $shared Include activities with several markers and no allocation, whose share
     *                     marking() works out separately (G7).
     * @return array [sql, params]
     */
    protected function mine_sql(bool $shared): array {
        global $DB;
        [$staff, $marker, $convenor] = $this->courses(true);
        [$markersql, $params] = $DB->get_in_or_equal($marker, SQL_PARAMS_NAMED, 'mc');
        // Marked work counts for whoever marked it, the rest for whoever it is allocated to (EC8).
        $where = ["(" . self::MARKER_SQL . " = :me AND c.courseid $markersql)"];
        $params['me'] = $this->userid;
        if ($convenor) {
            [$convenorsql, $convenorparams] = $DB->get_in_or_equal($convenor, SQL_PARAMS_NAMED, 'cc');
            $where[] = "(m.allocatedto IS NULL AND c.hasallocation = 1 AND c.courseid $convenorsql)";
            $params += $convenorparams;
        }
        if ($staff) {
            [$staffsql, $staffparams] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED, 'sc');
            $multi = $shared ? '' : ' AND NOT ' . $DB->sql_like('c.stages', ':multi');
            $where[] = "(c.hasallocation = 0$multi AND c.courseid $staffsql)";
            $params += $staffparams + ($shared ? [] : ['multi' => '%marker%']);
        }
        return ['(' . implode(' OR ', $where) . ')', $params];
    }

    /**
     * Units of mine flagged for an academic conduct (plagiarism) check, per assessment.
     *
     * @return int[] cmid => flagged units
     */
    protected function flagged(): array {
        global $DB;
        if (!$this->courses(true)[1]) {
            return [];
        }
        [$where, $params] = $this->mine_sql(true);
        $unit = $DB->sql_concat('m.unittype', "':'", 'm.unitid');
        return array_map('intval', $DB->get_records_sql_menu("SELECT m.cmid, COUNT(DISTINCT $unit)
                                                                FROM {block_le_dashboard_mark} m
                                                                JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                                                " . base::LIVE . "
                                                               WHERE m.flagged = 1 AND $where
                                                            GROUP BY m.cmid", $params));
    }

    /**
     * SQL for the marker a mark index row (alias m) counts for: who marked it, else who it is allocated
     * to (EC8). Marking without allocation (a quiz graded by hand, F8) counts for whoever marked it.
     */
    const MARKER_SQL = "(CASE WHEN m.marked = 1 AND m.markedby IS NOT NULL THEN m.markedby ELSE m.allocatedto END)";

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
     * Students with an extension whose work may still arrive, per assessment, counting only
     * students whose marking is mine (as marking()): allocated to me, unallocated where I
     * convene, or anyone on activities without allocation in my staff courses. A student counts
     * until their deadline (if they submitted early) or their deadline plus the grace period
     * (if they haven't submitted yet).
     *
     * @return \stdClass[] Keyed by cmid: students, latest (latest extended deadline).
     */
    protected function extensions(): array {
        global $DB;
        [$staff, $marker, $convenor] = $this->courses(true);
        if (!$marker) {
            return [];
        }
        [$markersql, $params] = $DB->get_in_or_equal($marker, SQL_PARAMS_NAMED, 'xm');
        $where = ["(c.hasallocation = 1 AND c.courseid $markersql AND " . $DB->sql_like('s.allocatedto', ':me') . ")"];
        $params['me'] = '%,' . $this->userid . ',%';
        if ($convenor) {
            [$convenorsql, $convenorparams] = $DB->get_in_or_equal($convenor, SQL_PARAMS_NAMED, 'xc');
            $where[] = "(c.hasallocation = 1 AND s.allocatedto IS NULL AND c.courseid $convenorsql)";
            $params += $convenorparams;
        }
        if ($staff) {
            [$staffsql, $staffparams] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED, 'xs');
            $where[] = "(c.hasallocation = 0 AND c.courseid $staffsql)";
            $params += $staffparams;
        }
        return self::pending_extensions('(' . implode(' OR ', $where) . ')', $params, $this->now);
    }

    /**
     * Students with an extension whose work may still arrive, per assessment (G9), for the
     * staff and convenor views.
     *
     * @param string $where SQL on s (student index) and c (assessment index) choosing whose count.
     * @param array $params
     * @param int $now
     * @return \stdClass[] Keyed by cmid: students, latest.
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

    /**
     * The hint on a row with extensions still to come (G9), with the count beside its icon.
     *
     * @param string $rowid
     * @param \stdClass $pending students, latest
     * @return array
     */
    public static function extensions_hint(string $rowid, \stdClass $pending): array {
        $n = (int)$pending->students;
        return format::hint(
            $rowid,
            'extensions',
            get_string('hint_extensions_text', 'block_le_dashboard', format::datetime((int)$pending->latest)),
            null,
            $n === 1 ? get_string('hint_extensions_one', 'block_le_dashboard')
                : get_string('hint_extensions_many', 'block_le_dashboard', $n),
            $n
        );
    }

    /**
     * My share of activities with several markers and no allocation (G7), in the same shape
     * as marking(). Per unit: the marker stage I marked, otherwise the first open marker
     * stage, otherwise nothing (others have given every mark). Agreement stays shared once
     * it is ready. So a marker who has given their mark is done with that unit.
     *
     * @param int[] $staff Staff role courses.
     * @return \stdClass[]
     */
    protected function shared_marking(array $staff): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED, 'sh');
        $params['multi'] = '%marker%';
        $rs = $DB->get_recordset_sql("SELECT m.id, m.cmid, m.unitid, m.unittype, m.stage, m.submitted, m.marked,
                                             m.markedby, m.timemarked, m.timeready, m.unitdue, m.released
                                        FROM {block_le_dashboard_mark} m
                                        JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                        " . base::LIVE . "
                                       WHERE c.hasallocation = 0 AND " . $DB->sql_like('c.stages', ':multi') . "
                                             AND c.courseid $insql
                                    ORDER BY m.cmid, m.unittype, m.unitid, m.stage", $params);
        $units = [];
        foreach ($rs as $r) {
            $units[(int)$r->cmid][$r->unittype . ':' . $r->unitid][$r->stage] = $r;
        }
        $rs->close();

        $out = [];
        foreach ($units as $cmid => $byunit) {
            foreach ($byunit as $stages) {
                $markerstages = array_filter($stages, fn($r) => str_starts_with($r->stage, 'marker'));
                $mymark = array_filter($markerstages, fn($r) => $r->marked && (int)$r->markedby === $this->userid);
                $open = array_filter($markerstages, fn($r) => self::is_ready($r, $this->now) && !$r->marked);
                $share = array_slice($mymark ?: $open, 0, 1);
                foreach ($stages as $r) {
                    if (!str_starts_with($r->stage, 'marker') && self::is_ready($r, $this->now)) {
                        $share[] = $r;
                    }
                }
                foreach ($share as $r) {
                    $key = $cmid . '-' . $r->stage;
                    $o = $out[$key] ??= (object)['id' => $key, 'cmid' => $cmid, 'stage' => $r->stage, 'tomark' => 0,
                        'mine' => 0, 'marked' => 0, 'unallocated' => 0, 'isgroup' => 0,
                        'firstready' => null, 'lastmarked' => null];
                    $o->mine++;
                    if ($r->marked) {
                        $o->marked++;
                    } else {
                        $o->tomark++;
                        $o->firstready = $o->firstready === null ? $r->timeready
                            : min((int)$o->firstready, (int)$r->timeready);
                    }
                    $o->isgroup = $o->isgroup || $r->unittype === 'group' ? 1 : 0;
                    if ($r->marked && (int)$r->markedby === $this->userid) {
                        $o->lastmarked = max((int)$o->lastmarked, (int)$r->timemarked) ?: null;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Assessments in my staff courses whose deadline is ahead or unset (EC2), and those past it
     * whose only work still to come is extensions (G9). Students who left are not counted (EC4).
     *
     * @param int[] $later Cmids past their deadline that stay in Upcoming deadlines.
     * @return \stdClass[] Keyed by cmid.
     */
    protected function upcoming(array $later): array {
        global $DB;
        [$staff] = $this->courses();
        if (!$staff && !$later) {
            return [];
        }
        $where = [];
        $params = ['now' => $this->now];
        if ($staff) {
            [$insql, $staffparams] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED);
            $where[] = "(c.courseid $insql AND (c.due >= :now OR c.due IS NULL))";
            $params += $staffparams;
        }
        if ($later) {
            [$latersql, $laterparams] = $DB->get_in_or_equal($later, SQL_PARAMS_NAMED, 'lt');
            $where[] = "c.cmid $latersql";
            $params += $laterparams;
        }
        $records = $DB->get_records_sql("SELECT c.*,
                                            (SELECT COUNT(1) FROM {block_le_dashboard_stu} s
                                              WHERE s.cmid = c.cmid AND s.canaccess = 1 AND s.active = 1) AS students,
                                            (SELECT COUNT(1) FROM {block_le_dashboard_stu} s
                                              WHERE s.cmid = c.cmid AND s.canaccess = 1 AND s.active = 1
                                                    AND s.submitted = 1) AS submissions
                                       FROM {block_le_dashboard_cm} c
                                       " . base::LIVE . "
                                      WHERE " . implode(' OR ', $where) . "
                                   ORDER BY c.due ASC", $params);
        return array_column(array_values($records), null, 'cmid');
    }

    #[\Override]
    public function scope_cmids(): array {
        global $DB;
        [$staff, $marker] = $this->courses();
        if (!$marker) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($marker, SQL_PARAMS_NAMED);
        return array_map('intval', $DB->get_fieldset_select('block_le_dashboard_cm', 'cmid', "courseid $insql", $params));
    }

    #[\Override]
    protected function scope_courses(): array {
        return $this->courses()[1];
    }

    #[\Override]
    public function export(): array {
        global $DB;
        $bycm = [];
        foreach ($this->marking() as $m) {
            $bycm[(int)$m->cmid][$m->stage] = $m;
        }
        $pending = $this->extensions();
        $flagged = $this->flagged();
        $cmids = array_unique(array_merge(array_keys($bycm), array_keys($pending)));
        $cms = [];
        if ($cmids) {
            [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $cms = $DB->get_records_select('block_le_dashboard_cm', "cmid $insql", $params, '', '*');
            $cms = array_column(array_values($cms), null, 'cmid');
        }
        $names = format::course_names(array_column($cms, 'courseid'));

        $sections = [classifier::TOMARK => [], classifier::COMPLETED => [], classifier::STAFF_UPCOMING => [],
            classifier::UNDATED => []];
        $tomarkcounts = format::zero_counts();
        $oldest = null;
        // Marking still to do shows whatever its year; the rest follows the year picker (EC3).
        $inyear = array_flip($this->courses()[1]);
        foreach ($bycm as $cmid => $stages) {
            $cm = $cms[$cmid];
            $tomark = $mine = $unallocated = 0;
            foreach ($stages as $s) {
                $tomark += (int)$s->tomark;
                $mine += (int)$s->mine;
                $unallocated += (int)$s->unallocated;
            }
            $section = classifier::staff(
                $tomark,
                $mine,
                $cm->due === null ? null : (int)$cm->due,
                $this->now,
                isset($pending[$cmid])
            );
            $otheryear = !isset($inyear[(int)$cm->courseid]);
            // Upcoming and Undated rows come from upcoming(), for every assessment in my staff courses.
            if (
                !$section || in_array($section, [classifier::STAFF_UPCOMING, classifier::UNDATED])
                    || ($otheryear && $section !== classifier::TOMARK)
            ) {
                continue;
            }
            $row = format::row($cm, $names[$cm->courseid] ?? '', $this->key());
            if ($otheryear) {
                $row['course'] = $this->with_year($row['course'], (int)$cm->courseid);
            }
            $row['dates'] = array_values(array_filter([
                $cm->due ? format::date('submissiondeadline', (int)$cm->due) : null,
                $cm->markingdue ? format::date('markingdue', (int)$cm->markingdue) : null,
            ]));
            $row['tomark'] = $tomark;
            $row['bars'] = $this->stage_bars($stages);
            $row['hints'] = !empty($cm->nosubmission) ? [format::nosubmission_hint($row['id'], true)] : [];
            if ($setup = format::setup_hint($row['id'], (int)$cmid, (string)$cm->setup)) {
                $row['hints'][] = $setup;
            }
            if ($unallocated) {
                $row['hints'][] = format::hint(
                    $row['id'],
                    'unallocated',
                    get_string(
                        $unallocated === 1 ? 'hint_unallocated_one' : 'hint_unallocated_many',
                        'block_le_dashboard',
                        $unallocated
                    ),
                    null,
                    get_string('hint_unallocated', 'block_le_dashboard', $unallocated),
                    $unallocated
                );
            }
            // Work that arrived after I finished marking here: the row came back (G6).
            if ($tomark && self::returned($stages, $cm->due === null ? null : (int)$cm->due)) {
                $row['hints'][] = format::hint($row['id'], 'returned', get_string('hint_returned_marker', 'block_le_dashboard'));
            }
            if (isset($pending[$cmid])) {
                $row['hints'][] = self::extensions_hint($row['id'], $pending[$cmid]);
            }
            if (!empty($flagged[$cmid])) {
                $row['hints'][] = format::plagiarism_hint($row['id'], $flagged[$cmid]);
            }
            $row['actions'] = [format::view_action($row['url'])];
            $sections[$section][] = $row + ['sortkey' => (int)($cm->markingdue ?: $cm->due)];
            if ($section === classifier::TOMARK) {
                format::count($tomarkcounts, $cm->assesstype, $tomark);
            }
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }

        // Finished marking: where the whole assessment stands, with my bars behind Show marking (Pierre, 2026-10-07).
        $grading = self::grading_counts(array_column($sections[classifier::COMPLETED], 'cmid'), $this->now);
        foreach ($sections[classifier::COMPLETED] as &$row) {
            $g = $grading[$row['cmid']] ?? null;
            $row['grading'] = $g ? format::grading(
                (int)$g->units,
                (int)$g->released,
                (int)$g->unmarked,
                !empty($cms[$row['cmid']]->norelease)
            ) : null;
            $row = format::marking_detail($row, get_string('marking_mine', 'block_le_dashboard'));
        }
        unset($row);

        $windowcounts = [];
        foreach (self::WINDOWS as $days) {
            $windowcounts[$days] = format::zero_counts();
        }
        // Upcoming leaves out what is already listed, and adds what is past its deadline with
        // only extensions to come (G9).
        $listed = array_flip(array_merge(
            array_column($sections[classifier::TOMARK], 'cmid'),
            array_column($sections[classifier::COMPLETED], 'cmid')
        ));
        $later = [];
        foreach (array_keys($pending) as $cmid) {
            if (
                !isset($listed[$cmid]) && isset($cms[$cmid]) && (int)$cms[$cmid]->due < $this->now
                    && isset($inyear[(int)$cms[$cmid]->courseid])
            ) {
                $later[] = $cmid;
            }
        }
        foreach ($this->upcoming($later) as $cmid => $cm) {
            foreach (self::WINDOWS as $days) {
                if ((int)$cm->due >= $this->now && (int)$cm->due <= $this->now + $days * DAYSECS) {
                    format::count($windowcounts[$days], $cm->assesstype);
                }
            }
            if (isset($listed[$cmid])) {
                continue;
            }
            $row = format::row($cm, $names[$cm->courseid] ?? '', $this->key());
            $row['dates'] = $cm->due === null ? [] : [format::date('submissiondeadline', (int)$cm->due)];
            // Held here past its deadline by extensions: say when the last one ends (D10).
            if ((int)$cm->due < $this->now && isset($pending[$cmid])) {
                $row['dates'][] = format::date('extendeddeadline', (int)$pending[$cmid]->latest);
            }
            // Generic types can't know about submissions (R5).
            $row['bars'] = $cm->adapter === 'generic' ? [] : [format::bar(
                'submitted',
                get_string('bar_submitted', 'block_le_dashboard'),
                (int)$cm->submissions,
                (int)$cm->students
            )];
            $row['hints'] = !empty($cm->nosubmission) ? [format::nosubmission_hint($row['id'], true)] : [];
            if ($setup = format::setup_hint($row['id'], (int)$cmid, (string)$cm->setup)) {
                $row['hints'][] = $setup;
            }
            if (isset($pending[$cmid])) {
                $row['hints'][] = self::extensions_hint($row['id'], $pending[$cmid]);
            }
            if (!empty($flagged[$cmid])) {
                $row['hints'][] = format::plagiarism_hint($row['id'], $flagged[$cmid]);
            }
            $row['actions'] = [format::view_action($row['url'])];
            $section = $cm->due === null ? classifier::UNDATED : classifier::STAFF_UPCOMING;
            $sections[$section][] = $row + ['sortkey' => (int)$cm->due];
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }
        foreach ($sections as &$rows) {
            usort($rows, fn($a, $b) => ($a['sortkey'] <=> $b['sortkey']) ?: strcmp($a['search'], $b['search']));
        }
        unset($rows);

        $window = (int)get_user_preferences('block_le_dashboard_window', self::DEFAULT_WINDOW, $this->userid);
        $window = in_array($window, self::WINDOWS) ? $window : self::DEFAULT_WINDOW;
        $windowoptions = [];
        foreach (self::WINDOWS as $days) {
            $windowoptions[$days] = $days === 1 ? get_string('withinday', 'block_le_dashboard')
                : get_string('withindays', 'block_le_dashboard', $days);
        }
        $range = get_user_preferences('block_le_dashboard_range', self::DEFAULT_RANGE, $this->userid);
        $range = isset(self::RANGES[$range]) ? $range : self::DEFAULT_RANGE;
        $rangeoptions = [];
        foreach (array_keys(self::RANGES) as $key) {
            $rangeoptions[$key] = get_string('range_' . $key, 'block_le_dashboard');
        }

        $summary = [
            $this->box('tomark', $tomarkcounts),
            $this->box('duesoon', $windowcounts, $windowoptions, (string)$window),
            $this->box('marked', $this->marked_counts(), $rangeoptions, $range),
        ];
        return $this->context($summary, array_filter([
            // What needs action, then what is coming, then what has no date, then what is done.
            $this->section(classifier::TOMARK, $sections[classifier::TOMARK]),
            $this->section(classifier::STAFF_UPCOMING, $sections[classifier::STAFF_UPCOMING]),
            $this->undated($sections[classifier::UNDATED]),
            $this->section(classifier::COMPLETED, $sections[classifier::COMPLETED]),
        ]), $oldest, ['hasyears' => true, 'years' => $this->years()]);
    }

    /**
     * Work on each assessment, over every marker: ready to mark, released to students, and still unmarked at
     * some stage, each counted once per student or group.
     *
     * @param int[] $cmids
     * @param int $now
     * @return \stdClass[] cmid => units, released, unmarked
     */
    public static function grading_counts(array $cmids, int $now): array {
        global $DB;
        if (!$cmids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values(array_unique($cmids)), SQL_PARAMS_NAMED);
        $ready = self::ready_sql($now);
        $unit = $DB->sql_concat('m.unittype', "':'", 'm.unitid');
        return $DB->get_records_sql("SELECT m.cmid,
                                            COUNT(DISTINCT CASE WHEN $ready THEN $unit ELSE NULL END) AS units,
                                            COUNT(DISTINCT CASE WHEN $ready AND m.released = 1 THEN $unit ELSE NULL END)
                                                AS released,
                                            COUNT(DISTINCT CASE WHEN $ready AND m.marked = 0 THEN $unit ELSE NULL END)
                                                AS unmarked
                                       FROM {block_le_dashboard_mark} m
                                      WHERE m.cmid $insql
                                   GROUP BY m.cmid", $params);
    }

    /**
     * One bar per kind of work I hold on an assessment, each with its own count:
     * marking (any marker stage), then agreeing, then moderating.
     *
     * @param \stdClass[] $stages stage => my units at that stage
     * @return array
     */
    protected function stage_bars(array $stages): array {
        $kinds = [];
        foreach ($stages as $stage => $s) {
            $kind = in_array($stage, ['agreed', 'moderated']) ? $stage : 'marked';
            $kinds[$kind] ??= [0, 0, false];
            $kinds[$kind][0] += (int)$s->marked;
            $kinds[$kind][1] += (int)$s->mine;
            $kinds[$kind][2] = $kinds[$kind][2] || !empty($s->isgroup);
        }
        $bars = [];
        foreach (['marked', 'agreed', 'moderated'] as $kind) {
            // Agreement or moderation not yet open to me says nothing as "0 of 0" (EC9).
            if (isset($kinds[$kind]) && ($kind === 'marked' || $kinds[$kind][1] > 0)) {
                [$marked, $mine, $groups] = $kinds[$kind];
                $bars[] = format::bar($kind, format::stage($kind), $marked, $mine, $groups);
            }
        }
        return $bars;
    }

    /**
     * Units I marked, per range and type.
     *
     * @return array range => counts
     */
    protected function marked_counts(): array {
        global $DB;
        $select = [];
        $params = ['me' => $this->userid];
        foreach (self::RANGES as $key => $days) {
            if ($days) {
                $select[] = "SUM(CASE WHEN m.timemarked >= :t$key THEN 1 ELSE 0 END) AS r$key";
                $params['t' . $key] = $this->now - $days * DAYSECS;
            } else {
                $select[] = "COUNT(1) AS r$key";
            }
        }
        $records = $DB->get_records_sql("SELECT c.assesstype, " . implode(', ', $select) . "
                                           FROM {block_le_dashboard_mark} m
                                           JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                           " . base::LIVE . "
                                          WHERE m.markedby = :me AND m.marked = 1
                                       GROUP BY c.assesstype", $params);
        $counts = [];
        foreach (array_keys(self::RANGES) as $key) {
            $counts[$key] = format::zero_counts();
            foreach ($records as $type => $r) {
                format::count($counts[$key], $type, (int)$r->{'r' . $key});
            }
        }
        return $counts;
    }
}
