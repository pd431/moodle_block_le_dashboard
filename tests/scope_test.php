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

namespace block_my_assessments;

use block_my_assessments\local\scope;

/**
 * View routing (rc6 style): staff and convenor roles give only the staff views.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\local\scope
 */
final class scope_test extends \advanced_testcase {
    /**
     * Role combinations and the views they give.
     *
     * @return array
     */
    public static function views_provider(): array {
        return [
            'student only' => [['student', 'student'], [scope::STUDENT]],
            'teaches and studies' => [['editingteacher', 'student'], [scope::STAFF]],
            'convenes and studies' => [['convenor', 'student'], [scope::CONVENOR]],
            'teaches and convenes' => [['editingteacher', 'convenor'], [scope::STAFF, scope::CONVENOR]],
            'no recognised role' => [['guest', 'guest'], [scope::STUDENT]],
        ];
    }

    /**
     * The views offered depend only on staff and convenor roles.
     *
     * @dataProvider views_provider
     * @param string[] $roles Role in each of two courses ('convenor' is the block's convenor role).
     * @param string[] $expected
     */
    public function test_views(array $roles, array $expected): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $convenorrole = $gen->get_plugin_generator('block_my_assessments')->configure(['assign']);
        $user = $gen->create_user();
        foreach ($roles as $role) {
            $roleid = $role === 'convenor' ? $convenorrole : (int)$DB->get_field('role', 'id', ['shortname' => $role]);
            $gen->enrol_user($user->id, $gen->create_course()->id, $roleid);
        }
        $this->assertSame($expected, scope::views($user->id));
    }

    /**
     * A staff role in a course whose enrolment has ended does not count.
     */
    public function test_ended_staff_enrolment(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $gen->get_plugin_generator('block_my_assessments')->configure(['assign']);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $gen->create_course()->id, 'student');
        $gen->enrol_user($user->id, $gen->create_course()->id, 'editingteacher', 'manual', 0, time() - DAYSECS);
        $this->assertSame([scope::STUDENT], scope::views($user->id));
    }

    /**
     * A saved choice of a view the user no longer has is ignored.
     */
    public function test_default_view_ignores_saved_student_view(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('block_my_assessments_view', scope::STUDENT, $user);
        $this->assertSame(scope::STAFF, scope::default_view($user->id, [scope::STAFF, scope::CONVENOR]));
    }
}
