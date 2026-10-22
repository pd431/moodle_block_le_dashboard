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

use block_le_dashboard\local\view\staff;

/**
 * "Mine" on My marking: the bars, the Extensions hint and the plagiarism flag agree on whose marking it is.
 *
 * Written against beta28 (the candidate inconsistencies C1 to C3 and C5 of the beta29 scope) before the rules were
 * folded into one place, so each case records what the dashboard did and keeps doing.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\view\staff
 */
final class marking_mine_test extends \advanced_testcase {
    /** @var array Users by name. */
    protected array $u = [];
    /** @var int The course. */
    protected int $courseid;
    /** @var int The clock. */
    protected int $now;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $convenorrole = $gen->get_plugin_generator('block_le_dashboard')->configure(['assign', 'quiz']);
        set_config('hint_plagiarism', 1, 'block_le_dashboard');
        $course = $gen->create_course(['shortname' => 'M1']);
        $this->courseid = (int)$course->id;
        foreach (['s1', 's2'] as $name) {
            $this->u[$name] = $gen->create_and_enrol($course, 'student');
        }
        foreach (['marker1', 'marker2'] as $name) {
            $this->u[$name] = $gen->create_and_enrol($course, 'teacher');
        }
        $this->u['convenor'] = $gen->create_user();
        $gen->enrol_user($this->u['convenor']->id, $course->id, $convenorrole);
        $this->now = time();
    }

    /**
     * An assessment in the index with a mark row per student and stage, and a student row each, both students with
     * an extension still to come.
     *
     * @param bool $hasallocation
     * @param string[] $stages
     * @param array $marks unit => stage => [allocatedto, markedby] (markedby null: not marked).
     * @return int cmid
     */
    protected function assessment(bool $hasallocation, array $stages, array $marks): int {
        global $DB;
        $gen = $this->getDataGenerator();
        $a = $gen->create_module('assign', ['course' => $this->courseid, 'name' => 'A1', 'duedate' => $this->now - 3 * DAYSECS]);
        $cmid = (int)$a->cmid;
        $DB->insert_record('block_le_dashboard_cm', (object)['cmid' => $cmid, 'courseid' => $this->courseid,
            'modname' => 'assign', 'adapter' => 'assign', 'name' => 'A1', 'assesstype' => 'summative',
            'due' => $this->now - 3 * DAYSECS, 'hasallocation' => $hasallocation ? 1 : 0,
            'stages' => implode(',', $stages), 'timeindexed' => $this->now]);
        foreach (['s1', 's2'] as $name) {
            $userid = (int)$this->u[$name]->id;
            $allocated = [];
            foreach ($marks[$name] as $stage => [$allocatedto, $markedby]) {
                $DB->insert_record('block_le_dashboard_mark', (object)['cmid' => $cmid, 'unitid' => $userid,
                    'unittype' => 'user', 'stage' => $stage, 'allocatedto' => $allocatedto, 'submitted' => 1,
                    'timeready' => $this->now - 2 * DAYSECS, 'unitdue' => $this->now - 3 * DAYSECS,
                    'marked' => $markedby ? 1 : 0, 'markedby' => $markedby,
                    'timemarked' => $markedby ? $this->now - DAYSECS : null, 'released' => 0, 'flagged' => 1]);
                if ($allocatedto) {
                    $allocated[] = $allocatedto;
                }
            }
            $DB->insert_record('block_le_dashboard_stu', (object)['cmid' => $cmid, 'userid' => $userid,
                'due' => $this->now + 2 * DAYSECS, 'submitted' => 1, 'timesubmitted' => $this->now - 2 * DAYSECS,
                'allocatedto' => $allocated ? ',' . implode(',', array_unique($allocated)) . ',' : null,
                'canaccess' => 1, 'active' => 1]);
        }
        return $cmid;
    }

    /**
     * The A1 row of a marker's My marking, from whichever section holds it.
     *
     * @param string $name
     * @return array|null
     */
    protected function row(string $name): ?array {
        $export = (new staff((int)$this->u[$name]->id, $this->now))->export();
        foreach ($export['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['name'] === 'A1') {
                    return $row + ['section' => $section['key']];
                }
            }
        }
        return null;
    }

    /**
     * A row's own bars by key: on a Completed row they sit behind Show marking, first in its list.
     *
     * @param array $row
     * @return array
     */
    protected static function bars(array $row): array {
        $bars = $row['bars'] ?: ($row['markers'][0]['bars'] ?? []);
        return array_column($bars, null, 'key');
    }

    /**
     * Hints by key.
     *
     * @param array $row
     * @return array
     */
    protected static function hints(array $row): array {
        return array_column($row['hints'], null, 'key');
    }

    /**
     * C1 and C2: on double-marked work without allocation, each marker marks every student, so the Extensions hint
     * counts both students and the flag counts both units, while the bars hold the marker's own share (one stage
     * per unit).
     */
    public function test_shared_marking_counts_every_student(): void {
        $this->assessment(false, ['marker1', 'marker2', 'agreed'], [
            's1' => ['marker1' => [null, null], 'marker2' => [null, null], 'agreed' => [null, null]],
            's2' => ['marker1' => [null, (int)$this->u['marker1']->id], 'marker2' => [null, null], 'agreed' => [null, null]],
        ]);
        foreach (['marker1', 'marker2'] as $name) {
            $row = $this->row($name);
            $this->assertNotNull($row, $name);
            $bars = self::bars($row);
            $this->assertSame(2, $bars['marked']['max'], "$name: one stage per unit in my share");
            $hints = self::hints($row);
            $this->assertSame(2, $hints['extensions']['count'], "$name: both students' work may still arrive");
            $this->assertSame(2, $hints['plagiarism']['count'], "$name: both units are flagged");
        }
        $this->assertSame(1, self::bars($this->row('marker1'))['marked']['value']);
        $this->assertSame(0, self::bars($this->row('marker2'))['marked']['value']);
    }

    /**
     * C3 (EC8): work I marked without being allocated it counts as mine in the bars and in the Extensions hint alike.
     */
    public function test_marked_without_allocation_is_mine_everywhere(): void {
        $me = (int)$this->u['marker1']->id;
        $other = (int)$this->u['marker2']->id;
        $this->assessment(true, ['marked'], [
            's1' => ['marked' => [null, $me]],
            's2' => ['marked' => [$other, null]],
        ]);
        $row = $this->row('marker1');
        $this->assertNotNull($row);
        $this->assertSame('completed', $row['section']);
        $bars = self::bars($row);
        $this->assertSame([1, 1], [$bars['marked']['value'], $bars['marked']['max']], 'the unit I marked is mine');
        $hints = self::hints($row);
        $this->assertArrayHasKey('extensions', $hints, 'and so is that student\'s extension');
        $this->assertSame(1, $hints['extensions']['count']);
        $this->assertSame(1, $hints['plagiarism']['count']);

        $row = $this->row('marker2');
        $this->assertSame(1, self::hints($row)['extensions']['count'], 'the other marker sees their allocated student only');
        $this->assertNull($this->row('convenor'), 'nothing is unallocated, so the convenor has no marking here');
    }

    /**
     * C5: Completed marking holds a marker's own work by one rule on single-marker activities (counted in SQL) and
     * on double-marked ones (counted in PHP): work I marked is mine, finished work colleagues marked isn't, and work
     * still to mark keeps the row in Marking in progress either way.
     */
    public function test_own_marking_agrees_between_single_and_double_marking(): void {
        $me = (int)$this->u['marker1']->id;
        $other = (int)$this->u['marker2']->id;
        $third = (int)$this->getDataGenerator()->create_and_enrol(get_course($this->courseid), 'teacher')->id;
        // Each case for one stage and for two: who marked each student's stages.
        $cases = [
            'all my marking' => [[[$me], [$me]], [[$me, $other], [$me, $other]]],
            'mine done, the rest colleagues\'' => [[[$me], [$other]], [[$me, $other], [$other, $third]]],
            'one still to mark' => [[[$me], [null]], [[$me, $other], [null, null]]],
        ];
        foreach ($cases as $case => $bystages) {
            foreach ($bystages as $i => [$s1, $s2]) {
                $stages = $i === 0 ? ['marked'] : ['marker1', 'marker2'];
                $kind = "$case, " . count($stages) . ' stage(s)';
                $marks = [];
                foreach (['s1' => $s1, 's2' => $s2] as $name => $markers) {
                    foreach ($stages as $j => $stage) {
                        $marks[$name][$stage] = [null, $markers[$j]];
                    }
                }
                $cmid = $this->assessment(false, $stages, $marks);
                $row = $this->row('marker1');
                if ($case === 'one still to mark') {
                    $this->assertSame('tomark', $row['section'], $kind);
                    $this->assertSame('tomark', $this->row('marker2')['section'], "$kind: open to the other marker too");
                } else {
                    $this->assertSame('completed', $row['section'], $kind);
                    // The bar counts every unit of a single-marker activity (all of it is everyone's) and my share
                    // of a double-marked one (G7): either way it reads as finished.
                    $bar = self::bars($row)['marked'];
                    $this->assertSame($bar['max'], $bar['value'], $kind);
                    $this->assertSame(count($stages) === 1 || $case === 'all my marking' ? 2 : 1, $bar['max'], $kind);
                }
                if ($case === 'mine done, the rest colleagues\'' && count($stages) === 1) {
                    $this->assertSame('completed', $this->row('marker2')['section'], "$kind: the colleague's own work");
                }
                $this->remove($cmid);
            }
        }
    }

    /**
     * Drops an assessment from the index.
     *
     * @param int $cmid
     */
    protected function remove(int $cmid): void {
        global $DB;
        foreach (['block_le_dashboard_mark', 'block_le_dashboard_stu', 'block_le_dashboard_cm'] as $table) {
            $DB->delete_records($table, ['cmid' => $cmid]);
        }
        \block_le_dashboard\local\memo::reset();
    }
}
