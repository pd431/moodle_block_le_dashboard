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
     * Courses in scope: [staff role courses, courses where I may have allocated work].
     *
     * @return array
     */
    protected function courses(): array {
        if ($this->courses === null) {
            $year = $this->year();
            $staff = scope::courses_with_roles($this->userid, config::role_ids('staffrole'), $year);
            $convenor = scope::courses_with_roles($this->userid, config::role_ids('moduleconvenorrole'), $year);
            $this->courses = [$staff, array_values(array_unique(array_merge($staff, $convenor)))];
        }
        return $this->courses;
    }

    /**
     * My marking, aggregated per assessment and stage in the database.
     *
     * @return \stdClass[]
     */
    protected function marking(): array {
        global $DB;
        [$staff, $marker] = $this->courses();
        if (!$marker) {
            return [];
        }
        [$markersql, $params] = $DB->get_in_or_equal($marker, SQL_PARAMS_NAMED, 'mc');
        $where = "(m.allocatedto = :me AND c.courseid $markersql)";
        if ($staff) {
            [$staffsql, $staffparams] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED, 'sc');
            $where .= " OR (c.hasallocation = 0 AND c.courseid $staffsql)";
            $params += $staffparams;
        }
        $params['me'] = $this->userid;
        return $DB->get_records_sql("SELECT " . $DB->sql_concat('m.cmid', "'-'", 'm.stage') . " AS id, m.cmid, m.stage,
                                            SUM(CASE WHEN m.submitted = 1 AND m.marked = 0 THEN 1 ELSE 0 END) AS tomark,
                                            SUM(CASE WHEN m.submitted = 1 THEN 1 ELSE 0 END) AS mine,
                                            SUM(CASE WHEN m.submitted = 1 AND m.marked = 1 THEN 1 ELSE 0 END) AS marked
                                       FROM {block_my_assessments_mark} m
                                       JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                      WHERE $where
                                   GROUP BY m.cmid, m.stage", $params);
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
            $tomark = $mine = $marked = 0;
            foreach ($stages as $s) {
                $tomark += (int)$s->tomark;
                $mine += (int)$s->mine;
                $marked += (int)$s->marked;
            }
            $section = classifier::staff($tomark, $mine, $cm->due === null ? null : (int)$cm->due, $this->now);
            if (!$section) {
                continue;
            }
            $row = format::row($cm, $names[$cm->courseid] ?? '', $this->key());
            $row['dates'] = array_values(array_filter([
                $cm->due ? format::date('due', (int)$cm->due) : null,
                $cm->markingdue ? format::date('markingdue', (int)$cm->markingdue) : null,
            ]));
            $row['tomark'] = $tomark;
            $row['tomarklabel'] = $tomark ? get_string('tomarkcount', 'block_my_assessments', $tomark) : null;
            $row['bars'] = [format::bar('marked', get_string('bar_mymarking', 'block_my_assessments'), $marked, $mine)];
            if (count($stages) > 1) {
                $row['stages'] = [];
                foreach ($stages as $key => $s) {
                    if ((int)$s->tomark > 0) {
                        $row['stages'][] = ['label' => format::stage($key), 'count' => (int)$s->tomark];
                    }
                }
                $row['hasstages'] = !empty($row['stages']);
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
            $row['dates'] = [format::date('due', (int)$cm->due)];
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
            $this->section(classifier::TOMARK, $sections[classifier::TOMARK]),
            $this->section(classifier::COMPLETED, $sections[classifier::COMPLETED]),
            $this->section(classifier::STAFF_UPCOMING, $sections[classifier::STAFF_UPCOMING]),
        ], $oldest, ['hasyears' => true, 'years' => $this->years()]);
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
