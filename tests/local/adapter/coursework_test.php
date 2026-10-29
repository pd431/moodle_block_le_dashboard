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

use block_le_dashboard\local\index\marking_row;

/**
 * The coursework adapter against stand-ins for mod_coursework's tables, named as Exeter's coursework names them
 * (CS0), since mod_coursework can't be installed where these tests run.
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
     * A submission's latest flag counts, once (CS3): open (0, or a status Exeter doesn't use, or none) or withheld
     * (3); cleared ones (1, 2), rows without a submission and other courseworks don't.
     */
    public function test_flagged_submissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        if ($dbman->table_exists(self::TABLE)) {
            $this->markTestSkipped('mod_coursework is installed: its own tests cover the real table');
        }
        $table = new \xmldb_table(self::TABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseworkid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);
        try {
            // Coursework, submission, status: 101 cleared then reopened, 102 withheld, 103 an unknown status, 104
            // none, 106 open then cleared, 107 cleared (1), a row with no submission, and another coursework.
            $rows = [[7, 101, '2'], [7, 101, '0'], [7, 102, '3'], [7, 103, '4'], [7, 104, null], [7, 106, '0'],
                [7, 106, '2'], [7, 107, '1'], [7, 0, '0'], [8, 105, '0']];
            foreach ($rows as [$cw, $sub, $status]) {
                $DB->insert_record(self::TABLE, ['courseworkid' => $cw, 'submissionid' => $sub, 'status' => $status]);
            }
            $open = marking_row::FLAG_OPEN;
            $expected = [101 => $open, 102 => marking_row::FLAG_WITHHELD, 103 => $open, 104 => $open];
            $this->assertSame($expected, self::call('flagged_submissions', 7));
            $this->assertSame([102 => marking_row::FLAG_WITHHELD], self::call('flagged_submissions', 7, [102, 107]));
            $this->assertSame([105 => $open], self::call('flagged_submissions', 8));
            $this->assertSame([], self::call('flagged_submissions', 8, []));
        } finally {
            $dbman->drop_table($table);
        }
    }

    /**
     * Creates a stand-in table with the given fields (all integers unless named in $chars), runs the test
     * body, then drops it. Skips when mod_coursework's own table is there.
     *
     * @param array $tables table => [fields, char fields]
     * @param callable $body
     */
    protected function with_tables(array $tables, callable $body): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $made = [];
        foreach ($tables as $name => [$fields, $chars]) {
            if ($dbman->table_exists($name)) {
                $this->markTestSkipped('mod_coursework is installed: its own tests cover the real tables');
            }
        }
        try {
            foreach ($tables as $name => [$fields, $chars]) {
                $table = new \xmldb_table($name);
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
                foreach ($fields as $field) {
                    $table->add_field($field, XMLDB_TYPE_INTEGER, '10');
                }
                foreach ($chars as $field) {
                    $table->add_field($field, XMLDB_TYPE_CHAR, '100');
                }
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $dbman->create_table($table);
                $made[] = $table;
            }
            $body();
        } finally {
            foreach ($made as $table) {
                $dbman->drop_table($table);
            }
        }
    }

    /**
     * Pierre's schema (2026-10-07): an unfinalised submission still counts when it has a submission time or a
     * finalised, non-blank mark; finalised only locks it. A blank or unfinalised mark alone doesn't count.
     */
    public function test_submissions(): void {
        global $DB;
        $this->with_tables([
            'coursework_submissions' => [['courseworkid', 'allocatableid', 'timesubmitted', 'firstpublished',
                'lastpublished', 'finalised'], ['allocatabletype']],
            'coursework_feedbacks' => [['submissionid', 'assessorid', 'finalised'], ['grade', 'stage_identifier']],
        ], function () use ($DB) {
            $sub = fn($user, $time, $finalised) => $DB->insert_record('coursework_submissions', ['courseworkid' => 7,
                'allocatableid' => $user, 'allocatabletype' => 'user', 'timesubmitted' => $time, 'firstpublished' => 0,
                'lastpublished' => 0, 'finalised' => $finalised]);
            $sub(1, 100, 1);
            $sub(2, 100, 0);
            $draft = $sub(3, 0, 0);
            $blank = $sub(4, 0, 0);
            $unfinal = $sub(5, 0, 0);
            $feedback = fn($id, $grade, $finalised) => $DB->insert_record('coursework_feedbacks', ['submissionid' => $id,
                'assessorid' => 9, 'grade' => $grade, 'finalised' => $finalised, 'stage_identifier' => 'assessor_1']);
            $feedback($draft, '62', 1);
            $feedback($blank, '', 1);
            $feedback($unfinal, '55', 0);

            $subs = self::call('submissions', 7);
            $this->assertSame(['user:1', 'user:2', 'user:3'], array_keys($subs));
            $this->assertEquals(1, $subs['user:1']->finalised);
            $this->assertEquals(0, $subs['user:2']->finalised);
            $this->assertEquals(0, $subs['user:3']->timesubmitted);

            // Scale (beta19): a call for some students reads only their submissions.
            $this->assertSame(['user:1', 'user:3'], array_keys(self::call('submissions', 7, [['user', 1], ['user', 3]])));
            $this->assertSame([], self::call('submissions', 7, [['group', 1]]));
            $this->assertSame([], self::call('submissions', 7, []));
        });
    }

    /**
     * Released once first published, or last published where only that is set.
     */
    public function test_released(): void {
        $this->assertFalse(self::call('released', (object)['firstpublished' => 0, 'lastpublished' => null]));
        $this->assertTrue(self::call('released', (object)['firstpublished' => 5, 'lastpublished' => 0]));
        $this->assertTrue(self::call('released', (object)['firstpublished' => null, 'lastpublished' => 5]));
    }

    /**
     * Withheld work (CS3) and exempt or deferred students (CS4) are in no marking count; open flags,
     * extensions and units without a submission are.
     */
    public function test_uncounted(): void {
        $units = ['user:1' => true, 'user:2' => true, 'user:3' => true];
        $submissions = ['user:1' => (object)['id' => 11], 'user:2' => (object)['id' => 12]];
        $flagged = [11 => marking_row::FLAG_WITHHELD, 12 => marking_row::FLAG_OPEN];
        $this->assertSame(['user:1' => true], self::call('uncounted', $units, $submissions, $flagged));
        // Exempt or deferred students too, with or without a submission.
        $mitigations = ['user:3' => ['excused' => 'deferred'], 'user:2' => ['extension' => [5, false]]];
        $uncounted = self::call('uncounted', $units, $submissions, $flagged, $mitigations);
        $this->assertSame(['user:1' => true, 'user:3' => true], $uncounted);
    }

    /**
     * Each flag status means what Pierre said (CS3).
     */
    public function test_flag_state(): void {
        [$none, $open, $withheld] = [marking_row::FLAG_NONE, marking_row::FLAG_OPEN, marking_row::FLAG_WITHHELD];
        $states = ['0' => $open, '1' => $none, '2' => $none, ' 2 ' => $none, '3' => $withheld, '7' => $open, '' => $open];
        foreach ($states as $status => $state) {
            $this->assertSame($state, coursework::flag_state((string)$status), "status '$status'");
        }
        $this->assertSame($open, coursework::flag_state(null));
    }

    /**
     * Exeter's coursework names the group setting use_groups; flags follow plagiarismflagenabled.
     */
    public function test_settings(): void {
        $this->assertTrue(self::call('uses_groups', (object)['use_groups' => 1]));
        $this->assertFalse(self::call('uses_groups', (object)['use_groups' => 0]));
        $this->assertTrue(self::call('flags_enabled', (object)['plagiarismflagenabled' => 1]));
        $this->assertFalse(self::call('flags_enabled', (object)['plagiarismflagenabled' => 0]));
    }

    /**
     * One name per fact, the reference's (CS0): no other build's column or table, and no checks for which exists.
     */
    public function test_reads_one_name_per_fact(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/blocks/le_dashboard/classes/local/adapter/coursework.php');
        $names = ['use_groups' => 'usegroups', 'finalised' => 'finalisedstatus', 'stage_identifier' => 'stageidentifier',
            'personal_deadline' => 'personaldeadline\b(?!_)'];
        foreach ($names as $read => $notread) {
            $this->assertStringContainsString($read, $source);
            $this->assertDoesNotMatchRegularExpression("~(?<![_a-z])$notread~", $source, "reads $notread");
        }
        $this->assertStringNotContainsString('coursework_extensions', $source);
        // Whether anything is handed in online comes from the submission types (CS1), not these columns.
        foreach (['courseworktype', 'submissiontype', 'submissionmethod'] as $column) {
            $this->assertStringNotContainsString($column, $source);
        }
        foreach (['get_columns', 'table_exists', 'field_exists', 'property_exists', 'column('] as $check) {
            $this->assertStringNotContainsString($check, $source, "checks which column exists with $check");
        }
    }

    /**
     * Mitigations by allocatable (CS4): the last extension recorded, with whether it was self-certified; permanent
     * is exempt and temporary deferred, and an exemption wins whichever came first. Other types are ignored.
     */
    public function test_mitigations(): void {
        global $DB;
        $this->with_tables([
            'coursework_mitigations' => [['courseworkid', 'allocatableid', 'extended_deadline', 'selfcert', 'timecreated'],
                ['allocatabletype', 'type']],
        ], function () use ($DB) {
            $add = fn($user, $type, $until = 0, $selfcert = 0, $cw = 7) => $DB->insert_record('coursework_mitigations', [
                'courseworkid' => $cw, 'allocatableid' => $user, 'allocatabletype' => 'user', 'type' => $type,
                'extended_deadline' => $until, 'selfcert' => $selfcert, 'timecreated' => 1]);
            $add(1, 'extension', 500, 1);
            $add(1, 'extension', 400);
            $add(2, 'extension', 300);
            $add(3, 'permanent');
            $add(3, 'temporary');
            $add(4, 'temporary');
            $add(5, 'temporary');
            $add(5, 'permanent');
            $add(5, 'extension', 600, 1);
            $add(6, 'somethingelse');
            $add(7, 'permanent', 0, 0, 8);
            $this->assertSame([
                'user:1' => ['extension' => [400, false]],
                'user:2' => ['extension' => [300, false]],
                'user:3' => ['excused' => 'exempt'],
                'user:4' => ['excused' => 'deferred'],
                'user:5' => ['excused' => 'exempt', 'extension' => [600, true]],
            ], self::call('mitigations', 7));
            $expected = ['user:2' => ['extension' => [300, false]], 'user:4' => ['excused' => 'deferred']];
            $this->assertSame($expected, self::call('mitigations', 7, [['user', 2], ['user', 4]]));
        });
    }

    /**
     * Moderator allocations count as the moderation stage, whether flagged or staged moderator_1.
     */
    public function test_allocation_pairs(): void {
        global $DB;
        $this->with_tables([
            'coursework_allocation_pairs' => [['courseworkid', 'allocatableid', 'assessorid', 'moderator'],
                ['allocatabletype', 'stage_identifier']],
        ], function () use ($DB) {
            $pair = fn($user, $assessor, $stage, $moderator) => $DB->insert_record('coursework_allocation_pairs', [
                'courseworkid' => 7, 'allocatableid' => $user, 'allocatabletype' => 'user', 'assessorid' => $assessor,
                'stage_identifier' => $stage, 'moderator' => $moderator]);
            $pair(1, 10, 'assessor_1', 0);
            $pair(1, 11, 'moderator_1', 0);
            $pair(2, 12, 'assessor_1', 0);
            $pair(2, 13, 'moderator', 1);
            $this->assertSame([
                'user:1' => ['assessor_1' => 10, 'moderator' => 11],
                'user:2' => ['assessor_1' => 12, 'moderator' => 13],
            ], self::call('allocation_pairs', 7));
            // Allocated only with marker pairs: coursework 8 has only moderator pairs, 9 has none (beta19).
            $DB->insert_record('coursework_allocation_pairs', ['courseworkid' => 8, 'allocatableid' => 1,
                'allocatabletype' => 'user', 'assessorid' => 14, 'stage_identifier' => 'moderator_1', 'moderator' => 1]);
            $DB->insert_record('coursework_allocation_pairs', ['courseworkid' => 8, 'allocatableid' => 2,
                'allocatabletype' => 'user', 'assessorid' => 14, 'stage_identifier' => 'assessor_1', 'moderator' => 1]);
            $this->assertTrue(self::call('has_assessor_pairs', 7));
            $this->assertFalse(self::call('has_assessor_pairs', 8));
            $this->assertFalse(self::call('has_assessor_pairs', 9));
            // Moderation is a sample wherever a moderator is allocated, by flag or stage.
            $this->assertTrue(self::call('has_moderator_pairs', 7));
            $this->assertTrue(self::call('has_moderator_pairs', 8));
            $this->assertFalse(self::call('has_moderator_pairs', 9));
            // Scale (beta19): only the students asked for.
            $this->assertSame(
                ['user:2' => ['assessor_1' => 12, 'moderator' => 13]],
                self::call('allocation_pairs', 7, [['user', 2]])
            );
        });
    }

    /**
     * Marks entry as the only submission type means nothing is handed in online (CS1); file, online text or
     * Panopto alongside it, or no types recorded at all, means work is handed in.
     */
    public function test_marks_entry_only(): void {
        global $DB;
        $this->with_tables([
            'coursework_sub_plugin' => [[], ['name']],
            'coursework_sub_plugin_cwk' => [['courseworkid', 'subpluginid'], []],
        ], function () use ($DB) {
            $ids = [];
            foreach (['file', 'marksentry', 'onlinetext', 'panopto'] as $name) {
                $ids[$name] = $DB->insert_record('coursework_sub_plugin', ['name' => $name]);
            }
            $types = [1 => ['marksentry'], 2 => ['marksentry', 'file'], 3 => ['file'], 5 => ['panopto']];
            foreach ($types as $cw => $names) {
                foreach ($names as $name) {
                    $DB->insert_record('coursework_sub_plugin_cwk', ['courseworkid' => $cw, 'subpluginid' => $ids[$name]]);
                }
            }
            $this->assertTrue(self::call('marks_entry_only', 1));
            $this->assertFalse(self::call('marks_entry_only', 2));
            $this->assertFalse(self::call('marks_entry_only', 3));
            $this->assertFalse(self::call('marks_entry_only', 4));
            $this->assertFalse(self::call('marks_entry_only', 5));
        });
    }

    /**
     * Attendance comes first where nothing is handed in online, then coursework's own stages (CS1).
     */
    public function test_stage_map_with_attendance(): void {
        $cw = (object)['numberofmarkers' => 2, 'moderationenabled' => 0];
        $expected = ['marker1' => 'assessor_1', 'marker2' => 'assessor_2', 'agreed' => 'final_agreed_1'];
        $this->assertSame($expected, coursework::stage_map($cw));
        $this->assertSame(['attendance' => null, 'marker1' => 'assessor_1', 'marker2' => 'assessor_2',
            'agreed' => 'final_agreed_1'], coursework::stage_map($cw, true));
    }

    /**
     * Every unit is ready at the attendance stage at its deadline, and taken once coursework has a submission row
     * for it, handed in or not; allocated to the first marker where markers are allocated (CS1).
     */
    public function test_attendance_rows(): void {
        $units = ['user:1' => true, 'user:2' => true, 'group:3' => true];
        $subs = ['user:1' => (object)['firstpublished' => 0, 'lastpublished' => 0, 'timesubmitted' => 0],
            'group:3' => (object)['firstpublished' => 9, 'lastpublished' => 0, 'timesubmitted' => 0]];
        $rows = self::call('attendance_rows', $units, $subs, ['user:2' => ['assessor_1' => 40]]);
        $got = array_map(fn($r) => [$r->unittype . ':' . $r->unitid, $r->stage, $r->submitted, $r->marked, $r->released,
            $r->allocatedto, $r->timeready], $rows);
        $this->assertSame([
            ['user:1', 'attendance', true, true, false, null, 1],
            ['user:2', 'attendance', true, false, false, 40, 1],
            ['group:3', 'attendance', true, true, true, null, 1],
        ], $got);
        $this->assertNull(self::call('attendance_rows', ['user:2' => true], [], null)[0]->allocatedto);
        $rows = self::call('attendance_rows', ['user:1' => true, 'user:2' => true], [], null, ['user:2' => true]);
        $this->assertSame([true, false], array_map(fn($r) => $r->counted, $rows));
    }

    /**
     * Where attendance is taken, every submission row counts, handed in or not (CS1).
     */
    public function test_submissions_any_row(): void {
        global $DB;
        $this->with_tables([
            'coursework_submissions' => [['courseworkid', 'allocatableid', 'timesubmitted', 'firstpublished',
                'lastpublished', 'finalised'], ['allocatabletype']],
            'coursework_feedbacks' => [['submissionid', 'assessorid', 'finalised'], ['grade', 'stage_identifier']],
        ], function () use ($DB) {
            foreach ([[1, 100], [2, 0]] as [$user, $time]) {
                $DB->insert_record('coursework_submissions', ['courseworkid' => 7, 'allocatableid' => $user,
                    'allocatabletype' => 'user', 'timesubmitted' => $time, 'firstpublished' => 0, 'lastpublished' => 0,
                    'finalised' => 0]);
            }
            $this->assertSame(['user:1'], array_keys(self::call('submissions', 7)));
            $this->assertSame(['user:1', 'user:2'], array_keys(self::call('submissions', 7, null, true)));
        });
    }
}
