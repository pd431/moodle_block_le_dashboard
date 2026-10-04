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
use block_my_assessments\local\index\indexer;
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
    /** @var array Course ids once loaded, for the selected year and for all years. */
    protected array $courses = [];

    #[\Override]
    public function key(): string {
        return scope::CONVENOR;
    }

    /**
     * Courses where I am convenor, in the selected year or in every year.
     *
     * @param bool $allyears
     * @return int[]
     */
    protected function courses(bool $allyears = false): array {
        $key = $allyears ? 'all' : 'year';
        if (!isset($this->courses[$key])) {
            $year = $allyears ? null : $this->year();
            $this->courses[$key] = scope::courses_with_roles($this->userid, config::role_ids('moduleconvenorrole'), $year);
        }
        return $this->courses[$key];
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
    protected function scope_courses(): array {
        return $this->courses();
    }

    #[\Override]
    public function export(): array {
        global $DB;
        $extra = ['hasyears' => true, 'years' => $this->years()];
        $sections = [classifier::STAFF_UPCOMING => [], classifier::INPROGRESS => [], classifier::UNDATED => [],
            classifier::CONVENOR_PAST => []];
        // In progress shows whatever its year; Upcoming and Past follow the year picker (EC3).
        if (!$this->courses(true)) {
            return $this->sections_context($sections, null, $extra);
        }
        $inyear = array_flip($this->courses());
        [$insql, $params] = $DB->get_in_or_equal($this->courses(true), SQL_PARAMS_NAMED);
        $cms = $DB->get_records_sql("SELECT c.*
                                       FROM {block_my_assessments_cm} c
                                       " . self::LIVE . "
                                      WHERE c.courseid $insql
                                   ORDER BY c.due ASC", $params);
        if (!$cms) {
            return $this->sections_context($sections, null, $extra);
        }
        $cms = array_column(array_values($cms), null, 'cmid');

        // Students who can access, and how many submitted (R4: as of indexing). Students who left
        // are not counted here, though their submitted work is still marked (EC4).
        $students = $DB->get_records_sql("SELECT s.cmid,
                                                 SUM(CASE WHEN s.canaccess = 1 AND s.active = 1 THEN 1 ELSE 0 END) AS total,
                                                 SUM(CASE WHEN s.canaccess = 1 AND s.active = 1 AND s.submitted = 1
                                                          THEN 1 ELSE 0 END) AS submitted
                                            FROM {block_my_assessments_stu} s
                                            JOIN {block_my_assessments_cm} c ON c.cmid = s.cmid
                                           WHERE c.courseid $insql
                                        GROUP BY s.cmid", $params);
        // Marking units per stage. Work counts once its unit's deadline has passed (G9).
        $ready = staff::ready_sql($this->now);
        $marking = [];
        $rs = $DB->get_recordset_sql("SELECT m.cmid, m.stage,
                                             COUNT(1) AS units,
                                             SUM(CASE WHEN $ready THEN 1 ELSE 0 END) AS ready,
                                             SUM(CASE WHEN $ready AND m.marked = 1 THEN 1 ELSE 0 END) AS marked,
                                             SUM(CASE WHEN m.submitted = 1 THEN 1 ELSE 0 END) AS submittedunits,
                                             SUM(CASE WHEN m.submitted = 1 AND m.allocatedto IS NULL THEN 1 ELSE 0 END)
                                                 AS unallocated,
                                             SUM(m.released) AS released,
                                             MAX(CASE WHEN m.unittype = 'group' THEN 1 ELSE 0 END) AS isgroup,
                                             MAX(CASE WHEN $ready AND m.marked = 0 THEN m.timeready ELSE NULL END)
                                                 AS lastready,
                                             MAX(m.timemarked) AS lastmarked
                                        FROM {block_my_assessments_mark} m
                                        JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                       WHERE c.courseid $insql
                                    GROUP BY m.cmid, m.stage", $params);
        foreach ($rs as $r) {
            $marking[(int)$r->cmid][$r->stage] = $r;
        }
        $rs->close();
        // Units with marking outstanding at any stage, for the marking deadline call-out (G10).
        $unit = $DB->sql_concat('m.unittype', "':'", 'm.unitid');
        $unmarked = $DB->get_records_sql_menu("SELECT m.cmid, COUNT(DISTINCT $unit)
                                                 FROM {block_my_assessments_mark} m
                                                 JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                                WHERE c.courseid $insql AND $ready AND m.marked = 0
                                             GROUP BY m.cmid", $params);
        // Units flagged for an academic conduct (plagiarism) check, in every section (rc6 B43).
        $flagged = $DB->get_records_sql_menu("SELECT m.cmid, COUNT(DISTINCT $unit)
                                                FROM {block_my_assessments_mark} m
                                                JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                               WHERE c.courseid $insql AND m.flagged = 1
                                            GROUP BY m.cmid", $params);
        // Per marker, per stage.
        $markers = [];
        // Marked work is credited to whoever marked it (EC8).
        $marker = staff::MARKER_SQL;
        $rs = $DB->get_recordset_sql("SELECT m.cmid, $marker AS allocatedto, m.stage,
                                             SUM(CASE WHEN $ready THEN 1 ELSE 0 END) AS ready,
                                             SUM(CASE WHEN $ready AND m.marked = 1 THEN 1 ELSE 0 END) AS marked
                                        FROM {block_my_assessments_mark} m
                                        JOIN {block_my_assessments_cm} c ON c.cmid = m.cmid
                                       WHERE c.courseid $insql AND m.allocatedto IS NOT NULL
                                    GROUP BY m.cmid, $marker, m.stage", $params);
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

        // Extensions still to come, for every student (G9).
        $pending = staff::pending_extensions("c.courseid $insql", $params, $this->now);

        $names = format::course_names(array_column($cms, 'courseid'));
        $threshold = config::overdue_threshold_days();
        $oldest = null;
        $active = [];
        foreach ($cms as $cmid => $cm) {
            $stages = array_filter(explode(',', $cm->stages));
            $m = $marking[$cmid] ?? [];
            $first = $stages ? ($m[reset($stages)] ?? null) : null;
            $units = $first ? (int)$first->ready : 0;
            $released = $first ? (int)$first->released : 0;
            $groups = $first && $first->isgroup;

            $bars = [];
            $s = $students[$cmid] ?? null;
            if ($cm->adapter !== 'generic') {
                $bars[] = format::bar(
                    'submitted',
                    get_string('bar_submitted', 'block_my_assessments'),
                    (int)($s->submitted ?? 0),
                    (int)($s->total ?? 0)
                );
            }
            // Allocation counts every submission, early ones included: it happens before marking.
            $submittedunits = $first ? (int)$first->submittedunits : 0;
            if ($cm->hasallocation && $submittedunits > 0) {
                $bars[] = format::bar(
                    'unallocated',
                    get_string('bar_unallocated', 'block_my_assessments'),
                    (int)$first->unallocated,
                    $submittedunits,
                    $groups
                );
            }
            // Marking bars only once there is something to mark: "0 of 0" says nothing.
            $outstanding = 0;
            if ($units > 0) {
                foreach ($stages as $stage) {
                    $marked = isset($m[$stage]) ? (int)$m[$stage]->marked : 0;
                    $bars[] = format::bar($stage, format::stage($stage), $marked, $units, $groups);
                    $outstanding += isset($m[$stage]) ? (int)$m[$stage]->ready - $marked : 0;
                }
                $bars[] = format::bar('released', get_string('bar_released', 'block_my_assessments'), $released, $units, $groups);
                $outstanding += $units - $released;
            }

            $section = classifier::convenor(
                $cm->due === null ? null : (int)$cm->due,
                $this->now,
                $units,
                $outstanding + (int)($pending[$cmid]->students ?? 0),
                $threshold,
                (int)($s->total ?? 0)
            );
            $otheryear = !isset($inyear[(int)$cm->courseid]);
            // Only with marking or release actually outstanding: that is the work needing action.
            if ($otheryear && ($section !== classifier::INPROGRESS || $outstanding + (int)($pending[$cmid]->students ?? 0) === 0)) {
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
            // Marking starts after the deadline: before it, only submissions and allocation.
            $upcoming = $section === classifier::STAFF_UPCOMING;
            if ($upcoming) {
                $bars = array_values(array_filter($bars, fn($b) => in_array($b['key'], ['submitted', 'unallocated'])));
            }
            $row['bars'] = $bars;
            $row['markers'] = [];
            foreach ($upcoming ? [] : $markers[$cmid] ?? [] as $markerid => $bystage) {
                $person = $people[$markerid] ?? null;
                $name = $person ? fullname($person) : get_string('unknownuser', 'block_my_assessments');
                // Someone who marked here and has since left the module (EC1).
                $active[$cm->courseid] ??= array_flip(indexer::markers((int)$cm->courseid));
                if ($person && !isset($active[$cm->courseid][$markerid])) {
                    $name = get_string('formermarker', 'block_my_assessments', $name);
                }
                $markerbars = [];
                foreach ($bystage as $stage => $r) {
                    $markerbars[] = format::bar($stage, format::stage($stage), (int)$r->marked, (int)$r->ready);
                }
                $row['markers'][] = ['name' => $name, 'bars' => $markerbars];
                $row['search'] .= ' ' . \core_text::strtolower($name);
            }
            \core_collator::asort_array_of_arrays_by_key($row['markers'], 'name');
            $row['markers'] = array_values($row['markers']);
            $row['hasmarkers'] = !empty($row['markers']);
            $row['markersid'] = $row['id'] . '-markers';
            $row['actions'] = [format::view_action($row['url'])];
            $row['hints'] = [];
            if ($cm->due === null) {
                $row['hints'][] = $this->nodeadline_hint($row['id'], (int)$cmid);
            }
            if (empty($cm->hasbrief)) {
                $row['hints'][] = $this->brief_hint($row['id']);
            }
            // Work that arrived after the latest mark brought this back to In progress (G6).
            $lastready = $lastmarked = 0;
            foreach ($m as $r) {
                $lastready = max($lastready, (int)$r->lastready);
                $lastmarked = max($lastmarked, (int)$r->lastmarked);
            }
            if ($section === classifier::INPROGRESS && $lastmarked && $lastready >= $lastmarked) {
                $text = get_string('hint_returned_convenor', 'block_my_assessments');
                $row['hints'][] = format::hint($row['id'], 'returned', $text);
            }
            if (isset($pending[$cmid])) {
                $row['hints'][] = staff::extensions_hint($row['id'], $pending[$cmid]);
            }
            if (!empty($flagged[$cmid])) {
                $row['hints'][] = format::plagiarism_hint($row['id'], (int)$flagged[$cmid]);
            }
            // Past the marking deadline with marking outstanding (G10): staff only, so the attention tone is fine.
            $late = (int)($unmarked[$cmid] ?? 0);
            if ($cm->markingdue && (int)$cm->markingdue < $this->now && $late && !$upcoming) {
                $row['alert'] = get_string('unmarkedafterdeadline', 'block_my_assessments', $late);
            }
            $row['sortkey'] = [(int)($cm->markingdue ?: PHP_INT_MAX), (int)$cm->due];
            $sections[$section][] = $row;
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }
        $sections[classifier::CONVENOR_PAST] = array_reverse($sections[classifier::CONVENOR_PAST]);
        usort($sections[classifier::UNDATED], fn($a, $b) => strcmp($a['search'], $b['search']));
        // In progress by marking deadline, then deadline.
        usort($sections[classifier::INPROGRESS], fn($a, $b) => $a['sortkey'] <=> $b['sortkey']);
        return $this->sections_context($sections, $oldest, $extra);
    }

    /**
     * The hint shown on an assessment with no deadline, wherever it sits (EC2): students can't
     * see when it is due, and it can't move through the sections by date.
     *
     * @param string $rowid
     * @param int $cmid
     * @return array
     */
    protected function nodeadline_hint(string $rowid, int $cmid): array {
        $link = \html_writer::link(
            new \moodle_url('/course/modedit.php', ['update' => $cmid]),
            get_string('hint_nodeadline_settings', 'block_my_assessments')
        );
        return format::hint(
            $rowid,
            'nodeadline',
            get_string('hint_nodeadline_text', 'block_my_assessments'),
            get_string('hint_nodeadline_link', 'block_my_assessments', $link)
        );
    }

    /**
     * The hint shown on an assessment with no brief or description.
     *
     * @param string $rowid
     * @return array
     */
    protected function brief_hint(string $rowid): array {
        $url = (string)get_config('block_my_assessments', 'briefguidanceurl');
        $link = null;
        if ($url !== '') {
            $link = get_string('briefhint_link', 'block_my_assessments', \html_writer::link(
                new \moodle_url($url),
                get_string('briefhint_guidance', 'block_my_assessments')
            ));
        }
        return format::hint($rowid, 'brief', get_string('briefhint_text', 'block_my_assessments'), $link);
    }

    /**
     * The view context from the sections: what needs action, then what is coming, then what has
     * no date (only when there is some), then what is done.
     *
     * @param array $sections
     * @param int|null $oldest
     * @param array $extra
     * @return array
     */
    protected function sections_context(array $sections, ?int $oldest, array $extra): array {
        return $this->context([], array_filter([
            $this->section(classifier::INPROGRESS, $sections[classifier::INPROGRESS]),
            $this->section(classifier::STAFF_UPCOMING, $sections[classifier::STAFF_UPCOMING]),
            $this->undated($sections[classifier::UNDATED]),
            $this->section(classifier::CONVENOR_PAST, $sections[classifier::CONVENOR_PAST]),
        ]), $oldest, $extra);
    }
}
