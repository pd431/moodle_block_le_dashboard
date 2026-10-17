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

namespace block_le_dashboard\local\output;

use block_le_dashboard\local\guidance;

/**
 * Small formatting helpers shared by the three views.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format {
    /** Font Awesome icon per activity type. Add a line here for a new adapter. */
    const ICONS = [
        'assign' => 'fa-file-arrow-up',
        'quiz' => 'fa-clipboard-question',
        'coursework' => 'fa-file-pen',
        'peerwork' => 'fa-people-group',
    ];

    /** Icon for any other activity type. */
    const ICON_FALLBACK = 'fa-file-lines';

    /** Font Awesome icon per row action (Show markers has none, as in rc6). */
    const ACTION_ICONS = [
        'submit' => 'fa-upload',
        'view' => 'fa-eye',
        'mitigate' => 'fa-hand-holding-heart',
        'locked' => 'fa-lock',
    ];

    /**
     * A date and time, as rows show it.
     *
     * @param int $time
     * @return string
     */
    public static function datetime(int $time): string {
        return userdate($time, get_string('strftimedue', 'block_le_dashboard'));
    }

    /**
     * A string with a date in it, split around the date so a template can mark it up as a time element and the
     * page can show it in the viewer's own time zone, like the row dates (Pierre, 2026-10-09: "all times ...
     * should look like the server was in china").
     *
     * @param string $identifier A block string whose only placeholder is the date.
     * @param int $time
     * @return array text (all of it, as Moodle draws it), before, date, iso, after
     */
    public static function dated(string $identifier, int $time): array {
        $mark = "\x01";
        [$before, $after] = explode($mark, get_string($identifier, 'block_le_dashboard', $mark), 2) + [1 => ''];
        $date = self::datetime($time);
        return ['text' => $before . $date . $after, 'before' => $before, 'date' => $date, 'iso' => date('c', $time),
            'after' => $after];
    }

    /**
     * Calendar days from one time to another, counted on the dates they fall on in the user's Moodle time zone:
     * 0 for later today, 1 for tomorrow.
     *
     * @param int $from
     * @param int $to
     * @return int
     */
    public static function calendar_days(int $from, int $to): int {
        $zone = \core_date::get_user_timezone_object();
        $utc = new \DateTimeZone('UTC');
        $day = fn(int $t) => new \DateTimeImmutable((new \DateTimeImmutable('@' . $t))->setTimezone($zone)->format('Y-m-d'), $utc);
        $diff = $day($from)->diff($day($to));
        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * A countdown note ("Due in 16 h", "Due tomorrow", "Due in 3 days"). Under a day it's the time left, the same
     * in every zone; from a day it's calendar days to the date the row shows, so it agrees with that date. The page
     * recounts the days in the viewer's own time zone when it shows the dates in it (localtime.js).
     *
     * @param string $kind duein or stillsubmit: the strings it's made of are {kind}, {kind}_tomorrow.
     * @param int $time The deadline.
     * @param int $now
     * @return array text, kind, until (the deadline) and now, in ISO 8601
     */
    public static function countdown(string $kind, int $time, int $now): array {
        $left = $time - $now;
        $days = $left < DAYSECS ? 0 : max(1, self::calendar_days($now, $time));
        if ($days === 1) {
            $text = get_string($kind . '_tomorrow', 'block_le_dashboard');
        } else {
            $text = get_string($kind, 'block_le_dashboard', $days ? get_string('duration_days', 'block_le_dashboard', $days)
                : self::duration($left));
        }
        return ['text' => $text, 'kind' => $kind, 'until' => date('c', $time), 'now' => date('c', $now)];
    }

    /**
     * A labelled date for a row.
     *
     * @param string $key Date key; the label is the date_{key} string, if there is one.
     * @param int $time
     * @return array
     */
    public static function date(string $key, int $time): array {
        $sm = get_string_manager();
        return [
            'key' => $key,
            'label' => $sm->string_exists('date_' . $key, 'block_le_dashboard')
                ? get_string('date_' . $key, 'block_le_dashboard') : null,
            'text' => self::datetime($time),
            'iso' => date('c', $time),
        ];
    }

    /**
     * A length of time in one unit, rounded down: "less than a minute", "12 min", "6 h", "2 days".
     * The unit changes before the number would reach 0, so "0 h" never appears.
     *
     * @param int $seconds
     * @return string
     */
    public static function duration(int $seconds): string {
        $seconds = max(0, $seconds);
        if ($seconds < MINSECS) {
            return get_string('duration_lessminute', 'block_le_dashboard');
        }
        if ($seconds < HOURSECS) {
            return get_string('duration_minutes', 'block_le_dashboard', (int)floor($seconds / MINSECS));
        }
        if ($seconds < DAYSECS) {
            return get_string('duration_hours', 'block_le_dashboard', (int)floor($seconds / HOURSECS));
        }
        $days = (int)floor($seconds / DAYSECS);
        return $days === 1 ? get_string('duration_day', 'block_le_dashboard')
            : get_string('duration_days', 'block_le_dashboard', $days);
    }

    /**
     * "Updated just now", "Updated 12 min ago", "Updated 3 hours ago"...
     *
     * @param int|null $time
     * @param int $now
     * @return string
     */
    public static function updated(?int $time, int $now): string {
        if (!$time) {
            return get_string('updatednever', 'block_le_dashboard');
        }
        $diff = max(0, $now - $time);
        if ($diff < MINSECS) {
            return get_string('updatedjustnow', 'block_le_dashboard');
        }
        if ($diff < HOURSECS) {
            return get_string('updatedminutes', 'block_le_dashboard', (int)floor($diff / MINSECS));
        }
        if ($diff < DAYSECS) {
            return get_string('updatedhours', 'block_le_dashboard', (int)floor($diff / HOURSECS));
        }
        return get_string('updatedon', 'block_le_dashboard', self::datetime($time));
    }

    /**
     * updated(), with the date split out (dated()) when it gives one.
     *
     * @param int|null $time
     * @param int $now
     * @return array|null dated(), or null when the line has no date
     */
    public static function updated_dated(?int $time, int $now): ?array {
        return $time && $now - $time >= DAYSECS ? self::dated('updatedon', $time) : null;
    }

    /**
     * Label of a marking stage key.
     *
     * @param string $stage
     * @return string
     */
    public static function stage(string $stage): string {
        if (preg_match('/^marker(\d+)$/', $stage, $m)) {
            return get_string('stage_markern', 'block_le_dashboard', $m[1]);
        }
        $sm = get_string_manager();
        return $sm->string_exists('stage_' . $stage, 'block_le_dashboard')
            ? get_string('stage_' . $stage, 'block_le_dashboard') : $stage;
    }

    /**
     * A progress bar context.
     *
     * @param string $key Bar key (submitted, unallocated, released, or a stage key).
     * @param string $label
     * @param int $value
     * @param int $max
     * @param bool $groups The units are groups, not students: say so, as submissions count students (G15).
     * @return array
     */
    public static function bar(string $key, string $label, int $value, int $max, bool $groups = false): array {
        $percent = $max > 0 ? (int)round(100 * min($value, $max) / $max) : 0;
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'max' => $max,
            'percent' => $percent,
            'text' => get_string($groups ? 'xofygroups' : 'xofy', 'block_le_dashboard', ['x' => $value, 'y' => $max]),
        ];
    }

    /**
     * A hint on a row: one icon (with its count) that opens a popover with its title and
     * description on a click, keyboard focus or a short hover (Pierre, 2026-10-06). The same
     * component for every hint, in the row's meta line or beside a date, pill or grade.
     *
     * @param string $rowid
     * @param string $key brief, hidden, unallocated, returned, feedbackcontact, extensions, setup,
     *                    late or plagiarism.
     * @param string $text The description.
     * @param string|null $link HTML link sentence after the description, if any.
     * @param string|null $title The popover title and accessible name, when it isn't the hint_{key} string.
     * @param int|null $count A number shown beside the icon.
     * @return array
     */
    public static function hint(
        string $rowid,
        string $key,
        string $text,
        ?string $link = null,
        ?string $title = null,
        ?int $count = null
    ): array {
        return [
            'id' => $rowid . '-hint-' . $key,
            'key' => $key,
            'icon' => self::hint_icon($key),
            'tone' => self::HINT_TONES[$key],
            // Student hints are bare glyphs beside what they explain; staff flags keep their outline.
            'glyph' => in_array($key, self::GLYPH_HINTS),
            'title' => $title ?? get_string('hint_' . $key, 'block_le_dashboard'),
            'count' => $count,
            'text' => $text,
            'link' => $link,
            'guidance' => isset(self::HINT_GUIDANCE[$key]) ? guidance::html(self::HINT_GUIDANCE[$key]) : null,
        ];
    }

    /** The guidance box each hint shows under its factual line (Pierre, 2026-10-09). */
    const HINT_GUIDANCE = [
        'late' => 'latepenalty',
        'feedbackcontact' => 'feedbacklate',
        'nosubmission' => 'nosubmission',
        'brief' => 'brief',
        'setup' => 'setup',
        'unallocated' => 'unallocated',
        'plagiarism' => 'plagiarism',
        'loaderror' => 'loaderror',
    ];

    /**
     * A hint's icon. The missing brief is a half-dashed file where Moodle's Font Awesome has one (6.6 and later,
     * so Moodle 5.0 and later): 4.5's 6.5.2 would draw a blank square (Pierre, 2026-10-17).
     *
     * @param string $key
     * @return string
     */
    public static function hint_icon(string $key): string {
        global $CFG;
        if ($key === 'brief' && (int)$CFG->branch >= 500) {
            return 'fa-file-half-dashed';
        }
        return self::HINT_ICONS[$key];
    }

    /** Each hint's icon (the Flag icons mockup). */
    const HINT_ICONS = [
        'brief' => 'fa-file-circle-exclamation',
        'genericname' => 'fa-signature',
        'hidden' => 'fa-eye-slash',
        'unallocated' => 'fa-user-slash',
        'returned' => 'fa-file-circle-plus',
        'feedbackcontact' => 'fa-circle-question',
        'extensions' => 'fa-calendar-plus',
        'setup' => 'fa-screwdriver-wrench',
        'loaderror' => 'fa-triangle-exclamation',
        'late' => 'fa-clock',
        'plagiarism' => 'fa-flag',
        'nosubmission' => 'fa-person-chalkboard',
    ];

    /** Each hint's tone. Attention is for staff and convenors only, never on a student's row. */
    const HINT_TONES = [
        'brief' => 'info',
        'genericname' => 'info',
        'hidden' => 'grey',
        'unallocated' => 'attention',
        'returned' => 'info',
        'feedbackcontact' => 'info',
        'extensions' => 'info',
        'setup' => 'attention',
        'loaderror' => 'attention',
        'late' => 'grey',
        'nosubmission' => 'grey',
        'plagiarism' => 'attention',
    ];

    /**
     * Where a finished assessment stands, for staff Past rows (Pierre, 2026-10-07): one status over the whole
     * assessment, every marker's share included. Still being marked first, then release.
     *
     * @param int $units Work ready to mark.
     * @param int $released Of which released to students.
     * @param int $unmarked Of which still unmarked at some stage.
     * @param bool $norelease Nothing is ever released (a quiz whose review never shows marks, F10).
     * @return array|null key and text, or null with no work
     */
    public static function grading(int $units, int $released, int $unmarked, bool $norelease): ?array {
        if ($units <= 0) {
            return null;
        }
        if ($unmarked > 0) {
            $key = 'grading';
        } else if ($norelease) {
            $key = 'done';
        } else if ($released <= 0) {
            $key = 'unreleased';
        } else if ($released < $units) {
            $key = 'withheld';
        } else {
            $key = 'released';
        }
        return ['key' => $key, 'text' => get_string('grading_' . $key, 'block_le_dashboard', $units - $released)];
    }

    /**
     * Moves a finished row's bars behind its Show marking button, first in the list under the row, above any
     * markers: at a glance the row shows its grading status only (Pierre, 2026-10-07).
     *
     * @param array $row
     * @param string $label The name beside the row's own bars.
     * @return array
     */
    public static function marking_detail(array $row, string $label): array {
        $markers = $row['markers'] ?? [];
        if (!empty($row['bars'])) {
            array_unshift($markers, ['name' => $label, 'bars' => $row['bars'], 'all' => true]);
        }
        $row['bars'] = [];
        $row['markers'] = $markers;
        $row['hasmarkers'] = !empty($markers);
        $row['markersid'] = $row['id'] . '-markers';
        $row['marking'] = true;
        return $row;
    }

    /** The student hints, shown as bare glyphs. */
    const GLYPH_HINTS = ['feedbackcontact', 'late'];

    /**
     * The hint on an assessment where students hand nothing in online: a bare glyph on a student's
     * row, an outlined flag on staff and convenor rows.
     *
     * @param string $rowid
     * @param bool $staff
     * @return array
     */
    public static function nosubmission_hint(string $rowid, bool $staff): array {
        $hint = self::hint(
            $rowid,
            'nosubmission',
            $staff ? get_string('hint_nosubmission_staff', 'block_le_dashboard') : ''
        );
        $hint['glyph'] = !$staff;
        // The guidance is for students.
        $hint['guidance'] = $staff ? null : $hint['guidance'];
        return $hint;
    }

    /** The settings a tagged assessment can be missing, in the order the flag lists them. */
    const SETUP_PROBLEMS = ['nodue', 'nograde', 'nomarks', 'bothtags'];

    /**
     * Staff flag on a tagged assessment missing a setting (Pierre, 2026-10-07): each problem on its
     * own, then a link to the activity's settings.
     *
     * @param string $rowid
     * @param int $cmid
     * @param string $setup The index's comma-separated problems.
     * @return array|null Null when nothing is missing.
     */
    public static function setup_hint(string $rowid, int $cmid, string $setup): ?array {
        $problems = array_intersect(self::SETUP_PROBLEMS, explode(',', $setup));
        if (!$problems) {
            return null;
        }
        $link = \html_writer::link(
            new \moodle_url('/course/modedit.php', ['update' => $cmid]),
            get_string('setup_settings', 'block_le_dashboard')
        );
        return self::hint(
            $rowid,
            'setup',
            implode(' ', array_map(fn($p) => get_string('setup_' . $p . '_text', 'block_le_dashboard'), $problems)),
            get_string('setup_link', 'block_le_dashboard', $link),
            get_string('setup', 'block_le_dashboard')
        );
    }

    /**
     * Staff rows of an assessment whose last index couldn't read all student data: a flag, and with no
     * student data at all, a note in place of the bars that would read 0 of 0 (Pierre, 2026-10-08).
     *
     * @param array $row
     * @param \stdClass $cm Index row, with loaderror.
     * @param bool $nostudents Whether no student's data was read.
     * @return array
     */
    public static function load_error(array $row, \stdClass $cm, bool $nostudents): array {
        if (empty($cm->loaderror)) {
            return $row;
        }
        $row['hints'][] = self::hint($row['id'], 'loaderror', get_string('hint_loaderror_text', 'block_le_dashboard'));
        if ($nostudents) {
            $row['bars'] = array_values(array_filter($row['bars'] ?? [], fn($bar) => (int)$bar['max'] > 0));
            $row['alert'] = get_string('hint_loaderror', 'block_le_dashboard');
        }
        return $row;
    }

    /**
     * The hint on a row with submissions flagged for an academic conduct (plagiarism) check:
     * staff and convenors only, never students.
     *
     * @param string $rowid
     * @param int $n Flagged submissions.
     * @return array
     */
    public static function plagiarism_hint(string $rowid, int $n): array {
        return self::hint(
            $rowid,
            'plagiarism',
            $n === 1 ? get_string('hint_plagiarism_text_one', 'block_le_dashboard')
                : get_string('hint_plagiarism_text_many', 'block_le_dashboard', $n),
            null,
            get_string('hint_plagiarism', 'block_le_dashboard', $n),
            $n
        );
    }

    /**
     * Counts per assessment type, ready for a summary box.
     *
     * @return array summative, formative and both, all zero
     */
    public static function zero_counts(): array {
        return ['both' => 0, 'summative' => 0, 'formative' => 0];
    }

    /**
     * Adds one to a counts array for the given assessment type.
     *
     * @param array $counts
     * @param string $assesstype
     * @param int $by
     */
    public static function count(array &$counts, string $assesstype, int $by = 1): void {
        $counts['both'] += $by;
        $counts[$assesstype] += $by;
    }

    /**
     * The activity icon's text: the activity type, then each part as its own sentence.
     *
     * @param string $modlabel
     * @param string[] $parts Sentences, each with its own full stop.
     * @return string
     */
    public static function tip(string $modlabel, array $parts): string {
        return implode(' ', array_merge([get_string('tipsentence', 'block_le_dashboard', $modlabel)], $parts));
    }

    /**
     * Shared fields of a row, from an index cm record.
     *
     * @param \stdClass $cm Index record (cmid, modname, name, assesstype, courseid, hiddenfromstudents).
     * @param string $courseshortname
     * @param string $view
     * @return array
     */
    public static function row(\stdClass $cm, string $courseshortname, string $view): array {
        // Not escaped here: the template escapes once (PR3).
        $options = ['context' => \context_course::instance($cm->courseid), 'escape' => false];
        $name = format_string($cm->name, true, $options);
        $modname = get_string('modulename', $cm->modname);
        $id = 'ma-' . $view . '-' . $cm->cmid;
        return [
            'id' => $id,
            'cmid' => (int)$cm->cmid,
            'name' => $name,
            'url' => (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->cmid]))->out(false),
            'course' => format_string($courseshortname, true, $options),
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $cm->courseid]))->out(false),
            'modname' => $cm->modname,
            'modlabel' => $modname,
            'faicon' => self::ICONS[$cm->modname] ?? self::ICON_FALLBACK,
            'assesstype' => $cm->assesstype,
            'typelabel' => get_string($cm->assesstype, 'block_le_dashboard'),
            'typeline' => get_string('rowtype', 'block_le_dashboard', [
                'mod' => $modname,
                'type' => get_string($cm->assesstype, 'block_le_dashboard'),
            ]),
            // The first flag on staff rows; students never see hidden work.
            'hiddenhint' => empty($cm->hiddenfromstudents) ? null
                : self::hint($id, 'hidden', get_string('hint_hidden_text', 'block_le_dashboard')),
            'tip' => self::tip($modname, !empty($cm->hiddenfromstudents) ? [get_string('tip_hidden', 'block_le_dashboard')] : []),
            'search' => \core_text::strtolower(implode(' ', [$name, $courseshortname, $modname])),
        ];
    }

    /**
     * The View action every row has.
     *
     * @param string $url
     * @return array
     */
    public static function view_action(string $url): array {
        return self::action('view', $url);
    }

    /**
     * A row action button.
     *
     * @param string $key submit or view
     * @param string $url
     * @return array
     */
    public static function action(string $key, string $url): array {
        return [
            'key' => $key,
            'label' => get_string('action_' . $key, 'block_le_dashboard'),
            'icon' => self::ACTION_ICONS[$key],
            'url' => $url,
        ];
    }

    /**
     * Course short names for a list of course ids.
     *
     * @param int[] $courseids
     * @return string[] courseid => shortname
     */
    public static function course_names(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_unique($courseids), SQL_PARAMS_NAMED);
        return $DB->get_records_select_menu('course', "id $insql", $params, '', 'id, shortname');
    }

    /**
     * Classes for a small select: Bootstrap 4's on Moodle 4.x, Bootstrap 5's from 5.0, so 5.x
     * never relies on its deprecated Bootstrap 4 compatibility styles (PR9).
     *
     * @return string
     */
    public static function select_class(): string {
        global $CFG;
        return (int)$CFG->branch >= 500 ? 'form-select form-select-sm' : 'custom-select custom-select-sm';
    }
}
