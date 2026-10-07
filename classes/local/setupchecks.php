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
use block_le_dashboard\local\output\format;

/**
 * Tagged activities whose settings need checking, for the module convenor's second quiet line
 * (Pierre, 2026-10-07): indexed ones the indexer found a problem with, and tagged ones of a type
 * that isn't enabled, which no dashboard shows. The second list reads Moodle's tables, so it is
 * cached with the untagged list and forgotten with it on Refresh.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setupchecks {
    /**
     * The convenor's line, or null when there is nothing to check.
     *
     * @param int $userid
     * @param \stdClass|null $year The selected year, null for all.
     * @param int[] $courseids The courses the user convenes in that year.
     * @return array|null label, courses (label, activities: name, url, hidden, problems)
     */
    public static function hint(int $userid, ?\stdClass $year, array $courseids): ?array {
        global $DB;
        if (!$courseids) {
            return null;
        }
        $problems = [];
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $rs = $DB->get_recordset_select(
            'block_le_dashboard_cm',
            "courseid $insql AND setup IS NOT NULL",
            $params,
            '',
            'cmid, courseid, setup'
        );
        foreach ($rs as $r) {
            $problems[(int)$r->courseid][(int)$r->cmid] = array_map(
                fn($p) => get_string('setup_' . $p, 'block_le_dashboard'),
                array_values(array_intersect(format::SETUP_PROBLEMS, explode(',', $r->setup)))
            );
        }
        $rs->close();
        foreach (self::disabled($userid, $year ? (int)$year->id : 0, $courseids) as $courseid => $cms) {
            foreach ($cms as $cmid => $modname) {
                $problems[$courseid][$cmid] = [get_string(
                    'setup_disabled',
                    'block_le_dashboard',
                    get_string('modulename', $modname)
                )];
            }
        }
        $courses = [];
        $total = 0;
        foreach (array_intersect($courseids, array_keys($problems)) as $courseid) {
            $modinfo = get_fast_modinfo($courseid);
            $activities = [];
            foreach ($problems[$courseid] as $cmid => $list) {
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm || !$cm->url || !$list) {
                    continue;
                }
                $section = $cm->get_section_info();
                $activities[] = [
                    'name' => format_string($cm->name, true, ['context' => $cm->context, 'escape' => false]),
                    'url' => $cm->url->out(false),
                    'hidden' => !$cm->visible || ($section && !$section->visible),
                    'problems' => implode('; ', $list),
                ];
            }
            if (!$activities) {
                continue;
            }
            $total += count($activities);
            $context = \context_course::instance($courseid);
            $courses[] = [
                'label' => get_string('untagged_course', 'block_le_dashboard', [
                    'course' => format_string($modinfo->get_course()->fullname, true, ['context' => $context, 'escape' => false]),
                    'count' => count($activities),
                ]),
                'activities' => $activities,
            ];
        }
        if (!$total) {
            return null;
        }
        return [
            'label' => $year
                ? get_string('setupchecks_year', 'block_le_dashboard', ['count' => $total,
                    'year' => format_string($year->label, true, ['escape' => false])])
                : get_string('setupchecks_all', 'block_le_dashboard', $total),
            'courses' => $courses,
        ];
    }

    /**
     * Tagged activities of types not enabled in Activity types, by course, cached with the untagged list.
     *
     * @param int $userid
     * @param int $yearid
     * @param int[] $courseids
     * @return array courseid => [cmid => modname]
     */
    public static function disabled(int $userid, int $yearid, array $courseids): array {
        $cache = \cache::make('block_le_dashboard', 'untagged');
        $key = 'd' . $userid . '_' . $yearid;
        $hit = $cache->get($key);
        if ($hit !== false && $hit['courses'] === $courseids) {
            return $hit['cms'];
        }
        $cms = self::lookup($courseids);
        $cache->set($key, ['courses' => $courseids, 'cms' => $cms]);
        return $cms;
    }

    /**
     * Reads tagged activities of types that aren't enabled: the activities of other types in the
     * courses, then which carry a summative or formative tag (two indexed queries, as untagged::lookup).
     *
     * @param int[] $courseids
     * @return array courseid => [cmid => modname]
     */
    protected static function lookup(array $courseids): array {
        global $DB;
        $names = array_merge(config::summative_tags(), config::formative_tags());
        if (!$names) {
            return [];
        }
        [$namesql, $nameparams] = $DB->get_in_or_equal($names);
        $tagids = $DB->get_fieldset_select('tag', 'id', "name $namesql", $nameparams);
        if (!$tagids) {
            return [];
        }
        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $where = "cm.course $coursesql AND cm.deletioninprogress = 0";
        $types = registry::enabled_types();
        if ($types) {
            [$typesql, $typeparams] = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'm', false);
            $where .= " AND m.name $typesql";
            $params += $typeparams;
        }
        $cms = $DB->get_records_sql("SELECT cm.id, cm.course, m.name
                                       FROM {course_modules} cm
                                       JOIN {modules} m ON m.id = cm.module
                                      WHERE $where", $params);
        $out = [];
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
                $cm = $cms[$cmid];
                $out[(int)$cm->course][(int)$cmid] = $cm->name;
            }
        }
        return $out;
    }
}
