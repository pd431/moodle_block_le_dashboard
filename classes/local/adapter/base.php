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

namespace block_le_dashboard\local\adapter;

use block_le_dashboard\local\index\cm_row;
use block_le_dashboard\local\index\marking_row;
use block_le_dashboard\local\index\student_row;

/**
 * Helpers shared by the adapters: gradebook release, group membership and override resolution.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base implements adapter {
    /** Size of IN (...) lists. */
    const CHUNK = 1000;

    /** Columns of the activity's own table that the adapter reads. */
    const INSTANCE_FIELDS = '*';

    #[\Override]
    public function support(): string {
        return self::SUPPORT_FULL;
    }

    #[\Override]
    public static function events(): array {
        return [];
    }

    #[\Override]
    public static function events_for_related_user(): array {
        return [];
    }

    #[\Override]
    public function brief_areas(): array {
        return [];
    }

    #[\Override]
    public function takes_submissions(): ?bool {
        return null;
    }

    #[\Override]
    public function takes_selfcert(): bool {
        return false;
    }

    #[\Override]
    public function selfcert_subject(int $instanceid): ?object {
        return null;
    }

    #[\Override]
    public function selfcert_used(int $userid, int $since): ?int {
        return null;
    }

    /**
     * The activity's own record. Not cached: events can change it mid-request.
     *
     * @param \cm_info $cm
     * @return \stdClass
     */
    protected function instance(\cm_info $cm): \stdClass {
        global $DB;
        return $DB->get_record($this->modname(), ['id' => $cm->instance], static::INSTANCE_FIELDS, MUST_EXIST);
    }

    /**
     * Turns 0 or empty dates into null.
     *
     * @param mixed $value
     * @return int|null
     */
    protected static function date($value): ?int {
        return empty($value) ? null : (int)$value;
    }

    /**
     * Default student rows: the assessment's own dates, nothing submitted.
     *
     * @param cm_row $cmrow
     * @param int[] $userids
     * @return student_row[] Keyed by user id.
     */
    protected static function default_rows(cm_row $cmrow, array $userids): array {
        $rows = [];
        foreach ($userids as $userid) {
            $rows[$userid] = new student_row((int)$userid, $cmrow->due, $cmrow->cutoff);
        }
        return $rows;
    }

    /**
     * Runs a callback over the user ids in chunks, merging the returned arrays (keys kept).
     *
     * @param int[] $userids
     * @param callable $callback Receives an array of user ids, returns an array.
     * @return array
     */
    protected static function chunked(array $userids, callable $callback): array {
        $result = [];
        foreach (array_chunk(array_values($userids), self::CHUNK) as $chunk) {
            $result = $result + $callback($chunk);
        }
        return $result;
    }

    /**
     * Group membership of the given users in the course.
     *
     * @param int $courseid
     * @param int[] $userids
     * @param int $groupingid Restrict to the groups of this grouping, if not 0.
     * @return array userid => int[] group ids
     */
    protected static function user_groups(int $courseid, array $userids, int $groupingid = 0): array {
        global $DB;
        $map = [];
        foreach (array_chunk(array_values($userids), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['courseid'] = $courseid;
            $join = '';
            if ($groupingid) {
                $join = 'JOIN {groupings_groups} gg ON gg.groupid = g.id AND gg.groupingid = :groupingid';
                $params['groupingid'] = $groupingid;
            }
            $rs = $DB->get_recordset_sql("SELECT gm.id, gm.userid, gm.groupid
                                            FROM {groups_members} gm
                                            JOIN {groups} g ON g.id = gm.groupid
                                                 $join
                                           WHERE g.courseid = :courseid AND gm.userid $insql", $params);
            foreach ($rs as $r) {
                $map[(int)$r->userid][] = (int)$r->groupid;
            }
            $rs->close();
        }
        return $map;
    }

    /**
     * Release state from the gradebook: a grade is released once it is in the
     * gradebook and neither the item nor the grade is hidden (or hidden-until
     * has passed). assign (with or without marking workflow), quiz review
     * options, peerwork and coursework all publish to the gradebook only when
     * the student may see the grade, so this is the plugins' own answer.
     *
     * @param \cm_info $cm
     * @param int[] $userids
     * @return array userid => (object)['timereleased' => int, 'display' => string]
     */
    protected static function gradebook_release(\cm_info $cm, array $userids): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course,
        ]);
        if (!$item || (int)$item->hidden === 1 || !$userids) {
            return [];
        }
        $itemhiddenuntil = (int)$item->hidden > 1 ? (int)$item->hidden : 0;
        return self::chunked($userids, function (array $chunk) use ($DB, $item, $itemhiddenuntil) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['itemid'] = $item->id;
            $grades = $DB->get_records_sql("SELECT userid, finalgrade, hidden, timemodified, timecreated
                                              FROM {grade_grades}
                                             WHERE itemid = :itemid AND finalgrade IS NOT NULL AND userid $insql", $params);
            $out = [];
            foreach ($grades as $g) {
                if ((int)$g->hidden === 1) {
                    continue;
                }
                // Modules store the date graded in timemodified and the date submitted in
                // timecreated, so only timemodified says when the grade arrived.
                $graded = (int)$g->timemodified ?: (int)$g->timecreated;
                $time = max($graded, (int)$g->hidden, $itemhiddenuntil);
                $out[(int)$g->userid] = (object)[
                    'timereleased' => $time ?: time(),
                    'display' => grade_format_gradevalue($g->finalgrade, $item, true),
                ];
            }
            return $out;
        });
    }

    /**
     * Users whose grade is visible now (hidden-until dates in the future don't count yet).
     *
     * @param \cm_info $cm
     * @param int[] $userids
     * @return bool[] userid => true
     */
    protected static function released_now(\cm_info $cm, array $userids): array {
        $now = time();
        $out = [];
        foreach (self::gradebook_release($cm, $userids) as $userid => $release) {
            if ($release->timereleased <= $now) {
                $out[$userid] = true;
            }
        }
        return $out;
    }

    /**
     * Users whose grade is visible now and was released after their latest submission.
     *
     * @param \cm_info $cm
     * @param \stdClass[] $submissions Keyed by user id, with timemodified.
     * @return bool[] userid => true
     */
    protected static function released_since(\cm_info $cm, array $submissions): array {
        $now = time();
        $out = [];
        foreach (self::gradebook_release($cm, array_keys($submissions)) as $userid => $release) {
            if ($release->timereleased <= $now && $release->timereleased >= (int)$submissions[$userid]->timemodified) {
                $out[$userid] = true;
            }
        }
        return $out;
    }

    /**
     * Applies gradebook release to student rows.
     *
     * @param \cm_info $cm
     * @param student_row[] $rows Keyed by user id; changed in place.
     */
    protected static function apply_gradebook_release(\cm_info $cm, array $rows): void {
        foreach (self::gradebook_release($cm, array_keys($rows)) as $userid => $release) {
            $rows[$userid]->timereleased = $release->timereleased;
            $rows[$userid]->gradedisplay = $release->display;
        }
    }

    /**
     * Whether marking_rows() itself handles an assessment where students hand nothing in online. When
     * not, the indexer uses no_submission_units().
     *
     * @return bool
     */
    public function marks_without_submissions(): bool {
        return false;
    }

    /**
     * Marking units where students hand nothing in online: every student has one, ready at their
     * deadline, marked once the gradebook has their grade and released when the student can see it.
     *
     * @param \cm_info $cm
     * @param int[] $userids
     * @return marking_row[]
     */
    public static function no_submission_units(\cm_info $cm, array $userids): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        if (!$userids) {
            return [];
        }
        $item = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course,
        ]);
        $grades = !$item ? [] : self::chunked($userids, function (array $chunk) use ($DB, $item) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params['itemid'] = $item->id;
            return $DB->get_records_sql("SELECT userid, usermodified, timemodified
                                           FROM {grade_grades}
                                          WHERE itemid = :itemid AND finalgrade IS NOT NULL AND userid $insql", $params);
        });
        $released = self::released_now($cm, array_keys($grades));
        $rows = [];
        foreach ($userids as $userid) {
            $g = $grades[$userid] ?? null;
            $rows[] = new marking_row(
                unitid: (int)$userid,
                unittype: marking_row::UNIT_USER,
                stage: 'marked',
                submitted: true,
                marked: $g !== null,
                markedby: $g && $g->usermodified ? (int)$g->usermodified : null,
                timemarked: $g ? (int)$g->timemodified : null,
                released: isset($released[$userid]),
                // Ready at the student's deadline: the indexer moves it there.
                timeready: 1,
            );
        }
        return $rows;
    }
}
