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
use block_le_dashboard\local\genericname;
use block_le_dashboard\local\index\indexer;
use block_le_dashboard\local\output\format;
use block_le_dashboard\local\scope;
use block_le_dashboard\local\setupchecks;
use block_le_dashboard\local\untagged;

/**
 * The module convenor view: every assessment in my modules, with progress for
 * each marking stage the activity actually has, unallocated work (D6) and
 * release (D4). Hidden activities are included and flagged (R3).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
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
        return array_map('intval', $DB->get_fieldset_select('block_le_dashboard_cm', 'cmid', "courseid $insql", $params));
    }

    #[\Override]
    protected function scope_courses(): array {
        return $this->courses();
    }


    #[\Override]
    public function export(): array {
        global $DB;
        $extra = ['hasyears' => true, 'years' => $this->years(),
            // Compatible activities nobody tagged, in the selected year's courses (Pierre, 2026-10-06).
            // Each line has an admin switch (Pierre, 2026-10-19); off skips its queries too.
            'untagged' => config::shown('showuntagged') ? untagged::hint($this->userid, $this->year(), $this->courses()) : null,
            // Tagged activities missing a setting, or of a type no dashboard shows (Pierre, 2026-10-07).
            'setupchecks' => config::shown('showsetupchecks')
                ? setupchecks::hint($this->userid, $this->year(), $this->courses()) : null];
        $sections = [classifier::STAFF_UPCOMING => [], classifier::INPROGRESS => [], classifier::UNDATED => [],
            classifier::CONVENOR_PAST => []];
        // In progress shows whatever its year; Upcoming and Past follow the year picker (EC3).
        if (!$this->courses(true)) {
            return $this->sections_context($sections, null, $extra);
        }
        $inyear = array_flip($this->courses());
        [$insql, $params] = $DB->get_in_or_equal($this->courses(true), SQL_PARAMS_NAMED);
        $cms = $DB->get_records_sql("SELECT c.*
                                       FROM {block_le_dashboard_cm} c
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
                                            FROM {block_le_dashboard_stu} s
                                            JOIN {block_le_dashboard_cm} c ON c.cmid = s.cmid
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
                                             SUM(CASE WHEN $ready AND m.released = 1 THEN 1 ELSE 0 END) AS released,
                                             MAX(CASE WHEN m.unittype = 'group' THEN 1 ELSE 0 END) AS isgroup,
                                             MIN(CASE WHEN $ready AND m.marked = 0 THEN m.timeready ELSE NULL END)
                                                 AS firstready,
                                             MAX(m.timemarked) AS lastmarked
                                        FROM {block_le_dashboard_mark} m
                                        JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                       WHERE c.courseid $insql
                                    GROUP BY m.cmid, m.stage", $params);
        foreach ($rs as $r) {
            $marking[(int)$r->cmid][$r->stage] = $r;
        }
        $rs->close();
        // Units with marking outstanding at any stage, for the marking deadline call-out (G10).
        $unit = $DB->sql_concat('m.unittype', "':'", 'm.unitid');
        $unmarked = $DB->get_records_sql_menu("SELECT m.cmid, COUNT(DISTINCT $unit)
                                                 FROM {block_le_dashboard_mark} m
                                                 JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                                WHERE c.courseid $insql AND $ready AND m.marked = 0
                                             GROUP BY m.cmid", $params);
        // Units flagged for an academic conduct (plagiarism) check, in every section (rc6 B43). Off means the query
        // isn't run either (beta27 hints audit).
        $flagged = !config::shown('hint_plagiarism') ? [] : $DB->get_records_sql_menu("SELECT m.cmid, COUNT(DISTINCT $unit)
                                                FROM {block_le_dashboard_mark} m
                                                JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                               WHERE c.courseid $insql AND m.flagged = 1
                                            GROUP BY m.cmid", $params);
        // Per marker, per stage.
        $markers = [];
        // Marked work is credited to whoever marked it (EC8).
        $marker = staff::MARKER_SQL;
        $rs = $DB->get_recordset_sql("SELECT m.cmid, $marker AS allocatedto, m.stage,
                                             SUM(CASE WHEN $ready THEN 1 ELSE 0 END) AS ready,
                                             SUM(CASE WHEN $ready AND m.marked = 1 THEN 1 ELSE 0 END) AS marked
                                        FROM {block_le_dashboard_mark} m
                                        JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid
                                       WHERE c.courseid $insql AND $marker IS NOT NULL
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
            $people = $DB->get_records_select('user', "id $usql", $uparams, '', 'id, email, maildisplay, deleted, ' . $namefields);
        }

        // Extensions still to come, for every student (G9).
        $pending = staff::pending_extensions("c.courseid $insql", $params, $this->now);

        $names = format::course_names(array_column($cms, 'courseid'));
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
                    get_string('bar_submitted', 'block_le_dashboard'),
                    (int)($s->submitted ?? 0),
                    (int)($s->total ?? 0)
                );
            }
            // Allocation counts every submission, early ones included: it happens before marking.
            $submittedunits = $first ? (int)$first->submittedunits : 0;
            if ($cm->hasallocation && $submittedunits > 0) {
                $bars[] = format::bar(
                    'unallocated',
                    get_string('bar_unallocated', 'block_le_dashboard'),
                    (int)$first->unallocated,
                    $submittedunits,
                    $groups
                );
            }
            // Marking bars only once there is something to mark: "0 of 0" says nothing.
            $outstanding = 0;
            if ($units > 0) {
                foreach ($stages as $i => $stage) {
                    $marked = isset($m[$stage]) ? (int)$m[$stage]->marked : 0;
                    // Each stage out of the work it owes: sampled moderation owes nothing on work already
                    // released, so it isn't "0 of 276"; a later stage owing nothing has no bar (EC9, as staff).
                    $owed = $i === array_key_first($stages) ? $units : (int)($m[$stage]->ready ?? 0);
                    if ($owed > 0) {
                        $bars[] = format::bar($stage, format::stage($stage), $marked, $owed, $groups);
                    }
                    $outstanding += isset($m[$stage]) ? (int)$m[$stage]->ready - $marked : 0;
                }
                $bars[] = format::bar('released', get_string('bar_released', 'block_le_dashboard'), $released, $units, $groups);
                // Release is outstanding until every marked unit is released (Pierre, 2026-10-21, reversing
                // 2026-10-07): a partly released assessment stays In progress.
                $outstanding += $units - $released;
            }
            $section = classifier::convenor(
                $cm->due === null ? null : (int)$cm->due,
                $this->now,
                $units,
                $outstanding + (int)($pending[$cmid]->students ?? 0)
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
            $markingdate = format::markingdue($row, $cm);
            $row['dates'] = array_values(array_filter([
                $cm->due ? format::date('submissiondeadline', (int)$cm->due) : null,
                $markingdate,
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
                $name = $person ? fullname($person) : get_string('unknownuser', 'block_le_dashboard');
                // Someone who marked here and has since left the module (EC1).
                $active[$cm->courseid] ??= array_flip(indexer::markers((int)$cm->courseid));
                if ($person && !isset($active[$cm->courseid][$markerid])) {
                    $name = get_string('formermarker', 'block_le_dashboard', $name);
                }
                $markerbars = [];
                foreach ($bystage as $stage => $r) {
                    $markerbars[] = format::bar($stage, format::stage($stage), (int)$r->marked, (int)$r->ready);
                }
                // Each name links to the marker's email (Pierre, 2026-10-09), where Moodle would show it to me.
                $email = $person && config::shown('hint_markeremail') && $this->can_see_email($person, (int)$cm->courseid)
                    ? $person->email : null;
                $row['markers'][] = ['name' => $name, 'bars' => $markerbars, 'mailto' => $email ? 'mailto:' . $email : null];
                $row['search'] .= ' ' . \core_text::strtolower($name);
            }
            \core_collator::asort_array_of_arrays_by_key($row['markers'], 'name');
            $row['markers'] = array_values($row['markers']);
            $row['hasmarkers'] = !empty($row['markers']);
            $row['markersid'] = $row['id'] . '-markers';
            $row['actions'] = [format::view_action($row['url'])];
            $row['hints'] = !empty($cm->nosubmission) && config::shown('hint_nosubmission')
                ? [format::nosubmission_hint($row['id'], true)] : [];
            if (config::shown('hint_setup') && $setup = format::setup_hint($row['id'], (int)$cmid, (string)$cm->setup)) {
                $row['hints'][] = $setup;
            }
            if (empty($cm->hasbrief) && config::shown('hint_brief')) {
                $row['hints'][] = $this->brief_hint($row['id']);
            }
            if ($generic = genericname::hint($row['id'], (string)$cm->name, (int)$cm->courseid)) {
                $row['hints'][] = $generic;
            }
            // Work that arrived after marking was finished brought this back to In progress (G6).
            if (
                $section === classifier::INPROGRESS && config::shown('hint_returned')
                    && staff::returned($m, $cm->due === null ? null : (int)$cm->due)
            ) {
                $text = get_string('hint_returned_convenor', 'block_le_dashboard');
                $row['hints'][] = format::hint($row['id'], 'returned', $text);
            }
            if (isset($pending[$cmid]) && config::shown('hint_extensions')) {
                $row['hints'][] = staff::extensions_hint($row['id'], $pending[$cmid]);
            }
            if (!empty($flagged[$cmid])) {
                $row['hints'][] = format::plagiarism_hint($row['id'], (int)$flagged[$cmid]);
            }
            $row = format::load_error($row, $cm, (int)($s->total ?? 0) === 0);
            // Past the marking deadline with marking outstanding (G10): staff only, so the attention tone is fine.
            $late = (int)($unmarked[$cmid] ?? 0);
            [$markingdue] = config::markingdue($cm);
            if ($markingdue && $markingdue < $this->now && $late && !$upcoming && config::shown('hint_unmarkedlate')) {
                $row['alert'] = get_string('unmarkedafterdeadline', 'block_le_dashboard', $late);
            }
            if ($section === classifier::CONVENOR_PAST) {
                // Where it stands at a glance; the bars and markers behind Show marking (Pierre, 2026-10-07).
                $row['grading'] = format::grading($units, $released, (int)($unmarked[$cmid] ?? 0), !empty($cm->norelease));
                $row = format::marking_detail($row, get_string('marking_all', 'block_le_dashboard'));
            }
            // Upcoming by submission deadline, the rest by marking deadline, else deadline (Pierre, 2026-10-21).
            $row['sortkey'] = $upcoming ? (int)$cm->due : (int)($markingdue ?? $cm->due);
            $sections[$section][] = $row;
            $oldest = $oldest === null ? (int)$cm->timeindexed : min($oldest, (int)$cm->timeindexed);
        }
        // Soonest first while there's work, latest first once done, undated by name; ties by name (Pierre, 2026-10-21).
        foreach ($sections as $key => &$rows) {
            $order = $key === classifier::CONVENOR_PAST ? -1 : 1;
            usort($rows, fn($a, $b) => ($key === classifier::UNDATED ? 0 : $order * ($a['sortkey'] <=> $b['sortkey']))
                ?: strcmp($a['search'], $b['search']));
        }
        unset($rows);
        return $this->sections_context($sections, $oldest, $extra);
    }

    /**
     * Whether Moodle would show me someone's email, as on their profile: their Email visibility setting, or my
     * capability to see hidden emails (beta27 hints audit).
     *
     * @param \stdClass $person User record, with email and maildisplay.
     * @param int $courseid The course we share, as its convenor.
     * @return bool
     */
    protected function can_see_email(\stdClass $person, int $courseid): bool {
        if (!empty($person->deleted) || !validate_email($person->email)) {
            return false;
        }
        if ((int)$person->id === $this->userid) {
            return true;
        }
        $display = (int)($person->maildisplay ?? \core_user::MAILDISPLAY_HIDE);
        // Both are in the course: I convene it, and they mark in it.
        if (in_array($display, [\core_user::MAILDISPLAY_EVERYONE, \core_user::MAILDISPLAY_COURSE_MEMBERS_ONLY])) {
            return true;
        }
        // As user_get_user_details(): moodle/course:useremail shows hidden emails (editing teachers have it by default),
        // and so does email in the identity fields for those who can see them.
        $context = \context_course::instance($courseid);
        return has_capability('moodle/course:useremail', $context, $this->userid)
            || (in_array('email', \core_user\fields::get_identity_fields($context, false))
                && has_capability('moodle/site:viewuseridentity', $context, $this->userid));
    }

    /**
     * The hint shown on an assessment with no brief or description.
     *
     * @param string $rowid
     * @return array
     */
    protected function brief_hint(string $rowid): array {
        return format::hint($rowid, 'brief', get_string('briefhint_text', 'block_le_dashboard'));
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
