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
use block_my_assessments\local\output\format;
use block_my_assessments\local\tags;
use block_my_assessments\local\years;

/**
 * What the three views share: sections, the freshness line, the year picker and the filters.
 *
 * Every view's context has the same shape, so one set of templates renders them all.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /** @var int The viewer. */
    protected int $userid;
    /** @var int Time the view is built for. */
    protected int $now;
    /** @var int Academic year id, 0 for all. */
    protected int $yearid;

    /**
     * Constructor.
     *
     * @param int $userid
     * @param int|null $now
     * @param int|null $yearid Academic year (staff and convenor views); null for the default.
     */
    public function __construct(int $userid, ?int $now = null, ?int $yearid = null) {
        $this->userid = $userid;
        $this->now = $now ?? time();
        if ($yearid === null) {
            $saved = get_user_preferences('block_my_assessments_year', null, $userid);
            $yearid = ($saved !== null && ((int)$saved === 0 || years::get((int)$saved))) ? (int)$saved
                : years::default_id($this->now);
        }
        $this->yearid = $yearid;
    }

    /**
     * The view's key: student, staff or convenor.
     *
     * @return string
     */
    abstract public function key(): string;

    /**
     * The assessments this view covers, for Refresh.
     *
     * @return int[] cmids
     */
    abstract public function scope_cmids(): array;

    /**
     * The courses this view covers, for Refresh.
     *
     * @return int[]
     */
    abstract protected function scope_courses(): array;

    /**
     * What Refresh re-indexes: the indexed assessments of this view, plus every tagged one in its
     * courses, so assessments the index missed (a dropped event, tags set later) are found (PR4).
     *
     * @return int[] cmids
     */
    public function refresh_cmids(): array {
        $tagged = array_keys(tags::types($this->scope_courses()));
        return array_values(array_unique(array_merge($this->scope_cmids(), $tagged)));
    }

    /**
     * Builds the template context.
     *
     * @return array
     */
    abstract public function export(): array;

    /**
     * A section context. Rows past the first few are marked to start hidden behind Show more.
     *
     * @param string $key Section purpose, used for the CSS modifier and data-section.
     * @param array $rows
     * @return array
     */
    protected function section(string $key, array $rows): array {
        $visible = config::visible_rows();
        foreach (array_values($rows) as $i => $row) {
            $rows[$i]['overflow'] = $i >= $visible;
        }
        return [
            'key' => $key,
            'view' => $this->key(),
            'title' => get_string('section_' . $this->key() . '_' . $key, 'block_my_assessments'),
            'count' => count($rows),
            'rows' => array_values($rows),
            'hasrows' => !empty($rows),
            'hasmore' => count($rows) > $visible,
            'visiblerows' => $visible,
        ];
    }

    /**
     * SQL joining the assessment index (alias c) to activities not being deleted: deleting from
     * the course page only flags the activity until core's task removes it (EC7).
     */
    const LIVE = "JOIN {course_modules} lcm ON lcm.id = c.cmid AND lcm.deletioninprogress = 0";

    /** Seconds after which a queued Refresh no longer shows "Refreshing". */
    const REFRESHING_EXPIRES = 600;

    /**
     * The "Updated N min ago · Refresh" line.
     *
     * @param int|null $oldest The oldest timeindexed among the view's assessments.
     * @return array
     */
    protected function freshness(?int $oldest): array {
        // A queued Refresh that never finished stops showing after a while, so the button comes back (PR7).
        $since = (int)get_user_preferences('block_my_assessments_refreshing', 0, $this->userid);
        $refreshing = $since > $this->now - self::REFRESHING_EXPIRES;
        return [
            'text' => format::updated($oldest, $this->now),
            'refreshing' => $refreshing,
            // Only while this view has nothing indexed yet, or the index has never been filled.
            'preparing' => get_config('block_my_assessments', 'backfillpending')
                && ($oldest === null || !get_config('block_my_assessments', 'backfilldone')),
        ];
    }

    /**
     * The academic year picker.
     *
     * @return array
     */
    protected function years(): array {
        $options = [['id' => 0, 'label' => get_string('allyears', 'block_my_assessments'), 'selected' => $this->yearid === 0]];
        foreach (years::all() as $year) {
            $options[] = ['id' => (int)$year->id, 'label' => format_string($year->label, true, ['escape' => false]),
                'selected' => (int)$year->id === $this->yearid];
        }
        return $options;
    }

    /**
     * A course name with its academic year, for a row shown outside the selected year (EC3).
     *
     * @param string $course
     * @param int $courseid
     * @return string
     */
    protected function with_year(string $course, int $courseid): string {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'startdate, enddate');
        // The year it starts in, or ends in when it has no start date (EC3b).
        $date = (int)($course->startdate ?: $course->enddate);
        foreach (years::all() as $year) {
            if ($date && $date >= $year->timestart && $date < $year->timeend) {
                return get_string(
                    'courseinyear',
                    'block_my_assessments',
                    ['course' => $course, 'year' => format_string($year->label, true, ['escape' => false])]
                );
            }
        }
        return $course;
    }

    /**
     * The selected academic year record, or null for all years.
     *
     * @return \stdClass|null
     */
    protected function year(): ?\stdClass {
        return years::get($this->yearid);
    }

    /**
     * A summary box.
     *
     * @param string $key
     * @param array $counts both, summative, formative; or option => those, with $options
     * @param array|null $options [value => label] for a dropdown that switches the counts
     * @param string|null $selected Selected option
     * @param string|null $note Small text under the value
     * @return array
     */
    protected function box(
        string $key,
        array $counts,
        ?array $options = null,
        ?string $selected = null,
        ?string $note = null
    ): array {
        $box = [
            'key' => $key,
            'view' => $this->key(),
            'label' => get_string('box_' . $this->key() . '_' . $key, 'block_my_assessments'),
            'note' => $note,
            'hasoptions' => $options !== null,
            'options' => [],
        ];
        if ($options !== null) {
            foreach ($options as $value => $label) {
                $box['options'][] = ['value' => $value, 'label' => $label, 'selected' => (string)$value === (string)$selected];
            }
            $box['value'] = $counts[$selected]['both'] ?? 0;
        } else {
            $box['value'] = $counts['both'];
        }
        $box['counts'] = json_encode($counts);
        return $box;
    }

    /**
     * Wraps the parts into the view context every template expects.
     *
     * @param array $summary
     * @param array $sections
     * @param int|null $oldest
     * @param array $extra
     * @return array
     */
    protected function context(array $summary, array $sections, ?int $oldest, array $extra = []): array {
        $hasrows = false;
        foreach ($sections as $s) {
            $hasrows = $hasrows || $s['hasrows'];
        }
        return $extra + [
            'key' => $this->key(),
            'uniqid' => \html_writer::random_id('ma'),
            'selectclass' => format::select_class(),
            'summary' => $summary,
            'hassummary' => !empty($summary),
            'sections' => $sections,
            'hasrows' => $hasrows,
            'empty' => get_string('empty_' . $this->key(), 'block_my_assessments'),
            'searchplaceholder' => get_string('searchplaceholder_' . $this->key(), 'block_my_assessments'),
            'freshness' => $this->freshness($oldest),
            'hasyears' => false,
            'years' => [],
        ];
    }
}
