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

namespace block_le_dashboard;

use block_le_dashboard\local\scope;

/**
 * View routing (rc6 style): staff and convenor roles give only the staff views.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\scope
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
        $convenorrole = $gen->get_plugin_generator('block_le_dashboard')->configure(['assign']);
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
        $gen->get_plugin_generator('block_le_dashboard')->configure(['assign']);
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
        set_user_preference('block_le_dashboard_view', scope::STUDENT, $user);
        $this->assertSame(scope::STAFF, scope::default_view($user->id, [scope::STAFF, scope::CONVENOR]));
    }

    /**
     * An assessment belongs to a year by its deadlines or its course (EC3): the SQL and PHP forms agree over
     * every row shape, and All years restricts nothing.
     */
    public function test_in_year_forms_agree(): void {
        global $DB;
        $this->resetAfterTest();
        $year = (object)['timestart' => 1788220800, 'timeend' => 1819756800];
        $times = ['none' => null, 'before' => $year->timestart - DAYSECS, 'start' => $year->timestart,
            'inside' => $year->timestart + 60 * DAYSECS, 'end' => $year->timeend, 'after' => $year->timeend + DAYSECS];
        $courses = [];
        foreach ($times as $start) {
            foreach ($times as $end) {
                if ($start !== null && $end !== null && $end < $start) {
                    continue;
                }
                // Written directly: the course form refuses an end date without a start date, old courses have them.
                $courseid = (int)$this->getDataGenerator()->create_course()->id;
                $DB->update_record('course', (object)['id' => $courseid, 'startdate' => $start ?? 0, 'enddate' => $end ?? 0]);
                $courses[] = $courseid;
            }
        }
        $n = 0;
        foreach ($courses as $courseid) {
            foreach ($times as $due) {
                foreach ($times as $latest) {
                    $DB->insert_record('block_le_dashboard_cm', (object)['cmid' => ++$n, 'courseid' => $courseid,
                        'modname' => 'assign', 'adapter' => 'assign', 'name' => "A$n", 'assesstype' => 'summative',
                        'due' => $due, 'latestdue' => $latest, 'stages' => 'marked', 'timeindexed' => 1]);
                }
            }
        }
        $yearcourses = array_map('intval', $DB->get_fieldset_sql(
            'SELECT c.id FROM {course} c WHERE ' . scope::year_sql('c'),
            scope::year_params($year)
        ));
        $this->assertNotEmpty($yearcourses);
        $this->assertNotCount(count($courses), $yearcourses);
        foreach ([$year, null] as $chosen) {
            [$sql, $params] = scope::in_year_sql('c', $chosen);
            $insql = array_flip(array_map('intval', $DB->get_fieldset_sql(
                "SELECT c.cmid FROM {block_le_dashboard_cm} c WHERE $sql",
                $params
            )));
            $shown = 0;
            foreach ($DB->get_records('block_le_dashboard_cm') as $cm) {
                $inphp = scope::in_year($cm, $yearcourses, $chosen);
                $this->assertSame($inphp, isset($insql[(int)$cm->cmid]), json_encode($cm));
                $shown += $inphp ? 1 : 0;
            }
            if ($chosen) {
                $this->assertGreaterThan(0, $shown);
                $this->assertLessThan($n, $shown);
            } else {
                $this->assertSame($n, $shown);
            }
        }
        // The fragment can appear twice in one query.
        [$one, $p1] = scope::in_year_sql('a', $year);
        [$two, $p2] = scope::in_year_sql('b', $year);
        $this->assertEmpty(array_intersect_key($p1, $p2));
    }
}
