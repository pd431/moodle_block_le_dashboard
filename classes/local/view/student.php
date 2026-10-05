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
use block_le_dashboard\local\index\student_row;
use block_le_dashboard\local\mitigation;
use block_le_dashboard\local\output\format;
use block_le_dashboard\local\scope;

/**
 * The student view: my own assessments, in Overdue, Upcoming and Past.
 *
 * One query on the index, then the live uservisible check on the few rows
 * returned (R1: any restriction hides the assessment, dates included).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student extends base {
    /**
     * Overlay state => icon, for the unusual case only: nothing marks work handed in on time
     * (Pierre, 2026-10-04). Late is a clock, so due soon is an hourglass. Tones come from the
     * state map in scss/styles.scss.
     */
    const OVERLAY_ICONS = [
        'overdue' => 'fa-clock',
        'late' => 'fa-clock',
        'duesoon' => 'fa-hourglass-half',
        'notsubmitted' => 'fa-circle-xmark',
        student_row::MITIGATION_SELFCERT => 'fa-hand-holding-heart',
        student_row::MITIGATION_EXTENSION => 'fa-calendar-plus',
    ];

    /** Assessment types offered Mitigate: summative only (D8, Pierre 2026-10-04). */
    const MITIGATION_TYPES = ['summative'];

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
            enrol_get_all_users_courses($this->userid, true, 'id, shortname, fullname, visible, cacherev'),
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
                                                s.timereleased, s.gradedisplay, s.mitigation, s.reopened, s.enrolstart
                                           FROM {block_le_dashboard_stu} s
                                           JOIN {block_le_dashboard_cm} c ON c.cmid = s.cmid
                                          WHERE s.userid = :userid AND s.canaccess = 1 AND c.hiddenfromstudents = 0
                                            AND c.courseid $insql", $params);
        $rows = [];
        foreach ($records as $r) {
            // The course record is at hand, so modinfo needn't fetch it again (F7).
            $modinfo = get_fast_modinfo($courses[$r->courseid], $this->userid);
            $cm = $modinfo->cms[$r->cmid] ?? null;
            if (!$cm || !$cm->uservisible) {
                continue;
            }
            $r->instance = (int)$cm->instance;
            $r->courseshortname = $courses[$r->courseid]->shortname;
            $r->coursefullname = $courses[$r->courseid]->fullname;
            $rows[(int)$r->cmid] = $r;
        }
        return $this->rows = $rows;
    }

    #[\Override]
    public function scope_cmids(): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'block_le_dashboard_stu',
            'cmid',
            'userid = :userid',
            ['userid' => $this->userid]
        ));
    }

    #[\Override]
    protected function scope_courses(): array {
        return array_map('intval', array_keys(enrol_get_all_users_courses($this->userid, true, 'id')));
    }

    #[\Override]
    public function export(): array {
        $threshold = config::overdue_threshold_days();
        $grace = config::grace();
        $sections = [classifier::OVERDUE => [], classifier::UPCOMING => [], classifier::UNDATED => [], classifier::PAST => []];
        $counts = [
            'overdue' => format::zero_counts(),
            'upcoming' => format::zero_counts(),
            'submitted' => format::zero_counts(),
        ];
        $oldest = null;
        $hasgrades = false;
        foreach ($this->rows() as $r) {
            [$section, $status] = classifier::student($r, $this->now, $threshold, $grace);
            $row = $this->row($r, $section, $status);
            $hasgrades = $hasgrades || !empty($row['grade']);
            // Undated work in Past sorts by when feedback was given (Pierre, 2026-10-04).
            $sortkey = $r->due === null && $section === classifier::PAST ? (int)$r->timereleased : (int)$r->due;
            $sections[$section][] = $row + ['sortkey' => $sortkey];
            if (isset($counts[$section])) {
                format::count($counts[$section], $r->assesstype);
            }
            if ((int)$r->submitted === 1) {
                format::count($counts['submitted'], $r->assesstype);
            }
            $oldest = $oldest === null ? (int)$r->timeindexed : min($oldest, (int)$r->timeindexed);
        }
        // Soonest first, except Past: most recent first. Undated rows by name.
        foreach ($sections as $key => &$rows) {
            $order = $key === classifier::PAST ? -1 : 1;
            usort($rows, fn($a, $b) => $order * ($a['sortkey'] <=> $b['sortkey']) ?: strcmp($a['search'], $b['search']));
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
                $box['subnote'] = get_string('selfcertnolimit', 'block_le_dashboard');
            } else {
                $box = $this->box(
                    'selfcert',
                    format::zero_counts(),
                    null,
                    null,
                    get_string('selfcertused', 'block_le_dashboard', ['used' => $selfcert['used'], 'max' => $selfcert['max']])
                );
            }
            $summary[] = $box;
        }

        return $this->context($summary, array_filter([
            $this->section(classifier::OVERDUE, $sections[classifier::OVERDUE]),
            $this->section(classifier::UPCOMING, $sections[classifier::UPCOMING]),
            $this->undated($sections[classifier::UNDATED]),
            $this->section(classifier::PAST, $sections[classifier::PAST]),
        ]), $oldest, [
            'hasgradestoggle' => $hasgrades,
            'showgrades' => (bool)get_user_preferences('block_le_dashboard_showgrades', false, $this->userid),
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
            $parts[] = get_string('tip_' . $r->mitigation, 'block_le_dashboard');
        }
        $state = null;
        if ($status === classifier::STATUS_REOPENED) {
            // Nothing was missed: no overdue or due-soon icon on a new attempt.
            $state = null;
        } else if ($section === classifier::OVERDUE) {
            // The red overlay is rc6's and only appears in the actionable Overdue section.
            $state = 'overdue';
        } else if (self::is_late($r)) {
            $state = 'late';
        } else if (in_array($status, [classifier::STATUS_NOTSUBMITTED, classifier::STATUS_CLOSED])) {
            $state = 'notsubmitted';
        } else if (
            in_array($status, [classifier::STATUS_PENDING, classifier::STATUS_DUE]) && $r->due && (int)$r->due > $this->now
                && (int)$r->due - $this->now <= self::DUESOON_DAYS * DAYSECS
        ) {
            $state = 'duesoon';
        }
        if ($state) {
            $parts[] = get_string('tip_' . $state, 'block_le_dashboard');
        }
        return [$state ? ['state' => $state, 'icon' => self::OVERLAY_ICONS[$state]] : null, $mitigation, $parts];
    }

    /**
     * Whether the work was handed in after the student's own deadline, extensions included (D2).
     *
     * @param \stdClass $r
     * @return bool
     */
    protected static function is_late(\stdClass $r): bool {
        return (int)$r->submitted === 1 && $r->due && $r->timesubmitted && (int)$r->timesubmitted > (int)$r->due;
    }

    /**
     * Plain lines under the dates (G5), each at the precision its size needs: how late
     * the work was, the late note, time left to submit, and how soon it is due. Neutral
     * text: lateness is information, never a warning colour.
     *
     * @param \stdClass $r
     * @param string $section
     * @param string $status
     * @return string[]
     */
    protected function notes(\stdClass $r, string $section, string $status): array {
        $notes = [];
        $due = $r->due ? (int)$r->due : null;
        $submitted = (int)$r->submitted === 1 && $r->timesubmitted ? (int)$r->timesubmitted : null;
        if ($submitted && $due && $submitted > $due) {
            // Against the effective deadline (D2), so extensions never read as late.
            $notes[] = get_string('submittedlate', 'block_le_dashboard', format::duration($submitted - $due));
        }
        // Time left: to the cut-off, or to the deadline plus the grace period when there is none (G9).
        $until = $r->cutoff ? (int)$r->cutoff : ($due ? $due + config::grace() : null);
        if ($section === classifier::OVERDUE && $until && $until > $this->now) {
            $notes[] = get_string('stillsubmit', 'block_le_dashboard', format::duration($until - $this->now));
        }
        $open = in_array($status, [classifier::STATUS_PENDING, classifier::STATUS_DUE]);
        if ($open && $due && $due > $this->now && $due - $this->now < self::DUESOON_DAYS * DAYSECS) {
            $notes[] = get_string('duein', 'block_le_dashboard', format::duration($due - $this->now));
        }
        return $notes;
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
        // Students get two lines: the module's full name, then "Summative assignment".
        $row['course'] = format_string($r->coursefullname, true, [
            'context' => \context_course::instance($r->courseid),
            'escape' => false,
        ]);
        $row['typeline'] = get_string('rowkind', 'block_le_dashboard', [
            'type' => $row['typelabel'],
            'mod' => \core_text::strtolower($row['modlabel']),
        ]);
        $row['stacked'] = true;
        $row['search'] .= ' ' . \core_text::strtolower($row['course']);
        // The ordinary case has no pill: the section and the Submit button already say it.
        $row['status'] = in_array($status, classifier::PLAIN_STATUSES) ? null
            : ['key' => $status, 'label' => get_string('status_' . $status, 'block_le_dashboard')];
        [$row['overlay'], $row['mitigationoverlay'], $parts] = $this->modifiers($r, $section, $status);
        $row['hasmodifiers'] = $row['overlay'] || $row['mitigationoverlay'];
        $row['tip'] = format::tip($row['modlabel'], $parts);

        $dates = [];
        if ($r->due) {
            $note = $r->individual ? get_string('individualdeadline', 'block_le_dashboard') : null;
            $dates[] = format::date('due', (int)$r->due, $note);
        }
        $released = $status === classifier::STATUS_RELEASED;
        if ($released) {
            // First, under the Feedback released pill, so it never reads as the student's own deadline (D6).
            array_unshift($dates, format::date('released', (int)$r->timereleased));
            if ($r->gradedisplay !== null && $r->gradedisplay !== '') {
                $row['grade'] = ['text' => $r->gradedisplay];
            }
        } else if ($status === classifier::STATUS_INMARKING && $r->markingdue) {
            // The date stays once it has passed, with a hint saying who to contact (G12).
            $dates[] = format::date('feedbackexpected', (int)$r->markingdue);
            if ((int)$r->markingdue < $this->now && ($hint = $this->feedback_hint($row['id']))) {
                $row['hints'][] = $hint;
            }
        }
        $row['dates'] = $dates;
        $row['notes'] = $this->notes($r, $section, $status);
        if ($status === classifier::STATUS_RECEIVED) {
            $text = get_string('hint_received_text', 'block_le_dashboard');
            $row['hints'][] = format::hint($row['id'], 'received', $text);
        }
        // Late summative work: the penalties popover, next to the grade once there is one, else in the row's hints.
        if ($r->assesstype === 'summative' && self::is_late($r) && ($hint = $this->late_hint($row['id']))) {
            if (!empty($row['grade'])) {
                $row['grade']['hint'] = $hint;
            } else {
                $row['hints'][] = $hint;
            }
        }

        $actions = [];
        if (
            in_array($status, [classifier::STATUS_PENDING, classifier::STATUS_NOTSUBMITTED, classifier::STATUS_REOPENED])
                && $section !== classifier::PAST
        ) {
            $actions[] = format::action('submit', $row['url']);
        } else {
            $actions[] = format::view_action($row['url']);
        }
        $row['actions'] = $actions;
        // Mitigate around the student's own deadline, whatever the section or status (G4).
        $mitigable = in_array($r->assesstype, self::MITIGATION_TYPES)
            && config::mitigation_open($r->due ? (int)$r->due : null, $this->now);
        $links = $mitigable ? mitigation::links($r->modname, $r->instance) : [];
        if ($links) {
            $row['mitigation'] = [
                'id' => $row['id'] . '-mitigate',
                'icon' => format::ACTION_ICONS['mitigate'],
                'links' => array_map(fn($h) => ['html' => $h], $links),
            ];
        }
        return $row;
    }

    /**
     * The late penalties popover: the late note setting, and a link to the policy when one is set.
     * With the note cleared there is no popover.
     *
     * @param string $rowid
     * @return array|null
     */
    protected function late_hint(string $rowid): ?array {
        $text = get_config('block_le_dashboard', 'latenote');
        // Unset until an admin saves the new setting: use its default.
        $text = trim($text === false ? get_string('latenote_default', 'block_le_dashboard') : $text);
        if ($text === '') {
            return null;
        }
        $url = trim((string)get_config('block_le_dashboard', 'latenoteurl'));
        $link = $url === '' ? null : get_string('hint_latepenalty_link', 'block_le_dashboard', \html_writer::link(
            new \moodle_url($url),
            get_string('hint_latepenalty_linktext', 'block_le_dashboard')
        ));
        return format::hint($rowid, 'latepenalty', format_string($text, true, ['escape' => false]), $link);
    }

    /**
     * The hint on work in marking whose feedback date has passed: feedback should arrive
     * soon, and who to contact if it doesn't. The contact text and link are settings; with
     * the contact cleared, there is no hint, as it would point nowhere (D9).
     *
     * @param string $rowid
     * @return array|null
     */
    protected function feedback_hint(string $rowid): ?array {
        $text = get_config('block_le_dashboard', 'feedbackcontact');
        // Unset until an admin saves the new setting: use its default.
        $text = trim($text === false ? get_string('feedbackcontact_default', 'block_le_dashboard') : $text);
        if ($text === '') {
            return null;
        }
        $url = trim((string)get_config('block_le_dashboard', 'feedbackcontacturl'));
        $who = $url !== '' ? \html_writer::link(new \moodle_url($url), format_string($text)) : format_string($text);
        $contact = get_string('hint_feedbackcontact_contact', 'block_le_dashboard', $who);
        return format::hint($rowid, 'feedbackcontact', get_string('hint_feedbackcontact_text', 'block_le_dashboard'), $contact);
    }
}
