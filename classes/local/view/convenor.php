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
 * The module convenor view: every assessment in my modules, with progress for
 * each marking stage the activity actually has, unallocated work (D6) and
 * release (D4). Hidden activities are included and flagged (R3).
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class convenor extends base {
    /** @var int[]|null Course ids, once loaded. */
    protected ?array $courses = null;

    #[\Override]
    public function key(): string {
        return scope::CONVENOR;
    }

    /**
     * Courses where I am convenor, in the selected year.
     *
     * @return int[]
     */
    protected function courses(): array {
        if ($this->courses === null) {
            $this->courses = scope::courses_with_roles($this->userid, config::role_ids('moduleconvenorrole'), $this->year());
        }
        return $this->courses;
    }

    #[\Override]
    public function scope_cmids(): array {
        global $DB;
        if (!$this->courses()) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($this->courses(), SQL_PARAMS_NAMED);
        return array_map('intval', $DB->get_fieldset_select('block_my_assessments_cm', 'cmid', "courseid $insql", $params));
    }

    #[\Override]
    public function export(): array {
        global $DB;
        $extra = ['hasyears' => true, 'years' => $this->years()];
        $sections = [classifier::STAFF_UPCOMING => [], classifier::INPROGRESS => [], classifier::CONVENOR_PAST => []];
        if (!$this->courses()) {
            return $this->sections_context($sections, null, $extra);
        }
        [$insql, $params] = $DB->get_in_or_equal($this->courses(), SQL_PARAMS_NAMED);
        $cms = $DB->get_records_select('block_my_assessments_cm', "courseid $insql", $params, 'due ASC');
        if (!$cms) {
            return $this->sections_context($sections, null, $extra);
        }
        $cms = array_column(array_values($cms), null, 'cmid');

        // Students who can access, and how many submitted (R4: as of indexing).
        $students = $DB->get_records_sql("SELECT s.cmid,
                                                 SUM(s.canaccess) AS total,
                                                 SUM(CASE WHEN s.canaccess = 1 AND s.submitted = 1 THEN 1 ELSE 0 END) AS submitted
                                            FROM {block_my_assessments_stu} s
                                            JOIN {block_my_assessments_cm} c ON c.cmid = s.cmid
                                           WHERE c.courseid $insql
                                        GROUP BY s.cmid", $params);
        // Marking units per stage.
        $marking = [];
        $rs = $DB->get_recordset_sql("SELECT m.cmid, m.stage,
                                             COUNT(1) AS units,
                                             SUM(CASE WHEN m.submitted = 1 THEN 1 ELSE 0 END) AS ready,
                                             SUM(CASE WHEN m.submitted = 1 AND m.marked = 1 THEN 1 ELSE 0 END) AS marked,
                                             SUM(CASE WHEN m.submitted = 1 AND m.allocatedto IS NULL THEN 1 ELSE 0 END)
                                                 AS unallocated,
                                             SUM(m.released) AS released
                                        FROM {block_my_assessments_mark} m
                                        JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                       WHERE c.courseid $insql
                                    GROUP BY m.cmid, m.stage", $params);
        foreach ($rs as $r) {
            $marking[(int)$r->cmid][$r->stage] = $r;
        }
        $rs->close();
        // Per marker, per stage.
        $markers = [];
        $rs = $DB->get_recordset_sql("SELECT m.cmid, m.allocatedto, m.stage,
                                             SUM(CASE WHEN m.submitted = 1 THEN 1 ELSE 0 END) AS ready,
                                             SUM(CASE WHEN m.submitted = 1 AND m.marked = 1 THEN 1 ELSE 0 END) AS marked
                                        FROM {block_my_assessments_mark} m
                                        JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                       WHERE c.courseid $insql AND m.allocatedto IS NOT NULL
                                    GROUP BY m.cmid, m.allocatedto, m.stage", $params);
        $markerids = [];
        foreach ($rs as $r) {
            $markers[(int)$r->cmid][(int)$r->allocatedto][$r->stage] = $r;
            $markerids[(int)$r->allocatedto] = true;
        }
        $rs->close();
        $people = [];
        if ($markerids) {
            [$usql, $uparams] = $DB->get_in_or_equal(array_keys($markerids), SQL_PARAMS_NAMED);
            $namefields = implode(', ', \core_user\fields::get_name_fields());
            $people = $DB->get_records_select('user', "id $usql", $uparams, '', 'id, ' . $namefields);
        }

        $names = format::course_names(array_column($cms, 'courseid'));
        $threshold = config::overdue_threshold_days();
        $oldest = null;
        foreach ($cms as $cmid => $cm) {
            $stages = array_filter(explode(',', $cm->stages));
            $m = $marking[$cmid] ?? [];
            $first = $stages ? ($m[reset($stages)] ?? null) : null;
            $units = $first ? (int)$first->ready : 0;
            $released = $first ? (int)$first->released : 0;

            $bars = [];
            if ($cm->adapter !== 'generic') {
                $s = $students[$cmid] ?? null;
                $bars[] = format::bar(
                    'submitted',
                    get_string('bar_submitted', 'block_my_assessments'),
                    (int)($s->submitted ?? 0),
                    (int)($s->total ?? 0)
                );
            }
            if ($cm->hasallocation) {
                $bars[] = format::bar(
                    'unallocated',
                    get_string('bar_unallocated', 'block_my_assessments'),
                    $first ? (int)$first->unallocated : 0,
                    $units
                );
            }
            $outstanding = 0;
            foreach ($stages as $stage) {
                $marked = isset($m[$stage]) ? (int)$m[$stage]->marked : 0;
                $bars[] = format::bar($stage, format::stage($stage), $marked, $units);
                $outstanding += isset($m[$stage]) ? (int)$m[$stage]->ready - $marked : 0;
            }
            if ($units > 0) {
                $bars[] = format::bar('released', get_string('bar_released', 'block_my_assessments'), $released, $units);
                $outstanding += $units - $released;
            }

            $section = classifier::convenor($cm->due === null ? null : (int)$cm->due, $this->now, $units, $outstanding, $threshold);
            $row = format::row($cm, $names[$cm->courseid] ?? '', $this->key());
            $row['dates'] = array_values(array_filter([
                $cm->due ? format::date('due', (int)$cm->due) : null,
                $cm->markingdue ? format::date('markingdue', (int)$cm->markingdue) : null,
            ]));
            $row['bars'] = $bars;
            $row['markers'] = [];
            foreach ($markers[$cmid] ?? [] as $markerid => $bystage) {
                $person = $people[$markerid] ?? null;
                $name = $person ? fullname($person) : get_string('unknownuser', 'block_my_assessments');
                $markerbars = [];
                foreach ($bystage as $stage => $r) {
                    $markerbars[] = format::bar($stage, format::stage($stage), (int)$r->marked, (int)$r->ready);
                }
                $row['markers'][] = ['name' => $name, 'bars' => $markerbars];
                $row['search'] .= ' ' . \core_text::strtolower($name);
            }
            usort($row['markers'], fn($a, $b) => strcmp($a['name'], $b['name']));
            $row['hasmarkers'] = !empty($row['markers']);
            $row['markersid'] = $row['id'] . '-markers';
            $row['actions'] = [format::view_action($row['url'])];
            $sections[$section][] = $row;
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }
        $sections[classifier::CONVENOR_PAST] = array_reverse($sections[classifier::CONVENOR_PAST]);
        return $this->sections_context($sections, $oldest, $extra);
    }

    /**
     * The view context from the three sections.
     *
     * @param array $sections
     * @param int|null $oldest
     * @param array $extra
     * @return array
     */
    protected function sections_context(array $sections, ?int $oldest, array $extra): array {
        return $this->context([], [
            $this->section(classifier::STAFF_UPCOMING, $sections[classifier::STAFF_UPCOMING]),
            $this->section(classifier::INPROGRESS, $sections[classifier::INPROGRESS]),
            $this->section(classifier::CONVENOR_PAST, $sections[classifier::CONVENOR_PAST]),
        ], $oldest, $extra);
    }
}
