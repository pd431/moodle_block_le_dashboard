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
use block_my_assessments\local\index\student_row;
use block_my_assessments\local\mitigation;
use block_my_assessments\local\output\format;
use block_my_assessments\local\scope;

/**
 * The student view: my own assessments, in Overdue, Upcoming and Past.
 *
 * One query on the index, then the live uservisible check on the few rows
 * returned (R1: any restriction hides the assessment, dates included).
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student extends base {
    /** Overlay state => icon. Tones come from the state map in scss/styles.scss. */
    const OVERLAY_ICONS = [
        'overdue' => 'fa-circle-exclamation',
        'submitted' => 'fa-circle-check',
        'late' => 'fa-hourglass-end',
        'duesoon' => 'fa-clock',
        'notsubmitted' => 'fa-circle-xmark',
        student_row::MITIGATION_SELFCERT => 'fa-hand-holding-heart',
        student_row::MITIGATION_EXTENSION => 'fa-calendar-plus',
    ];

    /** A pending assessment is due soon within this many days (rc6). */
    const DUESOON_DAYS = 7;

    /** @var array|null Rows, once loaded. */
    protected ?array $rows = null;

    #[\Override]
    public function key(): string {
        return scope::STUDENT;
    }

    /**
     * The student's visible index rows, with the live access check applied.
     *
     * @return \stdClass[] Keyed by cmid.
     */
    protected function rows(): array {
        global $DB;
        if ($this->rows !== null) {
            return $this->rows;
        }
        // Active enrolments in courses the student can see (D9, P10).
        $courses = array_filter(
            enrol_get_all_users_courses($this->userid, true, 'id, shortname, visible'),
            fn($c) => $c->visible || has_capability(
                'moodle/course:viewhiddencourses',
                \context_course::instance($c->id),
                $this->userid
            )
        );
        if (!$courses) {
            return $this->rows = [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        $params['userid'] = $this->userid;
        $records = $DB->get_records_sql("SELECT c.cmid, c.courseid, c.modname, c.name, c.assesstype, c.markingdue,
                                                c.hiddenfromstudents, c.timeindexed,
                                                s.due, s.cutoff, s.individual, s.submitted, s.timesubmitted,
                                                s.timereleased, s.gradedisplay, s.mitigation
                                           FROM {block_my_assessments_stu} s
                                           JOIN {block_my_assessments_cm} c ON c.cmid = s.cmid
                                          WHERE s.userid = :userid AND s.canaccess = 1 AND c.hiddenfromstudents = 0
                                            AND c.courseid $insql", $params);
        $rows = [];
        foreach ($records as $r) {
            $modinfo = get_fast_modinfo($r->courseid, $this->userid);
            $cm = $modinfo->cms[$r->cmid] ?? null;
            if (!$cm || !$cm->uservisible) {
                continue;
            }
            $r->instance = (int)$cm->instance;
            $r->courseshortname = $courses[$r->courseid]->shortname;
            $rows[(int)$r->cmid] = $r;
        }
        return $this->rows = $rows;
    }

    #[\Override]
    public function scope_cmids(): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'block_my_assessments_stu',
            'cmid',
            'userid = :userid',
            ['userid' => $this->userid]
        ));
    }

    #[\Override]
    public function export(): array {
        $threshold = config::overdue_threshold_days();
        $sections = [classifier::OVERDUE => [], classifier::UPCOMING => [], classifier::PAST => []];
        $counts = [
            'overdue' => format::zero_counts(),
            'upcoming' => format::zero_counts(),
            'submitted' => format::zero_counts(),
        ];
        $oldest = null;
        $hasgrades = false;
        foreach ($this->rows() as $r) {
            [$section, $status] = classifier::student($r, $this->now, $threshold);
            $row = $this->row($r, $section, $status);
            $hasgrades = $hasgrades || !empty($row['grade']);
            $sections[$section][] = $row + ['sortkey' => (int)$r->due];
            if ($section !== classifier::PAST) {
                format::count($counts[$section], $r->assesstype);
            }
            if ((int)$r->submitted === 1) {
                format::count($counts['submitted'], $r->assesstype);
            }
            $oldest = $oldest === null ? (int)$r->timeindexed : min($oldest, (int)$r->timeindexed);
        }
        // Soonest first, except Past: most recent first.
        foreach ($sections as $key => &$rows) {
            $order = $key === classifier::PAST ? -1 : 1;
            usort($rows, fn($a, $b) => $order * ($a['sortkey'] <=> $b['sortkey']));
        }
        unset($rows);

        $summary = [
            $this->box('overdue', $counts['overdue']),
            $this->box('upcoming', $counts['upcoming']),
            $this->box('submitted', $counts['submitted']),
        ];
        if ($selfcert = mitigation::selfcert($this->userid)) {
            if ($selfcert['max'] === null) {
                // No limit: the count is the value, with a note under it.
                $box = $this->box('selfcert', ['both' => $selfcert['used']] + format::zero_counts());
                $box['subnote'] = get_string('selfcertnolimit', 'block_my_assessments');
            } else {
                $box = $this->box(
                    'selfcert',
                    format::zero_counts(),
                    null,
                    null,
                    get_string('selfcertused', 'block_my_assessments', ['used' => $selfcert['used'], 'max' => $selfcert['max']])
                );
            }
            $summary[] = $box;
        }

        return $this->context($summary, [
            $this->section(classifier::OVERDUE, $sections[classifier::OVERDUE]),
            $this->section(classifier::UPCOMING, $sections[classifier::UPCOMING]),
            $this->section(classifier::PAST, $sections[classifier::PAST]),
        ], $oldest, [
            'hasgradestoggle' => $hasgrades,
            'showgrades' => (bool)get_user_preferences('block_my_assessments_showgrades', false, $this->userid),
        ]);
    }

    /**
     * The modifier icons on a row's activity icon, and the sentences that describe them.
     *
     * @param \stdClass $r
     * @param string $section
     * @param string $status
     * @return array [status overlay or null, mitigation overlay or null, sentences]
     */
    protected function modifiers(\stdClass $r, string $section, string $status): array {
        $parts = [];
        $mitigation = null;
        if ($r->mitigation && isset(self::OVERLAY_ICONS[$r->mitigation])) {
            $mitigation = ['state' => $r->mitigation, 'icon' => self::OVERLAY_ICONS[$r->mitigation]];
            $parts[] = get_string('tip_' . $r->mitigation, 'block_my_assessments');
        }
        $state = null;
        if ($section === classifier::OVERDUE) {
            // The red overlay is rc6's and only appears in the actionable Overdue section.
            $state = 'overdue';
        } else if (in_array($status, [classifier::STATUS_SUBMITTED, classifier::STATUS_INMARKING, classifier::STATUS_RELEASED])) {
            $late = $r->due && $r->timesubmitted && (int)$r->timesubmitted > (int)$r->due;
            $state = $late ? 'late' : 'submitted';
        } else if (in_array($status, [classifier::STATUS_NOTSUBMITTED, classifier::STATUS_CLOSED])) {
            $state = 'notsubmitted';
        } else if ($r->due && (int)$r->due - $this->now <= self::DUESOON_DAYS * DAYSECS) {
            $state = 'duesoon';
        }
        if ($state) {
            $parts[] = get_string('tip_' . $state, 'block_my_assessments');
        }
        return [$state ? ['state' => $state, 'icon' => self::OVERLAY_ICONS[$state]] : null, $mitigation, $parts];
    }

    /**
     * One student row.
     *
     * @param \stdClass $r
     * @param string $section
     * @param string $status
     * @return array
     */
    protected function row(\stdClass $r, string $section, string $status): array {
        $row = format::row($r, $r->courseshortname, $this->key());
        $row['status'] = ['key' => $status, 'label' => get_string('status_' . $status, 'block_my_assessments')];
        [$row['overlay'], $row['mitigationoverlay'], $parts] = $this->modifiers($r, $section, $status);
        $row['tip'] = format::tip($row['modlabel'], $parts);

        $dates = [];
        if ($r->due) {
            $note = $r->individual ? get_string('individualdeadline', 'block_my_assessments') : null;
            $dates[] = format::date('due', (int)$r->due, $note);
        }
        if ((int)$r->submitted === 1 && $r->timesubmitted) {
            $dates[] = format::date('submitted', (int)$r->timesubmitted);
        }
        $released = $status === classifier::STATUS_RELEASED;
        if ($released) {
            $dates[] = format::date('released', (int)$r->timereleased);
            if ($r->gradedisplay !== null && $r->gradedisplay !== '') {
                $row['grade'] = ['text' => $r->gradedisplay];
            }
        } else if ($status === classifier::STATUS_INMARKING && $r->markingdue) {
            $dates[] = format::date('feedbackexpected', (int)$r->markingdue);
        }
        $row['dates'] = $dates;

        $actions = [];
        if (
            in_array($status, [classifier::STATUS_PENDING, classifier::STATUS_NOTSUBMITTED])
                && $section !== classifier::PAST
        ) {
            $actions[] = ['key' => 'submit', 'label' => get_string('action_submit', 'block_my_assessments'),
                'url' => $row['url']];
            $links = mitigation::links($r->modname, $r->instance);
            if ($links) {
                $row['mitigation'] = ['id' => $row['id'] . '-mitigate', 'links' => array_map(fn($h) => ['html' => $h], $links)];
            }
        } else {
            $actions[] = format::view_action($row['url']);
        }
        $row['actions'] = $actions;
        return $row;
    }
}
