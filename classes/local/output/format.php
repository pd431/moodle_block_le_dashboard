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

namespace block_my_assessments\local\output;

/**
 * Small formatting helpers shared by the three views.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
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
    ];

    /**
     * A date and time, as rows show it.
     *
     * @param int $time
     * @return string
     */
    public static function datetime(int $time): string {
        return userdate($time, get_string('strftimedue', 'block_my_assessments'));
    }

    /**
     * A labelled date for a row.
     *
     * @param string $key Date key; the label is the date_{key} string, if there is one.
     * @param int $time
     * @param string|null $note Optional note after the date.
     * @return array
     */
    public static function date(string $key, int $time, ?string $note = null): array {
        $sm = get_string_manager();
        return [
            'key' => $key,
            'label' => $sm->string_exists('date_' . $key, 'block_my_assessments')
                ? get_string('date_' . $key, 'block_my_assessments') : null,
            'text' => self::datetime($time),
            'iso' => date('c', $time),
            'note' => $note,
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
            return get_string('duration_lessminute', 'block_my_assessments');
        }
        if ($seconds < HOURSECS) {
            return get_string('duration_minutes', 'block_my_assessments', (int)floor($seconds / MINSECS));
        }
        if ($seconds < DAYSECS) {
            return get_string('duration_hours', 'block_my_assessments', (int)floor($seconds / HOURSECS));
        }
        $days = (int)floor($seconds / DAYSECS);
        return $days === 1 ? get_string('duration_day', 'block_my_assessments')
            : get_string('duration_days', 'block_my_assessments', $days);
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
            return get_string('updatednever', 'block_my_assessments');
        }
        $diff = max(0, $now - $time);
        if ($diff < MINSECS) {
            return get_string('updatedjustnow', 'block_my_assessments');
        }
        if ($diff < HOURSECS) {
            return get_string('updatedminutes', 'block_my_assessments', (int)floor($diff / MINSECS));
        }
        if ($diff < DAYSECS) {
            return get_string('updatedhours', 'block_my_assessments', (int)floor($diff / HOURSECS));
        }
        return get_string('updatedon', 'block_my_assessments', self::datetime($time));
    }

    /**
     * Label of a marking stage key.
     *
     * @param string $stage
     * @return string
     */
    public static function stage(string $stage): string {
        if (preg_match('/^marker(\d+)$/', $stage, $m)) {
            return get_string('stage_markern', 'block_my_assessments', $m[1]);
        }
        $sm = get_string_manager();
        return $sm->string_exists('stage_' . $stage, 'block_my_assessments')
            ? get_string('stage_' . $stage, 'block_my_assessments') : $stage;
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
            'text' => get_string($groups ? 'xofygroups' : 'xofy', 'block_my_assessments', ['x' => $value, 'y' => $max]),
        ];
    }

    /**
     * A hint on a row: a small button that opens a tip by click or focus, never hover.
     * One component for every hint (no brief, unallocated work, returned work).
     *
     * @param string $rowid
     * @param string $key brief, unallocated, returned, feedbackcontact, extensions or nodeadline.
     * @param string $text The tip.
     * @param string|null $link HTML link sentence after the tip, if any.
     * @param string|null $label The button's text, when it isn't the hint_{key} string (a count, say).
     * @return array
     */
    public static function hint(string $rowid, string $key, string $text, ?string $link = null, ?string $label = null): array {
        return [
            'id' => $rowid . '-hint-' . $key,
            'key' => $key,
            'label' => $label ?? get_string('hint_' . $key, 'block_my_assessments'),
            'text' => $text,
            'link' => $link,
        ];
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
        return implode(' ', array_merge([get_string('tipsentence', 'block_my_assessments', $modlabel)], $parts));
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
        return [
            'id' => 'ma-' . $view . '-' . $cm->cmid,
            'cmid' => (int)$cm->cmid,
            'name' => $name,
            'url' => (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->cmid]))->out(false),
            'course' => format_string($courseshortname, true, $options),
            'modname' => $cm->modname,
            'modlabel' => $modname,
            'faicon' => self::ICONS[$cm->modname] ?? self::ICON_FALLBACK,
            'assesstype' => $cm->assesstype,
            'typelabel' => get_string($cm->assesstype, 'block_my_assessments'),
            'typeline' => get_string('rowtype', 'block_my_assessments', [
                'mod' => $modname,
                'type' => get_string($cm->assesstype, 'block_my_assessments'),
            ]),
            'hidden' => !empty($cm->hiddenfromstudents),
            'tip' => self::tip($modname, !empty($cm->hiddenfromstudents) ? [get_string('tip_hidden', 'block_my_assessments')] : []),
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
            'label' => get_string('action_' . $key, 'block_my_assessments'),
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
