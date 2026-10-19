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

use block_le_dashboard\local\index\indexer;
use block_le_dashboard\local\index\pass;

/**
 * Index passes that stop at a time limit and carry on where they stopped (Pierre, 2026-10-19).
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\index\pass
 * @covers     \block_le_dashboard\task\reconcile
 * @covers     \block_le_dashboard\task\backfill
 */
final class pass_test extends \advanced_testcase {
    /** @var int[] Course ids, in order. */
    protected $courses = [];

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $ma = $this->getDataGenerator()->get_plugin_generator('block_le_dashboard');
        $ma->configure(['assign']);
        for ($i = 0; $i < 3; $i++) {
            $course = $this->getDataGenerator()->create_course();
            $this->getDataGenerator()->create_and_enrol($course, 'student');
            $a = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
            $ma->tag($a->cmid);
            $this->courses[] = (int)$course->id;
        }
        $DB->delete_records('task_adhoc');
    }

    /**
     * Runs a pass and returns how it ended and the courses it indexed.
     *
     * @param string $kind
     * @param int $max
     * @param int $now
     * @return array [result, course ids]
     */
    protected function run_pass(string $kind, int $max = 0, int $now = 0): array {
        $done = [];
        $trace = function ($line) use (&$done) {
            if (preg_match('/^Indexing course (\d+)$/', $line, $m)) {
                $done[] = (int)$m[1];
            }
        };
        $result = pass::run($kind, $trace, 0, 0, $now, $max);
        return [$result, $done];
    }

    /**
     * A run stops at its limit after the course it is on, and the next carries on.
     */
    public function test_limit_and_resume(): void {
        [$c1, $c2, $c3] = $this->courses;
        $this->assertSame([pass::LIMIT, [$c1]], $this->run_pass(pass::NIGHTLY, 1));
        $state = pass::state(pass::NIGHTLY);
        $this->assertSame(1, $state['done']);
        $this->assertSame(3, $state['total']);
        $this->assertTrue($state['open']);
        $this->assertSame([pass::LIMIT, [$c2]], $this->run_pass(pass::NIGHTLY, 1));
        $this->assertSame([pass::DONE, [$c3]], $this->run_pass(pass::NIGHTLY, 1));
        $state = pass::state(pass::NIGHTLY);
        $this->assertFalse($state['open']);
        $this->assertGreaterThan(0, $state['finished']);
        // A finished nightly pass starts again from the first course at its next run.
        $this->assertSame([pass::DONE, [$c1, $c2, $c3]], $this->run_pass(pass::NIGHTLY));
    }

    /**
     * A run that dies carries on from the last course it saved.
     */
    public function test_died(): void {
        [$c1, $c2, $c3] = $this->courses;
        $this->run_pass(pass::NIGHTLY, 1);
        // The next run died while indexing the second course: nothing after the first was saved.
        $this->assertSame([pass::DONE, [$c2, $c3]], $this->run_pass(pass::NIGHTLY));
    }

    /**
     * A time limit that has passed stops the run before its next course, without an error.
     */
    public function test_deadline(): void {
        $this->assertSame(pass::LIMIT, pass::run(pass::NIGHTLY, null, time() - 1));
        $this->assertSame(0, pass::state(pass::NIGHTLY)['done']);
    }

    /**
     * A restart (Rebuild, a setting change) sends the fill back to the first course, and a complete fill
     * isn't done twice.
     */
    public function test_fill_restart(): void {
        [$c1, $c2, $c3] = $this->courses;
        task\backfill::queue();
        $this->run_pass(pass::FILL, 1);
        task\backfill::queue();
        $this->assertSame(0, pass::state(pass::FILL)['done']);
        $this->assertSame([pass::DONE, [$c1, $c2, $c3]], $this->run_pass(pass::FILL));
        $this->assertTrue(pass::complete(pass::FILL));
        $this->assertSame([pass::DONE, []], $this->run_pass(pass::FILL));
    }

    /**
     * The nightly check works from 02:00 to 06:00, carries on there, and waits for the next night once done.
     */
    public function test_night(): void {
        $zone = \core_date::get_server_timezone_object();
        $at = fn(string $when) => (new \DateTime($when, $zone))->getTimestamp();
        $this->assertFalse(pass::night($at('2026-10-20 14:00')));
        $this->assertFalse(pass::night($at('2026-10-20 06:00')));
        $this->assertTrue(pass::night($at('2026-10-20 02:00')));
        $this->run_pass(pass::NIGHTLY, 1, $at('2026-10-20 02:00'));
        $this->assertTrue(pass::night($at('2026-10-20 03:00')));
        $this->run_pass(pass::NIGHTLY, 0, $at('2026-10-20 03:00'));
        // Tonight's is complete.
        $this->assertFalse(pass::night($at('2026-10-20 04:00')));
        $this->assertTrue(pass::night($at('2026-10-21 02:00')));
    }

    /**
     * The hourly task carries on a first fill at any hour, and clears "Preparing" once it is complete.
     */
    public function test_task_fills_at_any_hour(): void {
        set_config('indexminutes', 0, 'block_le_dashboard');
        task\backfill::rebuild();
        $this->expectOutputRegex('/Indexing course/');
        $task = new task\reconcile();
        $at = (new \DateTime('2026-10-20 14:00', \core_date::get_server_timezone_object()))->getTimestamp();
        $this->assertSame(pass::DONE, $task->run($at));
        $this->assertSame(3, indexer::counts()['cm']);
        $this->assertEmpty(get_config('block_le_dashboard', 'backfillpending'));
        // Then outside the night hours there is nothing to do.
        $this->assertNull($task->run($at));
    }

    /**
     * A backfill that stops at its limit queues itself to carry on, and keeps "Preparing" until it is done.
     */
    public function test_backfill_carries_on(): void {
        global $DB;
        task\backfill::rebuild();
        set_config('behatindexcourses', 2, 'block_le_dashboard');
        $this->expectOutputRegex('/Stopped at the time limit/');
        $this->runAdhocTasks(task\backfill::class);
        $this->assertSame(2, indexer::counts()['cm']);
        $this->assertNotEmpty(get_config('block_le_dashboard', 'backfillpending'));
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\' . task\backfill::class]));
        $this->runAdhocTasks(task\backfill::class);
        $this->assertSame(3, indexer::counts()['cm']);
        $this->assertEmpty(get_config('block_le_dashboard', 'backfillpending'));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['classname' => '\\' . task\backfill::class]));
    }

    /**
     * The Index page lines.
     */
    public function test_progress(): void {
        $this->assertSame([], pass::progress(false));
        $this->run_pass(pass::NIGHTLY, 1);
        $this->assertSame(['Nightly check: 1 of 3 courses done.',
            'The last run stopped at its time limit and carries on at the next run.'], pass::progress(false));
        $this->run_pass(pass::NIGHTLY);
        $lines = pass::progress(false);
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('Nightly check: the last full pass finished', $lines[0]);
        task\backfill::queue();
        $this->run_pass(pass::FILL, 1);
        $this->assertSame('Preparing: 1 of 3 courses done.', pass::progress(true)[0]);
    }
}
