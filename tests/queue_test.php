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

/**
 * The queue of background re-indexes: one each, held back or brought forward, collected to the end of a request.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\index\queue
 */
final class queue_test extends \advanced_testcase {
    /** @var \block_le_dashboard_generator */
    protected $ma;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Index writers observe events after commit, and some tests roll back a write: no test transaction.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->ma = $this->getDataGenerator()->get_plugin_generator('block_le_dashboard');
        $this->ma->configure(['assign', 'quiz', 'forum', 'lti']);
    }

    /**
     * F1: a change during a running re-index queues another; a waiting one is not doubled.
     */
    public function test_queue_dedupes_waiting_only(): void {
        global $DB;
        $params = ['classname' => '\\block_le_dashboard\\task\\reindex'];
        indexer::queue_cm(42);
        indexer::queue_cm(42);
        $this->assertSame(1, $DB->count_records('task_adhoc', $params));
        $DB->set_field('task_adhoc', 'timestarted', time(), $params);
        indexer::queue_cm(42);
        $this->assertSame(2, $DB->count_records('task_adhoc', $params), 'the running one has read its data already');
    }

    /**
     * Deadline rush: a held-back re-index waits; one asked for now brings it forward rather than waiting behind it.
     */
    public function test_queue_delay_and_bring_forward(): void {
        global $DB;
        $params = ['classname' => '\\block_le_dashboard\\task\\reindex'];
        $before = time();
        indexer::queue_cm(42, indexer::COALESCE);
        indexer::queue_cm(42, indexer::COALESCE);
        $this->assertSame(1, $DB->count_records('task_adhoc', $params), 'a rush of submissions is one run');
        $this->assertGreaterThanOrEqual($before + indexer::COALESCE, (int)$DB->get_field('task_adhoc', 'nextruntime', $params));
        indexer::queue_cm(42);
        $this->assertSame(1, $DB->count_records('task_adhoc', $params));
        // Due before this second, as cron runs only tasks due before the second it starts.
        $this->assertLessThan(time(), (int)$DB->get_field('task_adhoc', 'nextruntime', $params));
        // A later hold-back never delays it again.
        indexer::queue_cm(42, indexer::COALESCE);
        $this->assertLessThanOrEqual(time(), (int)$DB->get_field('task_adhoc', 'nextruntime', $params));
    }

    /**
     * P6: within a web request, re-indexes are collected and each queued once at the end, as early as asked.
     */
    public function test_queue_deferred_to_end_of_request(): void {
        global $DB;
        $params = ['classname' => '\\block_le_dashboard\\task\\reindex'];
        indexer::defer_queue();
        $queries = $DB->perf_get_queries();
        for ($i = 0; $i < 50; $i++) {
            indexer::queue_cm(42, indexer::COALESCE);
        }
        indexer::queue_cm(42);
        indexer::queue_course(7);
        $this->assertSame($queries, $DB->perf_get_queries(), 'no queue checks while grading');
        $this->assertSame(0, $DB->count_records('task_adhoc', $params));
        indexer::flush_queue();
        $this->assertSame(2, $DB->count_records('task_adhoc', $params));
        $cm = $DB->get_record_select('task_adhoc', 'classname = :classname AND ' .
            $DB->sql_compare_text('customdata') . ' = :data', $params + ['data' => json_encode(['cmid' => 42])]);
        $this->assertLessThanOrEqual(time(), (int)$cm->nextruntime, 'the earliest asked for wins');
        // Back to queueing as it comes.
        indexer::queue_cm(43);
        $this->assertSame(3, $DB->count_records('task_adhoc', $params));
    }
}
