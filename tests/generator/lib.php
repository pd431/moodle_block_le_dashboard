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

/**
 * Data generator for block_my_assessments: configures the block and tags assessments.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_my_assessments_generator extends component_generator_base {
    /**
     * Sets the tags, roles and enabled types the tests use, and creates the module convenor role.
     *
     * @param array $types Enabled activity types.
     * @return int The module convenor role id.
     */
    public function configure(array $types = ['assign', 'quiz', 'peerwork']): int {
        global $DB;
        set_config('certaintags', 'summative', 'block_my_assessments');
        set_config('formativetags', 'formative', 'block_my_assessments');
        set_config('enabledtypes', implode(',', $types), 'block_my_assessments');
        set_config('studentrole', 'student', 'block_my_assessments');
        set_config('staffrole', 'editingteacher,teacher', 'block_my_assessments');
        if (!$roleid = $DB->get_field('role', 'id', ['shortname' => 'moduleconvenor'])) {
            $roleid = create_role('Module convenor', 'moduleconvenor', '', 'editingteacher');
            set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        }
        set_config('moduleconvenorrole', 'moduleconvenor', 'block_my_assessments');
        return (int)$roleid;
    }

    /**
     * Tags an activity as summative or formative, which indexes it.
     *
     * @param int $cmid
     * @param string $type summative or formative
     */
    public function tag(int $cmid, string $type = 'summative'): void {
        core_tag_tag::set_item_tags('core', 'course_modules', $cmid, context_module::instance($cmid), [$type]);
        (new \block_my_assessments\local\index\indexer())->index_cm($cmid);
    }

    /**
     * Allocates a student's assignment to a marker, in assign_user_flags before Moodle 5.0
     * and in assign_allocated_marker from 5.0.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $markerid 0 removes the allocation.
     * @param string|null $workflowstate Also sets the marking workflow state.
     */
    public function allocate_assign(int $cmid, int $userid, int $markerid, ?string $workflowstate = null): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assign = new assign(context_module::instance($cmid), null, null);
        $flags = $assign->get_user_flags($userid, true);
        if ($workflowstate !== null) {
            $flags->workflowstate = $workflowstate;
        }
        if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
            $params = ['assignment' => $assign->get_instance()->id, 'student' => $userid];
            $DB->delete_records('assign_allocated_marker', $params);
            if ($markerid) {
                $DB->insert_record('assign_allocated_marker', $params + ['marker' => $markerid]);
            }
        } else {
            $flags->allocatedmarker = $markerid;
        }
        $assign->update_user_flags($flags);
    }
}
