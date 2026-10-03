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

namespace block_my_assessments\local\adapter;

use block_my_assessments\local\index\cm_row;
use block_my_assessments\local\index\marking_row;

/**
 * Adapter for mod_peerwork (written against mod_peerwork 4.5.0, version 2025032200).
 *
 * Peerwork is submitted by groups: one peerwork_submission per group, no
 * allocation, one marking stage. A student's group is their only group in
 * the activity's grouping, as peerwork_get_mygroup() requires. Grades are
 * released per submission (peerwork_submission.released is a timestamp)
 * and reach the gradebook only then.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class peerwork extends base {
    #[\Override]
    public function modname(): string {
        return 'peerwork';
    }

    #[\Override]
    public static function events(): array {
        return [
            '\mod_peerwork\event\assessable_submitted',
            '\mod_peerwork\event\submission_created',
            '\mod_peerwork\event\submission_updated',
            '\mod_peerwork\event\submission_cleared',
            '\mod_peerwork\event\submission_graded',
            '\mod_peerwork\event\grades_released',
        ];
    }

    /** Only the columns this adapter reads. */
    const INSTANCE_FIELDS = 'id, course, fromdate, duedate, allowlatesubmissions, pwgroupingid';

    #[\Override]
    public function cm_row(\cm_info $cm): ?cm_row {
        $p = $this->instance($cm);
        $due = self::date($p->duedate);
        return new cm_row(
            opens: self::date($p->fromdate),
            due: $due,
            cutoff: empty($p->allowlatesubmissions) ? $due : null,
            markingdue: null,
            hasallocation: false,
            stages: ['marked'],
        );
    }

    /**
     * Each student's group, when they have exactly one in the activity's grouping.
     *
     * @param \cm_info $cm
     * @param \stdClass $p
     * @param int[] $userids
     * @return int[] userid => groupid
     */
    protected function groups(\cm_info $cm, \stdClass $p, array $userids): array {
        $out = [];
        foreach (self::user_groups($cm->course, $userids, (int)$p->pwgroupingid) as $userid => $groupids) {
            if (count($groupids) === 1) {
                $out[$userid] = reset($groupids);
            }
        }
        return $out;
    }

    /**
     * Submissions by group.
     *
     * @param \stdClass $p
     * @return \stdClass[] Keyed by group id.
     */
    protected function submissions(\stdClass $p): array {
        global $DB;
        return $DB->get_records(
            'peerwork_submission',
            ['peerworkid' => $p->id],
            '',
            'groupid, timecreated, timemodified, grade, gradedby, timegraded, released'
        );
    }

    #[\Override]
    public function student_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        $p = $this->instance($cm);
        $rows = self::default_rows($cmrow, $userids);
        $submissions = $this->submissions($p);
        foreach ($this->groups($cm, $p, array_keys($rows)) as $userid => $groupid) {
            if (isset($submissions[$groupid])) {
                $s = $submissions[$groupid];
                $rows[$userid]->submitted = true;
                $rows[$userid]->timesubmitted = (int)($s->timecreated ?: $s->timemodified);
            }
        }
        self::apply_gradebook_release($cm, $rows);
        return $rows;
    }

    #[\Override]
    public function marking_rows(\cm_info $cm, cm_row $cmrow, array $userids): array {
        $p = $this->instance($cm);
        $submissions = $this->submissions($p);
        // Only groups with at least one indexed student count.
        $groups = array_unique(array_values($this->groups($cm, $p, $userids)));
        $rows = [];
        foreach ($groups as $groupid) {
            if (!isset($submissions[$groupid])) {
                continue;
            }
            $s = $submissions[$groupid];
            $marked = !empty($s->timegraded);
            $rows[] = new marking_row(
                unitid: (int)$groupid,
                unittype: marking_row::UNIT_GROUP,
                stage: 'marked',
                allocatedto: null,
                submitted: true,
                marked: $marked,
                markedby: $marked ? ((int)$s->gradedby ?: null) : null,
                timemarked: $marked ? (int)$s->timegraded : null,
                released: !empty($s->released),
                timeready: (int)($s->timemodified ?: $s->timecreated) ?: null,
            );
        }
        return $rows;
    }
}
