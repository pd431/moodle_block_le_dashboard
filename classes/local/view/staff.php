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

use block_my_assessments\local\config;
use block_my_assessments\local\output\format;
use block_my_assessments\local\scope;

/**
 * The marker view: marking that is mine, whatever the plugin (R3: hidden activities included, flagged).
 *
 * "Mine" is the units allocated to me, plus every unit of activities without
 * allocation in courses where I hold a staff role. Unallocated units of
 * activities with allocation are for convenors only (D6).
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
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

    /** @var array|null Course ids, once loaded: staff and marker courses. */
    protected ?array $courses = null;

    #[\Override]
    public function key(): string {
        return scope::STAFF;
    }

    /**
     * Courses in scope: [staff role courses, courses where I may have allocated work,
     * convenor role courses].
     *
     * @return array
     */
    protected function courses(): array {
        if ($this->courses === null) {
            $year = $this->year();
            $staff = scope::courses_with_roles($this->userid, config::role_ids('staffrole'), $year);
            $convenor = scope::courses_with_roles($this->userid, config::role_ids('moduleconvenorrole'), $year);
            $this->courses = [$staff, array_values(array_unique(array_merge($staff, $convenor))), $convenor];
        }
        return $this->courses;
    }

    /**
     * My marking per assessment and stage: tomark, mine, marked, unallocated (units counted
     * because they fall to me as convenor), isgroup (units are groups), lastready (latest
     * time an unmarked unit became ready) and lastmarked (my latest mark here).
     *
     * Mine is: units allocated to me; unallocated units on activities with allocation in
     * courses I convene (G3; other markers never see them, D6); every unit of single-marker
     * activities without allocation in my staff courses; and my share of activities with
     * several markers and no allocation (G7, see shared_marking()).
     *
     * @return \stdClass[]
     */
    protected function marking(): array {
        global $DB;
        [$staff, $marker, $convenor] = $this->courses();
        if (!$marker) {
            return [];
        }
        [$markersql, $params] = $DB->get_in_or_equal($marker, SQL_PARAMS_NAMED, 'mc');
        $where = ["(m.allocatedto = :me AND c.courseid $markersql)"];
        if ($convenor) {
            [$convenorsql, $convenorparams] = $DB->get_in_or_equal($convenor, SQL_PARAMS_NAMED, 'cc');
            $where[] = "(m.allocatedto IS NULL AND c.hasallocation = 1 AND c.courseid $convenorsql)";
            $params += $convenorparams;
        }
        $shared = [];
        if ($staff) {
            [$staffsql, $staffparams] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED, 'sc');
            $multi = $DB->sql_like('c.stages', ':multi');
            $where[] = "(c.hasallocation = 0 AND NOT $multi AND c.courseid $staffsql)";
            $params += $staffparams + ['multi' => '%marker%'];
            $shared = $this->shared_marking($staff);
        }
        $params += ['me' => $this->userid, 'me2' => $this->userid];
        $records = $DB->get_records_sql("SELECT " . $DB->sql_concat('m.cmid', "'-'", 'm.stage') . " AS id, m.cmid, m.stage,
                                            SUM(CASE WHEN m.submitted = 1 AND m.marked = 0 THEN 1 ELSE 0 END) AS tomark,
                                            SUM(CASE WHEN m.submitted = 1 THEN 1 ELSE 0 END) AS mine,
                                            SUM(CASE WHEN m.submitted = 1 AND m.marked = 1 THEN 1 ELSE 0 END) AS marked,
                                            SUM(CASE WHEN m.submitted = 1 AND m.allocatedto IS NULL AND c.hasallocation = 1
                                                     THEN 1 ELSE 0 END) AS unallocated,
                                            MAX(CASE WHEN m.unittype = 'group' THEN 1 ELSE 0 END) AS isgroup,
                                            MAX(CASE WHEN m.submitted = 1 AND m.marked = 0 THEN m.timeready ELSE NULL END)
                                                AS lastready,
                                            MAX(CASE WHEN m.markedby = :me2 THEN m.timemarked ELSE NULL END) AS lastmarked
                                       FROM {block_my_assessments_mark} m
                                       JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                      WHERE " . implode(' OR ', $where) . "
                                   GROUP BY m.cmid, m.stage", $params);
        return array_merge(array_values($records), array_values($shared));
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
                                             m.markedby, m.timemarked, m.timeready
                                        FROM {block_my_assessments_mark} m
                                        JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
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
                $open = array_filter($markerstages, fn($r) => $r->submitted && !$r->marked);
                $share = array_slice($mymark ?: $open, 0, 1);
                foreach ($stages as $r) {
                    if (!str_starts_with($r->stage, 'marker') && $r->submitted) {
                        $share[] = $r;
                    }
                }
                foreach ($share as $r) {
                    $key = $cmid . '-' . $r->stage;
                    $o = $out[$key] ??= (object)['id' => $key, 'cmid' => $cmid, 'stage' => $r->stage, 'tomark' => 0,
                        'mine' => 0, 'marked' => 0, 'unallocated' => 0, 'isgroup' => 0, 'lastready' => null,
                        'lastmarked' => null];
                    $o->mine++;
                    if ($r->marked) {
                        $o->marked++;
                    } else {
                        $o->tomark++;
                        $o->lastready = max((int)$o->lastready, (int)$r->timeready) ?: null;
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
     * Assessments in my staff courses whose deadline is ahead.
     *
     * @return \stdClass[] Keyed by cmid.
     */
    protected function upcoming(): array {
        global $DB;
        [$staff] = $this->courses();
        if (!$staff) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED);
        $params['now'] = $this->now;
        $records = $DB->get_records_sql("SELECT c.*,
                                            (SELECT COUNT(1) FROM {block_my_assessments_stu} s
                                              WHERE s.cmid = c.cmid AND s.canaccess = 1) AS students,
                                            (SELECT COUNT(1) FROM {block_my_assessments_stu} s
                                              WHERE s.cmid = c.cmid AND s.canaccess = 1 AND s.submitted = 1) AS submissions
                                       FROM {block_my_assessments_cm} c
                                      WHERE c.courseid $insql AND c.due >= :now
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
        return array_map('intval', $DB->get_fieldset_select('block_my_assessments_cm', 'cmid', "courseid $insql", $params));
    }

    #[\Override]
    public function export(): array {
        global $DB;
        $bycm = [];
        foreach ($this->marking() as $m) {
            $bycm[(int)$m->cmid][$m->stage] = $m;
        }
        $upcoming = $this->upcoming();
        $cmids = array_unique(array_merge(array_keys($bycm), array_keys($upcoming)));
        $cms = [];
        if ($cmids) {
            [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $cms = $DB->get_records_select('block_my_assessments_cm', "cmid $insql", $params, '', '*');
            $cms = array_column(array_values($cms), null, 'cmid');
        }
        $names = format::course_names(array_column($cms, 'courseid'));

        $sections = [classifier::TOMARK => [], classifier::COMPLETED => [], classifier::STAFF_UPCOMING => []];
        $tomarkcounts = format::zero_counts();
        $oldest = null;
        foreach ($bycm as $cmid => $stages) {
            $cm = $cms[$cmid];
            $tomark = $mine = $unallocated = $lastready = $lastmarked = 0;
            foreach ($stages as $s) {
                $tomark += (int)$s->tomark;
                $mine += (int)$s->mine;
                $unallocated += (int)$s->unallocated;
                $lastready = max($lastready, (int)$s->lastready);
                $lastmarked = max($lastmarked, (int)$s->lastmarked);
            }
            $section = classifier::staff($tomark, $mine, $cm->due === null ? null : (int)$cm->due, $this->now);
            if (!$section) {
                continue;
            }
            $row = format::row($cm, $names[$cm->courseid] ?? '', $this->key());
            $row['dates'] = array_values(array_filter([
                $cm->due ? format::date('submissiondeadline', (int)$cm->due) : null,
                $cm->markingdue ? format::date('markingdue', (int)$cm->markingdue) : null,
            ]));
            $row['tomark'] = $tomark;
            $row['bars'] = $this->stage_bars($stages);
            $row['hints'] = [];
            if ($unallocated) {
                $row['hints'][] = format::hint(
                    $row['id'],
                    'unallocated',
                    get_string(
                        $unallocated === 1 ? 'hint_unallocated_one' : 'hint_unallocated_many',
                        'block_my_assessments',
                        $unallocated
                    )
                );
            }
            // Work that arrived after I last marked here: the row came back (G6).
            if ($tomark && $lastmarked && $lastready >= $lastmarked) {
                $row['hints'][] = format::hint($row['id'], 'returned', get_string('hint_returned_marker', 'block_my_assessments'));
            }
            $row['actions'] = [format::view_action($row['url'])];
            $sections[$section][] = $row + ['sortkey' => (int)($cm->markingdue ?: $cm->due)];
            if ($section === classifier::TOMARK) {
                format::count($tomarkcounts, $cm->assesstype, $tomark);
            }
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }

        $windowcounts = [];
        foreach (self::WINDOWS as $days) {
            $windowcounts[$days] = format::zero_counts();
        }
        // Upcoming leaves out what is already listed to mark.
        $tomarkcmids = array_flip(array_column($sections[classifier::TOMARK], 'cmid'));
        foreach ($upcoming as $cmid => $cm) {
            foreach (self::WINDOWS as $days) {
                if ((int)$cm->due <= $this->now + $days * DAYSECS) {
                    format::count($windowcounts[$days], $cm->assesstype);
                }
            }
            if (isset($tomarkcmids[$cmid])) {
                continue;
            }
            $row = format::row($cm, $names[$cm->courseid] ?? '', $this->key());
            $row['dates'] = [format::date('submissiondeadline', (int)$cm->due)];
            // Generic types can't know about submissions (R5).
            $row['bars'] = $cm->adapter === 'generic' ? [] : [format::bar(
                'submitted',
                get_string('bar_submitted', 'block_my_assessments'),
                (int)$cm->submissions,
                (int)$cm->students
            )];
            $row['actions'] = [format::view_action($row['url'])];
            $sections[classifier::STAFF_UPCOMING][] = $row + ['sortkey' => (int)$cm->due];
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }
        foreach ($sections as &$rows) {
            usort($rows, fn($a, $b) => $a['sortkey'] <=> $b['sortkey']);
        }
        unset($rows);

        $window = (int)get_user_preferences('block_my_assessments_window', self::DEFAULT_WINDOW, $this->userid);
        $window = in_array($window, self::WINDOWS) ? $window : self::DEFAULT_WINDOW;
        $windowoptions = [];
        foreach (self::WINDOWS as $days) {
            $windowoptions[$days] = $days === 1 ? get_string('withinday', 'block_my_assessments')
                : get_string('withindays', 'block_my_assessments', $days);
        }
        $range = get_user_preferences('block_my_assessments_range', self::DEFAULT_RANGE, $this->userid);
        $range = isset(self::RANGES[$range]) ? $range : self::DEFAULT_RANGE;
        $rangeoptions = [];
        foreach (array_keys(self::RANGES) as $key) {
            $rangeoptions[$key] = get_string('range_' . $key, 'block_my_assessments');
        }

        $summary = [
            $this->box('tomark', $tomarkcounts),
            $this->box('duesoon', $windowcounts, $windowoptions, (string)$window),
            $this->box('marked', $this->marked_counts(), $rangeoptions, $range),
        ];
        return $this->context($summary, [
            // What needs action, then what is coming, then what is done.
            $this->section(classifier::TOMARK, $sections[classifier::TOMARK]),
            $this->section(classifier::STAFF_UPCOMING, $sections[classifier::STAFF_UPCOMING]),
            $this->section(classifier::COMPLETED, $sections[classifier::COMPLETED]),
        ], $oldest, ['hasyears' => true, 'years' => $this->years()]);
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
            if (isset($kinds[$kind])) {
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
                                           FROM {block_my_assessments_mark} m
                                           JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
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
