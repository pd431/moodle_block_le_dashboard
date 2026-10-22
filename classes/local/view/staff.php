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
 * "Mine" is defined once, in marking_rules: the units allocated to me or that I marked, plus every unit of
 * activities without allocation in courses where I hold a staff or convenor role. Unallocated units of
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
     * Courses in scope: [staff role and convenor role courses, courses where I may have allocated work (the
     * same), convenor role courses].
     *
     * @param bool $allyears Every year, for marking still to do (EC3), instead of the selected one.
     * @return array
     */
    protected function courses(bool $allyears = false): array {
        $key = $allyears ? 'all' : 'year';
        if (!isset($this->courses[$key])) {
            $year = $allyears ? null : $this->year();
            $convenor = scope::courses_with_roles($this->userid, config::role_ids('moduleconvenorrole'), $year);
            // A convenor's own courses count as staff courses, so marking without allocation there is theirs too
            // (UAT, beta27: a convenor who wasn't also a teacher there never saw quiz marking).
            $staff = array_values(array_unique(array_merge(
                scope::courses_with_roles($this->userid, config::role_ids('staffrole'), $year),
                $convenor
            )));
            $this->courses[$key] = [$staff, $staff, $convenor];
        }
        return $this->courses[$key];
    }

    /**
     * My marking per assessment and stage: tomark, mine, marked, own (units allocated to me or that I marked, and
     * work without allocation no one marked by hand where I convene: my own workload for Completed marking),
     * unallocated (units counted because they fall to me as convenor), isgroup (units are groups), firstready (earliest
     * time an unmarked unit became ready) and lastmarked (my latest mark here).
     *
     * Mine is: units allocated to me; unallocated units on activities with allocation in
     * courses I convene (G3; other markers never see them, D6); every unit of single-marker
     * activities without allocation in my staff courses; and my share of activities with
     * several markers and no allocation (G7, see shared_marking()).
     *
     * Work counts once the unit's own deadline has passed (G9); see marking_rules::ready_sql().
     *
     * @return \stdClass[]
     */
    protected function marking(): array {
        global $DB;
        [$staff, $marker, $convenor] = $this->courses(true);
        if (!$marker) {
            return [];
        }
        [$where, $params] = marking_rules::mine_sql($this->courses(true), $this->userid, false);
        $shared = $staff ? $this->shared_marking($staff) : [];
        $params += ['me2' => $this->userid, 'me3' => $this->userid];
        // In modules I convene, work without allocation that no one marked by hand (an auto-marked quiz) is mine too;
        // a colleague's marking isn't (Pierre, 2026-10-22).
        $automarked = '';
        if ($convenor) {
            [$autosql, $autoparams] = $DB->get_in_or_equal($convenor, SQL_PARAMS_NAMED, 'am');
            $automarked = " OR (m.marked = 1 AND m.markedby IS NULL AND c.hasallocation = 0 AND c.courseid $autosql)";
            $params += $autoparams;
        }
        $ready = marking_rules::ready_sql($this->now);
        // Work still to mark whose marking deadline falls within each summary window, or has passed (UAT, beta27).
        $deadline = marking_rules::marking_deadline_sql();
        $windows = '';
        foreach (self::WINDOWS as $days) {
            $by = $this->now + $days * DAYSECS;
            $windows .= "SUM(CASE WHEN $ready AND m.marked = 0 AND $deadline <= $by THEN 1 ELSE 0 END) AS dueby$days, ";
        }
        $records = $DB->get_records_sql("SELECT " . $DB->sql_concat('m.cmid', "'-'", 'm.stage') . " AS id, m.cmid, m.stage,
                                            $windows
                                            SUM(CASE WHEN $ready AND m.marked = 0 THEN 1 ELSE 0 END) AS tomark,
                                            SUM(CASE WHEN $ready THEN 1 ELSE 0 END) AS mine,
                                            SUM(CASE WHEN $ready AND m.marked = 1 THEN 1 ELSE 0 END) AS marked,
                                            SUM(CASE WHEN $ready AND (" . marking_rules::MARKER_SQL . " = :me3$automarked)
                                                     THEN 1 ELSE 0 END) AS own,
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
     * Units of mine flagged for an academic conduct (plagiarism) check, per assessment.
     *
     * @return int[] cmid => flagged units
     */
    protected function flagged(): array {
        global $DB;
        if (!$this->courses(true)[1]) {
            return [];
        }
        [$where, $params] = marking_rules::mine_sql($this->courses(true), $this->userid, true);
        $unit = $DB->sql_concat('m.unittype', "':'", 'm.unitid');
        return array_map('intval', $DB->get_records_sql_menu("SELECT m.cmid, COUNT(DISTINCT $unit)
                                                                FROM {block_le_dashboard_mark} m
                                                                JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                                                " . base::LIVE . "
                                                               WHERE m.flagged = 1 AND $where
                                                            GROUP BY m.cmid", $params));
    }

    /**
     * Students with an extension whose work may still arrive, per assessment, counting only students whose
     * marking is mine by the same rule as marking() (marking_rules::mine_students_sql()).
     *
     * @return \stdClass[] Keyed by cmid: students, latest (latest extended deadline).
     */
    protected function extensions(): array {
        if (!$this->courses(true)[1]) {
            return [];
        }
        [$where, $params] = marking_rules::mine_students_sql($this->courses(true), $this->userid);
        return marking_rules::pending_extensions($where, $params, $this->now);
    }

    /**
     * My share of activities with several markers and no allocation (G7), in the same shape as marking():
     * per unit, the stages marking_rules::shared_units() gives me.
     *
     * @param int[] $staff Staff role courses.
     * @return \stdClass[]
     */
    protected function shared_marking(array $staff): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED, 'sh');
        $rs = $DB->get_recordset_sql("SELECT m.id, m.cmid, m.unitid, m.unittype, m.stage, m.submitted, m.marked,
                                             m.markedby, m.timemarked, m.timeready, m.unitdue, m.released, m.allocatedto,
                                             c.markingdue, c.due
                                        FROM {block_le_dashboard_mark} m
                                        JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                        " . base::LIVE . "
                                       WHERE " . marking_rules::SHARED_SQL . " AND c.courseid $insql
                                    ORDER BY m.cmid, m.unittype, m.unitid, m.stage", $params);
        $units = [];
        foreach ($rs as $r) {
            $units[(int)$r->cmid][$r->unittype . ':' . $r->unitid][$r->stage] = $r;
        }
        $rs->close();

        $out = [];
        foreach ($units as $cmid => $byunit) {
            foreach ($byunit as $stages) {
                foreach (marking_rules::shared_units($stages, $this->userid, $this->now) as $r) {
                    $key = $cmid . '-' . $r->stage;
                    // The array is complete before the cast: object + array is a PHP error (beta27).
                    $o = $out[$key] ??= (object)(['id' => $key, 'cmid' => $cmid, 'stage' => $r->stage, 'tomark' => 0,
                        'mine' => 0, 'marked' => 0, 'own' => 0, 'unallocated' => 0, 'isgroup' => 0,
                        'firstready' => null, 'lastmarked' => null]
                        + array_fill_keys(array_map(fn($d) => 'dueby' . $d, self::WINDOWS), 0));
                    $o->mine++;
                    $o->own += marking_rules::is_own($r, $this->userid, true) ? 1 : 0;
                    if ($r->marked) {
                        $o->marked++;
                    } else {
                        $o->tomark++;
                        $deadline = marking_rules::marking_deadline($r, $r);
                        foreach (self::WINDOWS as $days) {
                            $o->{'dueby' . $days} += $deadline !== null && $deadline <= $this->now + $days * DAYSECS ? 1 : 0;
                        }
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
     * Assessments in my staff courses with no deadline (EC2), for Undated. Students who left are not counted (EC4).
     *
     * @return \stdClass[] Keyed by cmid.
     */
    protected function undated_cms(): array {
        global $DB;
        [$staff] = $this->courses();
        if (!$staff) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql("SELECT c.*,
                                            (SELECT COUNT(1) FROM {block_le_dashboard_stu} s
                                              WHERE s.cmid = c.cmid AND s.canaccess = 1 AND s.active = 1) AS students,
                                            (SELECT COUNT(1) FROM {block_le_dashboard_stu} s
                                              WHERE s.cmid = c.cmid AND s.canaccess = 1 AND s.active = 1
                                                    AND s.submitted = 1) AS submissions
                                       FROM {block_le_dashboard_cm} c
                                       " . base::LIVE . "
                                      WHERE c.courseid $insql AND c.due IS NULL", $params);
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
        // Off means the query isn't run either (beta27 hints audit).
        $flagged = config::shown('hint_plagiarism') ? $this->flagged() : [];
        $cmids = array_keys($bycm);
        $cms = [];
        if ($cmids) {
            [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $cms = $DB->get_records_select('block_le_dashboard_cm', "cmid $insql", $params, '', '*');
            $cms = array_column(array_values($cms), null, 'cmid');
        }
        $names = format::course_names(array_column($cms, 'courseid'));

        $sections = [classifier::TOMARK => [], classifier::COMPLETED => [], classifier::UNDATED => []];
        $tomarkcounts = format::zero_counts();
        $windowcounts = [];
        foreach (self::WINDOWS as $days) {
            $windowcounts[$days] = format::zero_counts();
        }
        $oldest = null;
        // Marking still to do shows whatever its year; the rest follows the year picker (EC3).
        $inyear = array_flip($this->courses()[1]);
        foreach ($bycm as $cmid => $stages) {
            $cm = $cms[$cmid];
            $tomark = $own = $unallocated = 0;
            foreach ($stages as $s) {
                $tomark += (int)$s->tomark;
                $own += (int)$s->own;
                $unallocated += (int)$s->unallocated;
            }
            // Completed marking holds only my own workload: work allocated to me, or that I marked (Pierre, 2026-10-21).
            $section = classifier::staff($tomark, $own, $cm->due === null ? null : (int)$cm->due, $this->now);
            $otheryear = !isset($inyear[(int)$cm->courseid]);
            // Undated rows come from undated_cms(), for every assessment in my staff courses.
            if (!$section || $section === classifier::UNDATED || ($otheryear && $section !== classifier::TOMARK)) {
                continue;
            }
            $row = staff_row::build($cm, $names[$cm->courseid] ?? '', $this->key(), $this->stage_bars($stages), [
                'course' => $otheryear ? fn(string $course) => $this->with_year($course, (int)$cm->courseid) : null,
                'unallocated' => $unallocated,
                // Work that arrived after I finished marking here: the row came back (G6).
                'returned' => $tomark && marking_rules::returned($stages, $cm->due === null ? null : (int)$cm->due),
                'pending' => $pending[$cmid] ?? null,
                'flagged' => $flagged[$cmid] ?? 0,
            ]);
            $row['tomark'] = $tomark;
            $sections[$section][] = $row + ['sortkey' => (int)(config::markingdue($cm)[0] ?? $cm->due)];
            if ($section === classifier::TOMARK) {
                format::count($tomarkcounts, $cm->assesstype, $tomark);
                foreach (self::WINDOWS as $days) {
                    $due = 0;
                    foreach ($stages as $st) {
                        $due += (int)($st->{'dueby' . $days} ?? 0);
                    }
                    format::count($windowcounts[$days], $cm->assesstype, $due);
                }
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

        // Undated leaves out what is already listed: My marking has no Upcoming section (Pierre, 2026-10-21).
        $listed = array_flip(array_merge(
            array_column($sections[classifier::TOMARK], 'cmid'),
            array_column($sections[classifier::COMPLETED], 'cmid')
        ));
        $undated = $this->undated_cms();
        $names += format::course_names(array_diff(array_unique(array_column($undated, 'courseid')), array_keys($names)));
        foreach ($undated as $cmid => $cm) {
            if (isset($listed[$cmid])) {
                continue;
            }
            // Generic types can't know about submissions (R5).
            $bars = $cm->adapter === 'generic' ? [] : [format::bar(
                'submitted',
                get_string('bar_submitted', 'block_le_dashboard'),
                (int)$cm->submissions,
                (int)$cm->students
            )];
            $row = staff_row::build($cm, $names[$cm->courseid] ?? '', $this->key(), $bars, [
                'undated' => true,
                'flagged' => $flagged[$cmid] ?? 0,
                'nostudents' => (int)$cm->students === 0,
            ]);
            $sections[classifier::UNDATED][] = $row + ['sortkey' => 0];
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }
        // Soonest first while there's marking to do, latest first once done, undated by name (Pierre, 2026-10-21).
        foreach ($sections as $key => &$rows) {
            $order = $key === classifier::COMPLETED ? -1 : 1;
            usort($rows, fn($a, $b) => $order * ($a['sortkey'] <=> $b['sortkey']) ?: strcmp($a['search'], $b['search']));
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
            // What needs action, then what has no date, then what is done.
            $this->section(classifier::TOMARK, $sections[classifier::TOMARK]),
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
        $ready = marking_rules::ready_sql($now);
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
