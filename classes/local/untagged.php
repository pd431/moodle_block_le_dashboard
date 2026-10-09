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

namespace block_le_dashboard\local;

use block_le_dashboard\local\adapter\registry;

/**
 * Compatible activities nobody has tagged, for the module convenor's hint (Pierre, 2026-10-06).
 *
 * Compatible: an activity type ticked in Activity types, with a grade item, not tagged summative
 * or formative, and not being deleted. Untagged activities aren't in the index, so this reads
 * Moodle's own tables: one query over the convenor's courses, cached per user and year for a
 * few minutes (Refresh clears it).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class untagged {
    /**
     * Untagged compatible activities by course.
     *
     * @param int $userid
     * @param int $yearid The selected year, 0 for all.
     * @param int[] $courseids The courses the user convenes in that year.
     * @return array courseid => cmid[]
     */
    public static function cmids(int $userid, int $yearid, array $courseids): array {
        if (!$courseids) {
            return [];
        }
        $cache = \cache::make('block_le_dashboard', 'untagged');
        $key = $userid . '_' . $yearid;
        $hit = $cache->get($key);
        if ($hit !== false && $hit['courses'] === $courseids) {
            return $hit['cmids'];
        }
        $cmids = self::lookup($courseids);
        $cache->set($key, ['courses' => $courseids, 'cmids' => $cmids]);
        return $cmids;
    }

    /**
     * Forgets a user's cached lists (Refresh).
     *
     * @param int $userid
     */
    public static function forget(int $userid): void {
        $cache = \cache::make('block_le_dashboard', 'untagged');
        $keys = [];
        foreach (array_merge([0], array_keys(years::all())) as $yearid) {
            // This list and the setting checks' disabled-type list (setupchecks::disabled()).
            array_push($keys, $userid . '_' . $yearid, 'd' . $userid . '_' . $yearid);
        }
        $cache->delete_many($keys);
    }

    /**
     * Reads the untagged compatible activities.
     *
     * Two queries, each driven by its own index: the graded activities of the courses, then which of
     * them carry a summative or formative tag. As one query with NOT EXISTS, MariaDB read every tag
     * instance on the site (50 ms at 90,000 activities, whatever the course count).
     *
     * @param int[] $courseids
     * @return array courseid => cmid[]
     */
    protected static function lookup(array $courseids): array {
        global $DB;
        $types = registry::enabled_types();
        if (!$types) {
            return [];
        }
        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        [$gicoursesql, $giparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'g');
        [$typesql, $typeparams] = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'm');
        $params += $giparams + $typeparams;
        $cms = $DB->get_records_sql_menu("SELECT cm.id, cm.course
                                            FROM {course_modules} cm
                                            JOIN {modules} m ON m.id = cm.module
                                           WHERE cm.course $coursesql AND m.name $typesql AND cm.deletioninprogress = 0
                                                 AND EXISTS (SELECT 1
                                                               FROM {grade_items} gi
                                                              WHERE gi.courseid = cm.course AND gi.courseid $gicoursesql
                                                                    AND gi.itemtype = 'mod' AND gi.itemmodule = m.name
                                                                    AND gi.iteminstance = cm.instance)
                                        ORDER BY cm.course, cm.id", $params);
        $names = array_merge(config::summative_tags(), config::formative_tags());
        $tagids = [];
        if ($names) {
            [$namesql, $nameparams] = $DB->get_in_or_equal($names);
            $tagids = $DB->get_fieldset_select('tag', 'id', "name $namesql", $nameparams);
        }
        if ($tagids && $cms) {
            [$tagsql, $tagparams] = $DB->get_in_or_equal($tagids, SQL_PARAMS_NAMED, 't');
            foreach (array_chunk(array_keys($cms), 1000) as $chunk) {
                [$cmsql, $cmparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'i');
                $tagged = $DB->get_fieldset_select(
                    'tag_instance',
                    'DISTINCT itemid',
                    "component = 'core' AND itemtype = 'course_modules' AND itemid $cmsql AND tagid $tagsql",
                    $cmparams + $tagparams
                );
                foreach ($tagged as $cmid) {
                    unset($cms[$cmid]);
                }
            }
        }
        $out = [];
        foreach ($cms as $cmid => $courseid) {
            $out[(int)$courseid][] = (int)$cmid;
        }
        return $out;
    }

    /**
     * The hint's template context, or null when nothing is untagged.
     *
     * @param int $userid
     * @param \stdClass|null $year
     * @param int[] $courseids
     * @return array|null
     */
    public static function hint(int $userid, ?\stdClass $year, array $courseids): ?array {
        $bycourse = self::cmids($userid, $year ? (int)$year->id : 0, $courseids);
        $courses = [];
        $total = 0;
        foreach ($bycourse as $courseid => $cmids) {
            $modinfo = get_fast_modinfo($courseid);
            $activities = [];
            foreach ($cmids as $cmid) {
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm || !$cm->url) {
                    continue;
                }
                $section = $cm->get_section_info();
                $activities[] = [
                    'name' => format_string($cm->name, true, ['context' => $cm->context, 'escape' => false]),
                    'url' => $cm->url->out(false),
                    'hidden' => !$cm->visible || ($section && !$section->visible),
                ];
            }
            if (!$activities) {
                continue;
            }
            $total += count($activities);
            $context = \context_course::instance($courseid);
            $courses[] = [
                // The course name links to the course (Pierre, 2026-10-09); the rest of the heading opens the list.
                'course' => format_string($modinfo->get_course()->fullname, true, ['context' => $context, 'escape' => false]),
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'count' => count($activities),
                'activities' => $activities,
            ];
        }
        if (!$total) {
            return null;
        }
        $label = $year
            ? get_string('untagged_year', 'block_le_dashboard', ['count' => $total,
                'year' => format_string($year->label, true, ['escape' => false])])
            : get_string('untagged_all', 'block_le_dashboard', $total);
        return [
            'label' => $label,
            'guidance' => guidance::html('untagged'),
            'courses' => $courses,
        ];
    }
}
