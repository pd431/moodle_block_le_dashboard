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

use block_le_dashboard\fixture\fixture;
use block_le_dashboard\fixture\registry;

/**
 * Data generator for block_le_dashboard: configures the block, tags assessments, and hands out the fixture that
 * writes each activity type.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_le_dashboard_generator extends component_generator_base {
    /** @var string[] The fixture files, in dependency order. */
    const FIXTURES = ['fixture', 'unavailable', 'phrases', 'base', 'generic', 'assign', 'quiz', 'coursework', 'peerwork',
        'registry'];

    /**
     * Loads the fixtures (the write-side twin of the adapters), which live beside the generator, outside the
     * autoloaded classes.
     *
     * @param testing_data_generator $datagenerator
     */
    public function __construct(testing_data_generator $datagenerator) {
        parent::__construct($datagenerator);
        foreach (self::FIXTURES as $file) {
            require_once(__DIR__ . '/fixture/' . $file . '.php');
        }
    }

    /**
     * The fixture that writes an activity type, or an activity.
     *
     * @param string|cm_info $type A module name, or the activity itself.
     * @return fixture
     */
    public function fixture($type): fixture {
        return $type instanceof cm_info ? registry::for_cm($type) : registry::for($type);
    }

    /**
     * Every table and column holding a time in any installed type, for tests that move time.
     *
     * @return array table => [columns]
     */
    public function time_fields(): array {
        return registry::time_fields();
    }

    /**
     * Sets the tags, roles and enabled types the tests use, and creates the module convenor role.
     *
     * @param array $types Enabled activity types.
     * @return int The module convenor role id.
     */
    public function configure(array $types = ['assign', 'quiz', 'peerwork']): int {
        global $DB;
        set_config('certaintags', 'summative', 'block_le_dashboard');
        set_config('formativetags', 'formative', 'block_le_dashboard');
        set_config('enabledtypes', implode(',', $types), 'block_le_dashboard');
        set_config('studentrole', 'student', 'block_le_dashboard');
        set_config('staffrole', 'editingteacher,teacher', 'block_le_dashboard');
        if (!$roleid = $DB->get_field('role', 'id', ['shortname' => 'moduleconvenor'])) {
            $roleid = create_role('Module convenor', 'moduleconvenor', '', 'editingteacher');
            set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        }
        set_config('moduleconvenorrole', 'moduleconvenor', 'block_le_dashboard');
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
        (new \block_le_dashboard\local\index\indexer())->index_cm($cmid);
    }

    /**
     * Allocates a student's assignment to a marker (0 removes the allocation), optionally setting the workflow state.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $markerid
     * @param string|null $workflowstate
     */
    public function allocate_assign(int $cmid, int $userid, int $markerid, ?string $workflowstate = null): void {
        \block_le_dashboard\fixture\assign::allocate_marker($cmid, $userid, $markerid ? [$markerid] : [], $workflowstate);
    }
}
