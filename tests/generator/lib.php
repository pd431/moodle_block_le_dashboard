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
}
