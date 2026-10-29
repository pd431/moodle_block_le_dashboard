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
use block_le_dashboard\local\guidance;
use block_le_dashboard\local\index\student_row;
use block_le_dashboard\local\mitigation;
use block_le_dashboard\local\output\format;
use block_le_dashboard\local\scope;

/**
 * The student view: my own assessments, in Overdue, Upcoming and Past, of the selected academic year.
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
     *. Late is a clock, so due soon is an hourglass. Tones come from the
     * state map in scss/styles.scss.
     */
    const OVERLAY_ICONS = [
        'overdue' => 'fa-clock',
        'late' => 'fa-clock',
        'duesoon' => 'fa-hourglass-half',
        'notsubmitted' => 'fa-circle-xmark',
        student_row::MITIGATION_SELFCERT => 'fa-hand-holding-heart',
        student_row::MITIGATION_EXTENSION => 'fa-calendar-plus',
        // One icon for both: the student needn't do this one (CS4).
        student_row::MITIGATION_EXEMPT => 'fa-user-check',
        student_row::MITIGATION_DEFERRED => 'fa-user-check',
    ];

    /** Assessment types offered Mitigate: summative only (D8). */
    const MITIGATION_TYPES = ['summative'];

    /** @var array|null Self-certified extensions used and allowed, once loaded. */
    protected $selfcertcount = null;

    /** @var bool Whether $selfcertcount is loaded. */
    protected $selfcertloaded = false;

    /** Reasons the "Can't resubmit" control can give. */
    const LOCK_REASONS = [student_row::LOCK_FINALISED, student_row::LOCK_TEACHER, student_row::LOCK_ATTEMPTS];

    /** A pending assessment is due soon within this many days (rc6). */
    const DUESOON_DAYS = 7;

    /** The deadline that places a row in an academic year: the student's own (EC3d). */
    const DEADLINES = ['s.due'];

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
        [$yearsql, $yearparams] = $this->in_year_sql('c', self::DEADLINES);
        $params += $yearparams;
        $records = $DB->get_records_sql("SELECT c.cmid, c.courseid, c.modname, c.name, c.assesstype, c.markingdue, c.opens,
                                                c.hiddenfromstudents, c.nosubmission, c.norelease, c.timeindexed,
                                                s.due, s.cutoff, s.individual, s.submitted, s.timesubmitted,
                                                s.timereleased, s.gradedisplay, s.mitigation, s.reopened, s.lockreason, s.enrolstart
                                           FROM {block_le_dashboard_stu} s
                                           JOIN {block_le_dashboard_cm} c ON c.cmid = s.cmid
                                          WHERE s.userid = :userid AND s.canaccess = 1 AND c.hiddenfromstudents = 0
                                            AND c.courseid $insql AND $yearsql", $params);
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
        [$yearsql, $yearparams] = $this->in_year_sql('c', self::DEADLINES);
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT s.cmid
               FROM {block_le_dashboard_stu} s
               JOIN {block_le_dashboard_cm} c ON c.cmid = s.cmid
              WHERE s.userid = :userid AND $yearsql",
            ['userid' => $this->userid] + $yearparams
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
            // Undated work in Past sorts by when feedback was given.
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
        if ($selfcert = $this->selfcert()) {
            if ($selfcert['max'] === null) {
                // No limit: the count is the value, with a note under it.
                $box = $this->box('selfcert', ['both' => $selfcert['used']] + format::zero_counts());
                $box['subnote'] = get_string('selfcertnolimit', 'block_le_dashboard');
            } else {
                // Counting down, as the Mitigate menu does.
                $left = max(0, $selfcert['max'] - $selfcert['used']);
                $box = $this->box(
                    'selfcert',
                    format::zero_counts(),
                    null,
                    null,
                    $left ? get_string('selfcertleft', 'block_le_dashboard', ['left' => $left, 'max' => $selfcert['max']])
                        : get_string('selfcertallused', 'block_le_dashboard', $selfcert['max'])
                );
                if (!$left) {
                    $box['subnote'] = get_string('mitigate_stillrequest', 'block_le_dashboard');
                }
            }
            $summary[] = $box;
        }

        // A student's Refresh rewrites only their own rows and leaves each assessment's index time alone,
        // so the line also counts this view's last Refresh that finished (Pierre's screenshot).
        if ($oldest !== null) {
            $last = json_decode((string)get_user_preferences('block_le_dashboard_lastrefresh', '', $this->userid), true);
            $oldest = max($oldest, is_array($last) ? (int)($last[scope::STUDENT] ?? 0) : 0);
        }
        return $this->context($summary, array_filter([
            $this->section(classifier::OVERDUE, $sections[classifier::OVERDUE]),
            $this->section(classifier::UPCOMING, $sections[classifier::UPCOMING]),
            $this->undated($sections[classifier::UNDATED]),
            $this->section(classifier::PAST, $sections[classifier::PAST]),
        ]), $oldest, [
            'hasyears' => true,
            'years' => $this->years(),
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
        if ($status === classifier::STATUS_EXEMPT) {
            // Nothing is expected: no late, overdue or due-soon icon (CS4).
            $state = null;
        } else if ($status === classifier::STATUS_REOPENED) {
            // Nothing was missed: no overdue or due-soon icon on a new attempt.
            $state = null;
        } else if ($section === classifier::OVERDUE) {
            // The red overlay is rc6's and only appears in the actionable Overdue section.
            $state = 'overdue';
        } else if (self::is_late($r)) {
            $state = 'late';
        } else if ($status === classifier::STATUS_NOTSUBMITTED) {
            // Not for Closed: the block can't see those submissions (F6).
            $state = 'notsubmitted';
        } else if (
            in_array($status, [classifier::STATUS_PENDING, classifier::STATUS_DUE]) && !$this->not_yet_open($r)
                && $r->due && (int)$r->due > $this->now
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
     * Whether the activity hasn't opened for submissions yet (quiz open, assignment "allow submissions from",
     * coursework start, peer work from): the student can't hand anything in, so it isn't due soon and has no
     * Submit (Pierre's UAT).
     *
     * @param \stdClass $r
     * @return bool
     */
    protected function not_yet_open(\stdClass $r): bool {
        return !empty($r->opens) && (int)$r->opens > $this->now && (int)$r->submitted !== 1;
    }

    /**
     * Whether the work was handed in after the student's own deadline, extensions included (D2).
     *
     * @param \stdClass $r
     * @return bool
     */
    protected static function is_late(\stdClass $r): bool {
        // Work within the late tolerance isn't late (beta27 hints audit).
        $tolerance = config::number('latetolerance') * MINSECS;
        return (int)$r->submitted === 1 && $r->due && $r->timesubmitted
            && (int)$r->timesubmitted > (int)$r->due + $tolerance;
    }


    /**
     * Plain lines under the dates (G5), each at the precision its size needs: time left to submit,
     * and how soon it is due, on every row still open for submission. How late work was is the clock's title, not a line.
     *
     * @param \stdClass $r
     * @param string $section
     * @param string $status
     * @return string[]
     */
    protected function notes(\stdClass $r, string $section, string $status): array {
        $notes = [];
        $due = $r->due ? (int)$r->due : null;
        // Time left: to the cut-off (a quiz's close is its cut-off). Without one Moodle keeps taking the work, so
        // there's no countdown (B9).
        $until = $r->cutoff ? (int)$r->cutoff : null;
        if ($section === classifier::OVERDUE && $until && $until > $this->now) {
            $notes[] = format::countdown('stillsubmit', $until, $this->now);
        }
        // How soon it is due, however far away; the due-soon mark keeps its window (DUESOON_DAYS).
        $open = in_array($status, [classifier::STATUS_PENDING, classifier::STATUS_DUE]) && !$this->not_yet_open($r);
        if ($open && $due && $due > $this->now) {
            $notes[] = format::countdown('duein', $due, $this->now);
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
        // Nothing to hand in online: say so while the date is ahead.
        $row['hints'] = !empty($r->nosubmission) && $section !== classifier::PAST && config::shown('hint_nosubmission')
            ? [format::nosubmission_hint($row['id'], false)] : [];

        $dates = [];
        if ($this->not_yet_open($r)) {
            // Said first, so the row reads "Opens ..., Due ..." (Pierre's UAT).
            $dates[] = format::date('opens', (int)$r->opens);
        }
        if ($r->due) {
            // An extension is ordinary upcoming work: no note, but its date says it is the extended one (CS5).
            $extended = in_array($r->mitigation, [student_row::MITIGATION_EXTENSION, student_row::MITIGATION_SELFCERT], true);
            $dates[] = format::date('due', (int)$r->due, $extended ? 'extendeddeadline' : null);
        }
        $released = $status === classifier::STATUS_RELEASED;
        if ($released) {
            // After the deadline, in the order things happen: its label says what it is (replacing D6).
            $dates[] = format::date('released', (int)$r->timereleased);
            if ($r->gradedisplay !== null && $r->gradedisplay !== '') {
                $row['grade'] = ['text' => $r->gradedisplay];
            }
        } else if ($status === classifier::STATUS_INMARKING && ($feedback = config::feedback_date($r)) !== null) {
            // The date stays once it has passed, with a hint saying who to contact (G12).
            $date = format::date('feedbackexpected', $feedback);
            // The question-circle beside the date once the date students see has passed, saying who to contact (G12).
            if ($feedback < $this->now) {
                $date['hint'] = $this->feedback_hint($row['id']);
            }
            $dates[] = $date;
        }
        $row['dates'] = $dates;
        // With two dates or more, every label on its own line so the dates line up.
        $row['stackdates'] = count($dates) > 1;
        $row['notes'] = $this->notes($r, $section, $status);
        if ($status === classifier::STATUS_EXEMPT) {
            // Exempt or deferred: no actions at all, not even Mitigate (CS4).
            $row['actions'] = [];
            return $row;
        }
        // Late work: the clock beside the grade once there is one, else beside the status pill.
        if (self::is_late($r) && config::shown('hint_late')) {
            $hint = $this->late_hint($row['id'], $r);
            if (!empty($row['grade'])) {
                $row['grade']['hint'] = $hint;
            } else {
                $row['statushint'] = $hint;
            }
        }

        $actions = [];
        $open = [classifier::STATUS_PENDING, classifier::STATUS_NOTSUBMITTED, classifier::STATUS_REOPENED];
        if ($status === classifier::STATUS_SUBMITTED && $section !== classifier::PAST && $r->lockreason) {
            // Final before the deadline: say why instead of offering Submit.
            $row['locked'] = $this->lock_control($row['id'], $r->lockreason);
        } else if ($section !== classifier::PAST && !classifier::submittable($r, $this->now)) {
            // Nothing can be handed in before the deadline: a teacher's lock (B1), or every quiz attempt used with
            // none finished.
            if ($r->lockreason) {
                $row['locked'] = $this->lock_control($row['id'], $r->lockreason, false);
            } else {
                $actions[] = format::view_action($row['url']);
            }
        } else if ($this->not_yet_open($r)) {
            // Nothing can be handed in yet: View, not Submit.
            $actions[] = format::view_action($row['url']);
        } else if (in_array($status, $open) && $section !== classifier::PAST) {
            $actions[] = format::action('submit', $row['url']);
        } else if ($status === classifier::STATUS_SUBMITTED && $section === classifier::UPCOMING) {
            // Handed in and still open to change: Submit takes the student back to it.
            $actions[] = format::action('submit', $row['url']);
        } else {
            $actions[] = format::view_action($row['url']);
        }
        $row['actions'] = $actions;
        if ($menu = $this->mitigation_menu($r)) {
            $row['mitigation'] = [
                'id' => $row['id'] . '-mitigate',
                'icon' => format::ACTION_ICONS['mitigate'],
                'items' => $menu['items'],
            ];
        }
        return $row;
    }

    /**
     * The "Can't resubmit" control: why the work can't be changed, and who can help.
     *
     * @param string $rowid
     * @param string $reason A student_row::LOCK_ constant.
     * @param bool $submitted False for a teacher's lock before anything was handed in: "Can't submit".
     * @return array
     */
    protected function lock_control(string $rowid, string $reason, bool $submitted = true): array {
        $reason = in_array($reason, self::LOCK_REASONS) ? $reason : student_row::LOCK_FINALISED;
        return [
            'id' => $rowid . '-locked',
            'icon' => format::ACTION_ICONS['locked'],
            'label' => get_string($submitted ? 'action_locked' : 'action_lockednosubmit', 'block_le_dashboard'),
            'text' => get_string('locked_' . $reason . ($submitted ? '' : 'nosubmit'), 'block_le_dashboard'),
            // Finalised and teacher-locked work share one box; quiz attempts have their own.
            'guidance' => guidance::html($reason === student_row::LOCK_ATTEMPTS ? 'attempts' : 'finalised'),
        ];
    }

    /**
     * Mitigate: on every summative row (D8) where block_rhb gives a real link
     * (mitigation::links()), from the start until the hours set after the student's own deadline, whatever
     * was handed in or released. Work with no deadline keeps it.
     *
     * @param \stdClass $r
     * @return array|null mitigation::links()
     */
    protected function mitigation_menu(\stdClass $r): ?array {
        if (!in_array($r->assesstype, self::MITIGATION_TYPES)) {
            return null;
        }
        if ($r->due && !config::mitigation_open((int)$r->due, $this->now)) {
            return null;
        }
        // The new deadline, when the student self-certified on this one.
        $here = $r->mitigation === student_row::MITIGATION_SELFCERT && $r->due ? (int)$r->due : null;
        return mitigation::links($r->modname, (int)$r->instance, $this->selfcert(), $here);
    }

    /**
     * Self-certified extensions used and allowed (mitigation::selfcert()), once per view.
     *
     * @return array|null
     */
    protected function selfcert(): ?array {
        if (!$this->selfcertloaded) {
            $this->selfcertcount = mitigation::selfcert($this->userid);
            $this->selfcertloaded = true;
        }
        return $this->selfcertcount;
    }

    /**
     * The late clock: "Submitted 2 h late." On summative work with late penalties guidance, the title adds
     * "Late penalties may apply." and the popover shows the guidance under it.
     *
     * @param string $rowid
     * @param \stdClass $r
     * @return array
     */
    protected function late_hint(string $rowid, \stdClass $r): array {
        $late = format::duration((int)$r->timesubmitted - (int)$r->due);
        $guidance = $r->assesstype === 'summative' ? guidance::html('latepenalty') : null;
        $title = get_string($guidance !== null ? 'hint_late_penalties' : 'hint_late', 'block_le_dashboard', $late);
        $hint = format::hint($rowid, 'late', '', null, $title);
        $hint['guidance'] = $guidance;
        return $hint;
    }

    /**
     * The hint on work in marking whose feedback date has passed: feedback should arrive soon, with
     * the guidance on who to contact if it doesn't.
     *
     * @param string $rowid
     * @return array
     */
    protected function feedback_hint(string $rowid): array {
        return format::hint($rowid, 'feedbackcontact', get_string('hint_feedbackcontact_text', 'block_le_dashboard'));
    }
}
