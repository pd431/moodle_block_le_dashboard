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

use block_my_assessments\local\view\convenor;
use block_my_assessments\local\view\staff;
use block_my_assessments\local\view\student;

/**
 * The three views, end to end from the index.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\local\view\student
 * @covers     \block_my_assessments\local\view\staff
 * @covers     \block_my_assessments\local\view\convenor
 */
final class views_test extends \advanced_testcase {
    /** @var array Users by name. */
    protected array $u = [];
    /** @var array Activities by name. */
    protected array $a = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $ma = $gen->get_plugin_generator('block_my_assessments');
        $convenorrole = $ma->configure(['assign', 'quiz', 'forum']);
        $course = $gen->create_course(['shortname' => 'M1']);
        foreach (['s1', 's2'] as $name) {
            $this->u[$name] = $gen->create_and_enrol($course, 'student');
        }
        $this->u['marker'] = $gen->create_and_enrol($course, 'teacher');
        $this->u['other'] = $gen->create_and_enrol($course, 'teacher');
        $this->u['convenor'] = $gen->create_user();
        $gen->enrol_user($this->u['convenor']->id, $course->id, $convenorrole);

        $now = time();
        $agen = $gen->get_plugin_generator('mod_assign');
        $this->a['overdue'] = $agen->create_instance(['course' => $course->id, 'name' => 'Overdue essay',
            'duedate' => $now - DAYSECS, 'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1,
            'markingworkflow' => 1, 'markingallocation' => 1]);
        $this->a['upcoming'] = $agen->create_instance(['course' => $course->id, 'name' => 'Upcoming report',
            'duedate' => $now + 3 * DAYSECS, 'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1]);
        $this->a['forum'] = $gen->create_module('forum', ['course' => $course->id, 'name' => 'Discussion',
            'duedate' => $now - DAYSECS]);
        // Student s2 submits the overdue essay late; it is allocated to the marker.
        $agen->create_submission(['userid' => $this->u['s2']->id, 'cmid' => $this->a['overdue']->cmid,
            'onlinetext' => 'x']);
        $ma->allocate_assign($this->a['overdue']->cmid, $this->u['s2']->id, $this->u['marker']->id);
        // Student s1 submits the upcoming report, unallocated (no allocation on that one).
        $agen->create_submission(['userid' => $this->u['s1']->id, 'cmid' => $this->a['upcoming']->cmid, 'onlinetext' => 'x']);

        $ma->tag($this->a['overdue']->cmid, 'summative');
        $ma->tag($this->a['upcoming']->cmid, 'formative');
        $ma->tag($this->a['forum']->cmid, 'summative');
    }

    /**
     * Rows of a section, by name.
     *
     * @param array $export
     * @param string $key
     * @return array name => row
     */
    protected static function rows(array $export, string $key): array {
        foreach ($export['sections'] as $section) {
            if ($section['key'] === $key) {
                return array_column($section['rows'], null, 'name');
            }
        }
        throw new \coding_exception("No section $key");
    }

    /**
     * Summary box values by key.
     *
     * @param array $export
     * @return array
     */
    protected static function boxes(array $export): array {
        return array_column($export['summary'], 'value', 'key');
    }

    /**
     * Student view sections, statuses and counts.
     */
    public function test_student(): void {
        $s1 = (new student($this->u['s1']->id))->export();
        $this->assertArrayHasKey('Overdue essay', self::rows($s1, 'overdue'));
        $this->assertArrayHasKey('Upcoming report', self::rows($s1, 'upcoming'));
        $this->assertArrayHasKey('Discussion', self::rows($s1, 'past'), 'generic types are never overdue (R5)');
        $boxes = self::boxes($s1);
        $this->assertSame(1, $boxes['overdue']);
        $this->assertSame(1, $boxes['upcoming']);
        $this->assertSame(1, $boxes['submitted']);
        $counts = json_decode($s1['summary'][1]['counts'], true);
        $this->assertSame(['both' => 1, 'summative' => 0, 'formative' => 1], $counts);

        $s2 = (new student($this->u['s2']->id))->export();
        $this->assertArrayHasKey('Overdue essay', self::rows($s2, 'past'), 'submitted late: in marking');
        $this->assertSame(0, self::boxes($s2)['overdue']);
    }

    /**
     * Markers see only work allocated to them where allocation is on, and unallocated work is
     * left to convenors (D6).
     */
    public function test_staff(): void {
        $marker = (new staff($this->u['marker']->id))->export();
        $tomark = self::rows($marker, 'tomark');
        $this->assertArrayHasKey('Overdue essay', $tomark);
        $this->assertSame(1, $tomark['Overdue essay']['tomark']);
        $this->assertSame(['marked'], array_column($tomark['Overdue essay']['bars'], 'key'), 'one bar per kind of work');
        $this->assertSame(['tomark', 'upcoming', 'completed'], array_column($marker['sections'], 'key'));
        $this->assertArrayNotHasKey('Upcoming report', $tomark, 'an early submission waits for the deadline (G9)');
        $this->assertArrayHasKey('Upcoming report', self::rows($marker, 'upcoming'));

        $other = (new staff($this->u['other']->id))->export();
        $this->assertArrayNotHasKey('Overdue essay', self::rows($other, 'tomark'));
        $this->assertArrayNotHasKey('Overdue essay', self::rows($other, 'completed'));
    }

    /**
     * G9: work counts at each student's own deadline, and extensions still to come are flagged
     * for the students whose marking is mine, and for the convenor.
     */
    public function test_extensions(): void {
        global $DB;
        $ma = $this->getDataGenerator()->get_plugin_generator('block_my_assessments');
        $cmid = $this->a['overdue']->cmid;
        $ma->allocate_assign($cmid, $this->u['s1']->id, $this->u['marker']->id);
        $extension = time() + 2 * DAYSECS;
        $DB->set_field(
            'assign_user_flags',
            'extensionduedate',
            $extension,
            ['assignment' => $this->a['overdue']->id, 'userid' => $this->u['s1']->id]
        );
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission(['userid' => $this->u['s1']->id,
            'cmid' => $cmid, 'onlinetext' => 'early']);
        (new local\index\indexer())->index_cm($cmid);

        $unit = $DB->get_record('block_my_assessments_mark', ['cmid' => $cmid, 'unitid' => $this->u['s1']->id]);
        $this->assertEquals($extension, $unit->unitdue);
        $this->assertGreaterThanOrEqual($extension, (int)$unit->timeready, 'ready no earlier than the deadline');
        $this->assertSame(',' . $this->u['marker']->id . ',', $DB->get_field(
            'block_my_assessments_stu',
            'allocatedto',
            ['cmid' => $cmid, 'userid' => $this->u['s1']->id]
        ));

        $row = self::rows((new staff($this->u['marker']->id))->export(), 'tomark')['Overdue essay'];
        $this->assertSame(1, $row['tomark'], 's1 waits for their extended deadline');
        $hints = array_column($row['hints'], null, 'key');
        $this->assertSame('1 student has an extension', $hints['extensions']['label']);

        $other = (new staff($this->u['other']->id))->export();
        $this->assertStringNotContainsString('has an extension', json_encode($other), 'not allocated to them');

        $row = self::rows((new convenor($this->u['convenor']->id))->export(), 'inprogress')['Overdue essay'];
        $this->assertContains('extensions', array_column($row['hints'], 'key'));
    }

    /**
     * "Preparing your assessments" shows only while the index has never been filled, or has
     * nothing for this view yet, not merely because a re-index is queued after an upgrade.
     */
    public function test_preparing(): void {
        set_config('backfillpending', time(), 'block_my_assessments');
        unset_config('backfilldone', 'block_my_assessments');
        $this->assertTrue((new student($this->u['s1']->id))->export()['freshness']['preparing']);
        set_config('backfilldone', time(), 'block_my_assessments');
        $this->assertFalse((new student($this->u['s1']->id))->export()['freshness']['preparing']);
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertTrue((new student($nobody->id))->export()['freshness']['preparing'], 'nothing indexed for them yet');
        unset_config('backfillpending', 'block_my_assessments');
        $this->assertFalse((new student($nobody->id))->export()['freshness']['preparing']);
    }

    /**
     * Convenor bars per feature.
     */
    public function test_convenor(): void {
        $export = (new convenor($this->u['convenor']->id))->export();
        $inprogress = self::rows($export, 'inprogress');
        $bars = array_column($inprogress['Overdue essay']['bars'], null, 'key');
        $this->assertArrayHasKey('submitted', $bars);
        $this->assertArrayHasKey('unallocated', $bars);
        $this->assertArrayHasKey('released', $bars);
        $upcoming = self::rows($export, 'upcoming');
        $bars = array_column($upcoming['Upcoming report']['bars'], null, 'key');
        $this->assertArrayNotHasKey('unallocated', $bars, 'only activities that allocate markers');
        $this->assertSame([], array_diff(array_keys($bars), ['submitted', 'unallocated']), 'no marking before the deadline');
        $this->assertSame(['inprogress', 'upcoming', 'past'], array_column($export['sections'], 'key'));
        $this->assertArrayHasKey('Discussion', $inprogress + self::rows($export, 'past'));
        $discussion = ($inprogress + self::rows($export, 'past'))['Discussion'];
        $this->assertNotContains('submitted', array_column($discussion['bars'], 'key'), 'generic: no submissions bar');
    }
}
