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

use block_le_dashboard\local\view\marking_rules;

/**
 * The SQL and PHP forms of each marking rule agree, over every combination of a mark index row they can see.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\view\marking_rules
 */
final class marking_rules_test extends \advanced_testcase {
    /**
     * Ready, marking deadline and the marker a row counts for: the SQL fragments and their PHP twins give the same
     * answer for every row.
     */
    public function test_sql_and_php_forms_agree(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('defaultmarkingdays', 12, 'block_le_dashboard');
        $now = 1790848800;
        $me = 11;
        $other = 12;
        // Assessments: with their own marking deadline, with a deadline only, undated.
        $cms = [];
        foreach ([[$now - DAYSECS, $now + 5 * DAYSECS], [$now - DAYSECS, null], [null, null]] as $i => [$due, $markingdue]) {
            $cmid = 1000 + $i;
            $DB->insert_record('block_le_dashboard_cm', (object)['cmid' => $cmid, 'courseid' => 1, 'modname' => 'assign',
                'adapter' => 'assign', 'name' => "A$i", 'assesstype' => 'summative', 'due' => $due,
                'markingdue' => $markingdue, 'hasallocation' => 0, 'stages' => 'marked', 'timeindexed' => $now]);
            $cms[$cmid] = $DB->get_record('block_le_dashboard_cm', ['cmid' => $cmid]);
        }
        // Every combination of a unit's state.
        $n = 0;
        foreach (array_keys($cms) as $cmid) {
            foreach ([0, 1] as $submitted) {
                foreach ([0, 1] as $marked) {
                    foreach ([0, 1] as $released) {
                        foreach ([null, $now - HOURSECS, $now + HOURSECS] as $unitdue) {
                            foreach ([null, $me, $other] as $markedby) {
                                foreach ([null, $me, $other] as $allocatedto) {
                                    $DB->insert_record('block_le_dashboard_mark', (object)['cmid' => $cmid,
                                        'unitid' => ++$n, 'unittype' => 'user', 'stage' => 'marked',
                                        'allocatedto' => $allocatedto, 'submitted' => $submitted,
                                        'timeready' => $submitted ? $now - DAYSECS : null, 'unitdue' => $unitdue,
                                        'marked' => $marked, 'markedby' => $marked ? $markedby : null,
                                        'timemarked' => $marked ? $now - HOURSECS : null, 'released' => $released,
                                        'flagged' => 0]);
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->assertSame(648, $n);

        $ready = marking_rules::ready_sql($now);
        $deadline = marking_rules::marking_deadline_sql();
        $marker = marking_rules::MARKER_SQL;
        $rows = $DB->get_records_sql("SELECT m.*, c.markingdue AS cmmarkingdue, c.due AS cmdue,
                                             CASE WHEN $ready THEN 1 ELSE 0 END AS sqlready,
                                             $deadline AS sqldeadline,
                                             $marker AS sqlmarker
                                        FROM {block_le_dashboard_mark} m
                                        JOIN {block_le_dashboard_cm} c ON c.cmid = m.cmid");
        $this->assertCount($n, $rows);
        foreach ($rows as $r) {
            $cm = $cms[(int)$r->cmid];
            $r->unitdue = $r->unitdue === null ? null : (int)$r->unitdue;
            $where = "cm {$r->cmid}, submitted {$r->submitted}, marked {$r->marked}, released {$r->released}, "
                . "unitdue " . var_export($r->unitdue, true) . ", markedby " . var_export($r->markedby, true)
                . ", allocatedto " . var_export($r->allocatedto, true);
            $this->assertSame((bool)$r->sqlready, marking_rules::is_ready($r, $now), "ready: $where");
            $this->assertSame(
                $r->sqldeadline === null ? null : (int)$r->sqldeadline,
                marking_rules::marking_deadline($r, $cm),
                "marking deadline: $where"
            );
            $this->assertSame(
                $r->sqlmarker === null ? null : (int)$r->sqlmarker,
                marking_rules::counts_for($r),
                "counts for: $where"
            );
        }
    }

    /**
     * An activity with several markers and no allocation is told apart the same way in SQL and in PHP.
     */
    public function test_shared_forms_agree(): void {
        global $DB;
        $this->resetAfterTest();
        $i = 0;
        foreach ([0, 1] as $hasallocation) {
            foreach (['marked', 'marker1,marker2', 'marker1,marker2,agreed', 'marked,moderated', ''] as $stages) {
                $DB->insert_record('block_le_dashboard_cm', (object)['cmid' => 2000 + $i++, 'courseid' => 1,
                    'modname' => 'assign', 'adapter' => 'assign', 'name' => "A$i", 'assesstype' => 'summative',
                    'due' => null, 'hasallocation' => $hasallocation, 'stages' => $stages, 'timeindexed' => 1]);
            }
        }
        // Ordered: without ORDER BY, PostgreSQL may return the rows in any order.
        $shared = $DB->get_fieldset_sql("SELECT c.cmid FROM {block_le_dashboard_cm} c WHERE " . marking_rules::SHARED_SQL
            . ' ORDER BY c.cmid');
        $this->assertSame([2001, 2002], array_map('intval', $shared));
        foreach ($DB->get_records('block_le_dashboard_cm') as $cm) {
            $this->assertSame(in_array((int)$cm->cmid, [2001, 2002]), marking_rules::is_shared($cm), $cm->cmid);
        }
    }

    /**
     * My share of a shared unit (G7): the stage I marked, else the first open marker stage, else none, plus every
     * other stage once ready; and which of it is my own workload.
     */
    public function test_shared_units(): void {
        $now = 1000;
        $me = 1;
        $row = fn(string $stage, bool $marked, ?int $by, bool $ready = true) => (object)['stage' => $stage,
            'submitted' => 1, 'marked' => $marked ? 1 : 0, 'markedby' => $by, 'released' => 0,
            'unitdue' => $ready ? null : $now + 1, 'allocatedto' => null];
        $stages = fn(array $rows) => array_column($rows, null, 'stage');

        $share = marking_rules::shared_units($stages([$row('marker1', false, null), $row('marker2', false, null),
            $row('agreed', false, null, false)]), $me, $now);
        $this->assertSame(['marker1'], array_column($share, 'stage'), 'first open marker stage; agreement not ready');
        $this->assertTrue(marking_rules::is_own($share[0], $me, true), 'open work in my share falls to me');

        $share = marking_rules::shared_units($stages([$row('marker1', true, 2), $row('marker2', true, $me),
            $row('agreed', false, null)]), $me, $now);
        $this->assertSame(['marker2', 'agreed'], array_column($share, 'stage'), 'the stage I marked, and agreement');
        $this->assertTrue(marking_rules::is_own($share[0], $me, true));

        $share = marking_rules::shared_units($stages([$row('marker1', true, 2), $row('marker2', true, 3),
            $row('agreed', true, 2)]), $me, $now);
        $this->assertSame(['agreed'], array_column($share, 'stage'), 'others gave every mark; agreement is shared');
        $this->assertFalse(marking_rules::is_own($share[0], $me, true), 'agreement others did is not my workload');

        $this->assertFalse(marking_rules::is_own((object)['marked' => 1, 'markedby' => 2, 'allocatedto' => $me], $me));
        $this->assertTrue(marking_rules::is_own((object)['marked' => 0, 'markedby' => null, 'allocatedto' => $me], $me));
        $this->assertFalse(marking_rules::is_own((object)['marked' => 0, 'markedby' => null, 'allocatedto' => null], $me));
    }
}
