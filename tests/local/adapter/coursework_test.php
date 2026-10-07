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

namespace block_le_dashboard\local\adapter;

/**
 * The coursework adapter's plagiarism flags, against a stand-in for mod_coursework's flags table,
 * since mod_coursework can't be installed where these tests run.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\adapter\coursework
 */
final class coursework_test extends \advanced_testcase {
    /** The flags table mod_coursework keeps. */
    const TABLE = 'coursework_plagiarism_flags';

    /**
     * Calls a protected static method of the adapter.
     *
     * @param string $method
     * @param mixed ...$args
     * @return mixed
     */
    protected static function call(string $method, ...$args) {
        $m = new \ReflectionMethod(coursework::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    /**
     * Forgets the adapter's cached table columns, so it sees the table appear or go.
     */
    protected static function forget_columns(): void {
        $p = new \ReflectionProperty(coursework::class, 'columns');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    /**
     * Open flags count once per submission; cleared ones (status outside the open list), rows
     * without a submission and other courseworks don't. Without the table, nothing is flagged.
     */
    public function test_flagged_submissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        if ($dbman->table_exists(self::TABLE)) {
            $this->markTestSkipped('mod_coursework is installed: its own tests cover the real table');
        }
        self::forget_columns();
        $this->assertSame([], self::call('flagged_submissions', 7));

        $table = new \xmldb_table(self::TABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseworkid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);
        try {
            // Coursework, submission, status: open flags on 101 (and a cleared one), one on 102, cleared 103 (4) and
            // 106 (2, Pierre 2026-10-07), no submission, no status.
            $rows = [[7, 101, '0'], [7, 101, '2'], [7, 102, '3'], [7, 103, '4'], [7, 106, '2'], [7, 0, '1'], [7, 104, null],
                [8, 105, '1']];
            foreach ($rows as [$cw, $sub, $status]) {
                $DB->insert_record(self::TABLE, ['courseworkid' => $cw, 'submissionid' => $sub, 'status' => $status]);
            }
            self::forget_columns();
            $this->assertSame([101 => true, 102 => true], self::call('flagged_submissions', 7));
            $this->assertSame([105 => true], self::call('flagged_submissions', 8));
        } finally {
            $dbman->drop_table($table);
            self::forget_columns();
        }
    }
}
