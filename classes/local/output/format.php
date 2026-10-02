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
     * @param string $key Lang string key for the label (due, submittedon...).
     * @param int $time
     * @param string|null $note Optional note after the date.
     * @return array
     */
    public static function date(string $key, int $time, ?string $note = null): array {
        return [
            'key' => $key,
            'label' => get_string('date_' . $key, 'block_my_assessments'),
            'text' => self::datetime($time),
            'iso' => date('c', $time),
            'note' => $note,
        ];
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
     * @return array
     */
    public static function bar(string $key, string $label, int $value, int $max): array {
        $percent = $max > 0 ? (int)round(100 * min($value, $max) / $max) : 0;
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'max' => $max,
            'percent' => $percent,
            'text' => get_string('xofy', 'block_my_assessments', ['x' => $value, 'y' => $max]),
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
     * Shared fields of a row, from an index cm record.
     *
     * @param \stdClass $cm Index record (cmid, modname, name, assesstype, courseid, hiddenfromstudents).
     * @param string $courseshortname
     * @param string $view
     * @return array
     */
    public static function row(\stdClass $cm, string $courseshortname, string $view): array {
        global $OUTPUT;
        $name = format_string($cm->name, true, ['context' => \context_course::instance($cm->courseid)]);
        $modname = get_string('modulename', $cm->modname);
        return [
            'id' => 'ma-' . $view . '-' . $cm->cmid,
            'cmid' => (int)$cm->cmid,
            'name' => $name,
            'url' => (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->cmid]))->out(false),
            'course' => format_string($courseshortname),
            'modname' => $cm->modname,
            'modlabel' => $modname,
            'icon' => $OUTPUT->image_url('monologo', $cm->modname)->out(false),
            'assesstype' => $cm->assesstype,
            'typelabel' => get_string($cm->assesstype, 'block_my_assessments'),
            'hidden' => !empty($cm->hiddenfromstudents),
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
        return ['key' => 'view', 'label' => get_string('action_view', 'block_my_assessments'), 'url' => $url];
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
}
