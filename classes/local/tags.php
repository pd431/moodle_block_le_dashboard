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

namespace block_my_assessments\local;

/**
 * Summative and formative tags on course modules.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tags {
    /** Summative assessment. */
    const SUMMATIVE = 'summative';
    /** Formative assessment. */
    const FORMATIVE = 'formative';

    /**
     * Assessment type of tagged course modules (summative wins when both tags are present).
     *
     * @param int|null $courseid Limit to one course.
     * @param int[]|null $cmids Limit to these course modules.
     * @return string[] cmid => summative or formative
     */
    public static function types(?int $courseid = null, ?array $cmids = null): array {
        global $DB;
        $summative = config::summative_tags();
        $formative = config::formative_tags();
        $all = array_merge($summative, $formative);
        if (!$all || ($cmids !== null && !$cmids)) {
            return [];
        }
        [$tagsql, $params] = $DB->get_in_or_equal($all, SQL_PARAMS_NAMED, 't');
        $where = '';
        if ($courseid) {
            $where .= ' AND cm.course = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($cmids !== null) {
            [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
            $where .= " AND cm.id $cmsql";
            $params += $cmparams;
        }
        $rs = $DB->get_recordset_sql("SELECT ti.id, ti.itemid AS cmid, t.name
                                        FROM {tag_instance} ti
                                        JOIN {tag} t ON t.id = ti.tagid
                                        JOIN {course_modules} cm ON cm.id = ti.itemid
                                       WHERE ti.component = 'core' AND ti.itemtype = 'course_modules'
                                         AND t.name $tagsql $where", $params);
        $types = [];
        foreach ($rs as $r) {
            $cmid = (int)$r->cmid;
            if (in_array($r->name, $summative)) {
                $types[$cmid] = self::SUMMATIVE;
            } else if (!isset($types[$cmid])) {
                $types[$cmid] = self::FORMATIVE;
            }
        }
        $rs->close();
        return $types;
    }
}
