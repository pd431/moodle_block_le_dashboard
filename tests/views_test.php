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

use block_le_dashboard\local\index\marking_row;
use block_le_dashboard\local\view\convenor;
use block_le_dashboard\local\view\marking_rules;
use block_le_dashboard\local\view\staff;
use block_le_dashboard\local\view\student;

/**
 * The three views, end to end from the index.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\view\student
 * @covers     \block_le_dashboard\local\view\staff
 * @covers     \block_le_dashboard\local\view\convenor
 */
final class views_test extends \advanced_testcase {
    /** @var array Users by name. */
    protected array $u = [];
    /** @var array Activities by name. */
    protected array $a = [];

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        // Index writers observe events after commit, and some tests roll back a write: no test transaction.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $ma = $gen->get_plugin_generator('block_le_dashboard');
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

        // Everyone was on the module before the deadlines, so nobody is a late joiner (EC10).
        $DB->set_field('user_enrolments', 'timecreated', $now - 30 * DAYSECS);
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
        // No Upcoming section (Pierre, 2026-10-21): an early submission isn't marking until the deadline (G9).
        $this->assertSame(['tomark', 'completed'], array_column($marker['sections'], 'key'));
        $this->assertArrayNotHasKey('Upcoming report', $tomark, 'an early submission waits for the deadline (G9)');
        $this->assertArrayNotHasKey('Upcoming report', self::rows($marker, 'completed'));

        $other = (new staff($this->u['other']->id))->export();
        $this->assertArrayNotHasKey('Overdue essay', self::rows($other, 'tomark'));
        $this->assertArrayNotHasKey('Overdue essay', self::rows($other, 'completed'));
    }

    /**
     * Staff Undated rows name their course even in a course with no marking rows yet (R1, beta22 audit: the
     * course link was empty).
     */
    public function test_staff_undated_names_course(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'M2']);
        $gen->enrol_user($this->u['marker']->id, $course->id, 'teacher');
        $gen->enrol_user($this->u['s1']->id, $course->id, 'student');
        $cm = $gen->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id, 'name' => 'Essay 2',
            'duedate' => 0, 'assignsubmission_onlinetext_enabled' => 1]);
        $gen->get_plugin_generator('block_le_dashboard')->tag($cm->cmid, 'summative');

        $row = self::rows((new staff($this->u['marker']->id))->export(), 'undated')['Essay 2'];
        $this->assertSame('M2', $row['course']);
    }

    /**
     * G9: work counts at each student's own deadline, and extensions still to come are flagged
     * for the students whose marking is mine, and for the convenor.
     */
    public function test_extensions(): void {
        global $DB;
        $ma = $this->getDataGenerator()->get_plugin_generator('block_le_dashboard');
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

        $unit = $DB->get_record('block_le_dashboard_mark', ['cmid' => $cmid, 'unitid' => $this->u['s1']->id]);
        $this->assertEquals($extension, $unit->unitdue);
        $this->assertGreaterThanOrEqual($extension, (int)$unit->timeready, 'ready no earlier than the deadline');
        $this->assertSame(',' . $this->u['marker']->id . ',', $DB->get_field(
            'block_le_dashboard_stu',
            'allocatedto',
            ['cmid' => $cmid, 'userid' => $this->u['s1']->id]
        ));

        $row = self::rows((new staff($this->u['marker']->id))->export(), 'tomark')['Overdue essay'];
        $this->assertSame(1, $row['tomark'], 's1 waits for their extended deadline');
        $hints = array_column($row['hints'], null, 'key');
        $this->assertSame('1 student has an extension', $hints['extensions']['title']);
        $this->assertSame(1, $hints['extensions']['count']);

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
        set_config('backfillpending', time(), 'block_le_dashboard');
        unset_config('backfilldone', 'block_le_dashboard');
        $this->assertTrue((new student($this->u['s1']->id))->export()['freshness']['preparing']);
        set_config('backfilldone', time(), 'block_le_dashboard');
        $this->assertFalse((new student($this->u['s1']->id))->export()['freshness']['preparing']);
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertTrue((new student($nobody->id))->export()['freshness']['preparing'], 'nothing indexed for them yet');
        unset_config('backfillpending', 'block_le_dashboard');
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

    /**
     * Pierre, 2026-10-06 (beta14): every unit released means the assessment is finished, even when a
     * stage such as moderation only covered a sample. It goes to Past, with no "unmarked" call-out.
     */
    public function test_convenor_released_is_past(): void {
        global $DB;
        $cmid = $this->a['overdue']->cmid;
        $DB->set_field('block_le_dashboard_cm', 'markingdue', time() - DAYSECS, ['cmid' => $cmid]);
        $DB->set_field('block_le_dashboard_mark', 'marked', 1, ['cmid' => $cmid]);
        $DB->set_field('block_le_dashboard_mark', 'released', 1, ['cmid' => $cmid]);
        // A moderation stage that only sampled other students: this student's unit was never moderated.
        $row = $DB->get_record(
            'block_le_dashboard_mark',
            ['cmid' => $cmid, 'unitid' => $this->u['s2']->id],
            '*',
            IGNORE_MULTIPLE
        );
        unset($row->id);
        $row->stage = 'moderated';
        $row->marked = 0;
        $row->markedby = null;
        $row->timemarked = null;
        $DB->insert_record('block_le_dashboard_mark', $row);

        $export = (new convenor($this->u['convenor']->id))->export();
        $past = self::rows($export, 'past');
        $this->assertArrayHasKey('Overdue essay', $past);
        $this->assertEmpty($past['Overdue essay']['alert'] ?? null, 'nothing is outstanding once released');
        $marking = self::rows((new staff($this->u['marker']->id))->export(), 'completed');
        $this->assertArrayHasKey('Overdue essay', $marking);
    }

    /**
     * Pierre, 2026-10-06 (beta14): a course from another year shows "Course (2024/25)", not the raw placeholder.
     */
    public function test_course_in_other_year(): void {
        global $DB;
        $course = $DB->get_record('course', ['shortname' => 'M1']);
        $DB->set_field('course', 'startdate', mktime(0, 0, 0, 10, 1, 2024), ['id' => $course->id]);
        $method = new \ReflectionMethod(convenor::class, 'with_year');
        $text = $method->invoke(new convenor($this->u['convenor']->id), 'Module 1', (int)$course->id);
        $this->assertStringStartsWith('Module 1 (', $text);
        $this->assertStringNotContainsString('{$a', $text);
    }

    /**
     * PR3: names with "&" are escaped once, by the template, and search finds them.
     */
    public function test_names_escaped_once(): void {
        global $OUTPUT;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Law & Society', 'shortname' => 'L&S']);
        $gen->enrol_user($this->u['s1']->id, $course->id, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Essays & Reports',
            'duedate' => time() + DAYSECS]);
        $gen->get_plugin_generator('block_le_dashboard')->tag($a->cmid, 'summative');
        $export = (new student($this->u['s1']->id))->export();
        $row = self::rows($export, 'upcoming')['Essays & Reports'];
        $this->assertSame('Law & Society', $row['course']);
        $this->assertStringContainsString('essays & reports', $row['search']);
        $html = $OUTPUT->render_from_template('block_le_dashboard/view', $export);
        $this->assertStringContainsString('Essays &amp; Reports', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * PR4: Refresh finds a tagged assessment the index missed, for students and convenors.
     *
     * @covers \block_le_dashboard\external\refresh
     */
    public function test_refresh_finds_missed(): void {
        global $DB;
        $courseid = $this->a['overdue']->course;
        foreach (['s1' => 'student', 'convenor' => 'convenor'] as $user => $view) {
            $a = $this->getDataGenerator()->create_module('assign', ['course' => $courseid, 'name' => "Missed $view",
                'duedate' => time() + DAYSECS]);
            \core_tag_tag::set_item_tags('core', 'course_modules', $a->cmid, \context_module::instance($a->cmid), ['summative']);
            $DB->delete_records('task_adhoc', ['component' => 'block_le_dashboard']);
            $DB->delete_records('task_adhoc', ['classname' => '\\block_le_dashboard\\task\\reindex']);
            $this->assertFalse($DB->record_exists('block_le_dashboard_cm', ['cmid' => $a->cmid]));
            $this->setUser($this->u[$user]);
            \block_le_dashboard\external\refresh::execute($view);
            $this->assertTrue($DB->record_exists('block_le_dashboard_cm', ['cmid' => $a->cmid]), $view);
            $this->setAdminUser();
        }
    }

    /**
     * Deadline rush (P2): a staff Refresh over too many student rows is queued, however few the assessments.
     *
     * @covers \block_le_dashboard\external\refresh
     */
    public function test_refresh_queues_by_rows(): void {
        global $DB;
        $params = ['classname' => '\\block_le_dashboard\\task\\reindex'];
        $this->setUser($this->u['convenor']);
        $DB->delete_records('task_adhoc', $params);
        $this->assertSame('done', \block_le_dashboard\external\refresh::execute('convenor')['status']);
        $this->assertSame(0, $DB->count_records('task_adhoc', $params));

        set_config('inlinerefreshrows', 1, 'block_le_dashboard');
        unset_user_preference('block_le_dashboard_lastrefresh');
        $this->assertSame('queued', \block_le_dashboard\external\refresh::execute('convenor')['status']);
        $this->assertSame(1, $DB->count_records('task_adhoc', $params));
    }

    /**
     * Pierre's screenshot, 2026-10-09: a student's Refresh moves their "Updated" line on without
     * making the assessment look fully indexed to staff.
     */
    public function test_student_refresh_freshness(): void {
        global $DB;
        $DB->set_field('block_le_dashboard_cm', 'timeindexed', time() - 2 * DAYSECS);
        $before = $DB->get_records_menu('block_le_dashboard_cm', null, 'cmid', 'cmid, timeindexed');
        $justnow = get_string('updatedjustnow', 'block_le_dashboard');
        $student = $this->u['s1']->id;
        $this->assertNotEquals($justnow, (new student($student))->export()['freshness']['text']);

        $this->setUser($student);
        \block_le_dashboard\external\refresh::execute('student');
        $this->assertEquals($justnow, (new student($student))->export()['freshness']['text']);
        $this->assertEquals($before, $DB->get_records_menu('block_le_dashboard_cm', null, 'cmid', 'cmid, timeindexed'));
        $this->assertNotEquals($justnow, (new convenor($this->u['convenor']->id))->export()['freshness']['text']);

        // Another view's Refresh, or a preference in an old shape, doesn't count.
        set_user_preference('block_le_dashboard_lastrefresh', json_encode(['staff' => time()]), $student);
        $this->assertNotEquals($justnow, (new student($student))->export()['freshness']['text']);
        set_user_preference('block_le_dashboard_lastrefresh', (string)time(), $student);
        $this->assertNotEquals($justnow, (new student($student))->export()['freshness']['text']);
    }

    /**
     * PR7: "Refreshing" stops showing after ten minutes, so the button comes back.
     */
    public function test_refreshing_expires(): void {
        $userid = $this->u['convenor']->id;
        set_user_preference('block_le_dashboard_refreshing', time() - 30, $userid);
        $this->assertTrue((new convenor($userid))->export()['freshness']['refreshing']);
        set_user_preference('block_le_dashboard_refreshing', time() - 11 * MINSECS, $userid);
        $this->assertFalse((new convenor($userid))->export()['freshness']['refreshing']);
    }

    /**
     * F4: New work only when every unit marked before the last mark was done, and more arrived after it.
     */
    public function test_returned(): void {
        $due = 1000;
        $stage = fn(?int $firstready, int $lastmarked) => (object)['firstready' => $firstready, 'lastmarked' => $lastmarked];
        $this->assertTrue(marking_rules::returned([$stage(1600, 1500)], $due), 'work arrived after marking finished');
        $this->assertFalse(marking_rules::returned([$stage(1400, 1500)], $due), 'unmarked work older than the last mark');
        $this->assertFalse(marking_rules::returned([$stage(1600, 900)], $due), 'marked early, before the deadline');
        $this->assertFalse(marking_rules::returned([$stage(null, 1500)], $due), 'nothing left to mark');
        $this->assertTrue(marking_rules::returned([$stage(1600, 1500)], null), 'no deadline');
    }

    /**
     * A5: grades entered in the gradebook for students with nothing ready never push Released above the total.
     */
    public function test_released_never_exceeds_total(): void {
        global $DB;
        $cmid = $this->a['overdue']->cmid;
        $row = $DB->get_record(
            'block_le_dashboard_mark',
            ['cmid' => $cmid, 'unitid' => $this->u['s2']->id],
            '*',
            IGNORE_MULTIPLE
        );
        unset($row->id);
        $row->unitid = $this->u['s1']->id;
        $row->submitted = 0;
        $row->timeready = null;
        $row->marked = 1;
        $row->released = 1;
        $DB->insert_record('block_le_dashboard_mark', $row);

        $export = (new convenor($this->u['convenor']->id))->export();
        $rows = self::rows($export, 'inprogress') + self::rows($export, 'past');
        $bars = array_column($rows['Overdue essay']['bars'], null, 'key');
        $this->assertLessThanOrEqual($bars['released']['max'], $bars['released']['value']);
        $this->assertSame(0, $bars['released']['value']);
    }

    /**
     * No online submission: the student sees the icon until the date, then "Awaiting feedback", never
     * Overdue; staff see the flag.
     */
    public function test_no_submission(): void {
        global $DB;
        $cmid = $this->a['overdue']->cmid;
        $DB->set_field('block_le_dashboard_cm', 'nosubmission', 1, ['cmid' => $cmid]);
        $DB->set_field('block_le_dashboard_stu', 'submitted', null, ['cmid' => $cmid]);
        $export = (new student($this->u['s1']->id))->export();
        $past = self::rows($export, 'past');
        $this->assertSame('awaiting', $past['Overdue essay']['status']['key']);
        $this->assertSame([], $past['Overdue essay']['hints']);
        $marking = (new staff($this->u['marker']->id))->export();
        $all = [];
        foreach ($marking['sections'] as $section) {
            $all += array_column($section['rows'], null, 'name');
        }
        $this->assertContains('nosubmission', array_column($all['Overdue essay']['hints'], 'key'));

        $DB->set_field('block_le_dashboard_stu', 'due', time() + DAYSECS, ['cmid' => $cmid]);
        $upcoming = self::rows((new student($this->u['s1']->id))->export(), 'upcoming');
        $hint = $upcoming['Overdue essay']['hints'][0];
        $this->assertSame('nosubmission', $hint['key']);
        $this->assertTrue($hint['glyph']);
        $this->assertSame('No online submission', $hint['title']);
        $this->assertNotContains('submit', array_column($upcoming['Overdue essay']['actions'], 'key'));
    }

    /**
     * Module convenors see compatible activities nobody tagged; markers and students don't.
     */
    public function test_untagged_hint(): void {
        global $DB;
        $course = $DB->get_record('course', ['shortname' => 'M1']);
        $gen = $this->getDataGenerator();
        $plain = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Untagged essay']);
        $gen->create_module('page', ['course' => $course->id, 'name' => 'Reading']);
        $export = (new convenor($this->u['convenor']->id))->export();
        $this->assertNotNull($export['untagged']);
        $this->assertMatchesRegularExpression(
            '/^You have 1 compatible but untagged activities across your /',
            $export['untagged']['label']
        );
        $this->assertSame(['Untagged essay'], array_column($export['untagged']['courses'][0]['activities'], 'name'));
        $this->assertNull($export['untagged']['guidance'], 'no guidance by default');
        $this->assertArrayNotHasKey('untagged', (new staff($this->u['marker']->id))->export());

        // Tagged, it drops out once the cached list is forgotten (Refresh).
        \core_tag_tag::set_item_tags(
            'core',
            'course_modules',
            $plain->cmid,
            \context_module::instance($plain->cmid),
            ['formative']
        );
        \block_le_dashboard\local\untagged::forget($this->u['convenor']->id);
        $this->assertNull((new convenor($this->u['convenor']->id))->export()['untagged']);
    }

    /**
     * Item 8 (Pierre, 2026-10-09): from a day out, "Due in" counts calendar days to the date shown, in the user's
     * zone; under a day, the time left.
     *
     * @covers \block_le_dashboard\local\output\format::countdown
     */
    public function test_countdown(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Europe/London']));
        $at = fn(string $when, string $zone = 'Europe/London') => (new \DateTime($when, new \DateTimeZone($zone)))->getTimestamp();
        $note = fn(string $kind, string $due, string $now) =>
            \block_le_dashboard\local\output\format::countdown($kind, $at($due), $at($now))['text'];
        $this->assertSame('Due in 2 days', $note('duein', '2026-10-14 12:00', '2026-10-12 20:00'), 'not 1 day');
        $this->assertSame('Due tomorrow', $note('duein', '2026-10-13 12:00', '2026-10-12 08:00'));
        $this->assertSame('Due in 16 h', $note('duein', '2026-10-13 12:00', '2026-10-12 20:00'));
        $this->assertSame('Due in 45 min', $note('duein', '2026-10-12 12:00', '2026-10-12 11:15'));
        $this->assertSame('You can still submit until tomorrow', $note('stillsubmit', '2026-10-13 23:00', '2026-10-12 21:00'));
        $this->assertSame('You can still submit for 2 days', $note('stillsubmit', '2026-10-14 01:00', '2026-10-12 20:00'));
        // The user's zone decides the calendar: in Tokyo, 13 Oct 20:00 London is already 14 Oct.
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Asia/Tokyo']));
        $this->assertSame('Due tomorrow', $note('duein', '2026-10-15 12:00', '2026-10-13 20:00'));
        $countdown = \block_le_dashboard\local\output\format::countdown('duein', $at('2026-10-15 12:00'), $at('2026-10-13 20:00'));
        $this->assertSame('duein', $countdown['kind']);
        $this->assertSame($at('2026-10-15 12:00'), strtotime($countdown['until']));
        $this->assertSame($at('2026-10-13 20:00'), strtotime($countdown['now']));
    }

    /**
     * A sentence with a date in it, split around the date for the page to mark up.
     *
     * @covers \block_le_dashboard\local\output\format::dated
     */
    public function test_dated(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Europe/London']));
        $time = (new \DateTime('2026-10-21 12:00', new \DateTimeZone('Europe/London')))->getTimestamp();
        $dated = \block_le_dashboard\local\output\format::dated('hint_extensions_text', $time);
        $this->assertSame('Their work may still arrive. The latest extended deadline is 21 Oct 2026, 12:00.', $dated['text']);
        $this->assertSame('Their work may still arrive. The latest extended deadline is ', $dated['before']);
        $this->assertSame('21 Oct 2026, 12:00', $dated['date']);
        $this->assertSame('.', $dated['after']);
        $this->assertSame($time, strtotime($dated['iso']));
    }

    /**
     * CS3: withheld work is in no marking count, but stays in the academic conduct flag with its own sentence; a
     * marker whose only work there is withheld keeps the assessment under Completed marking, with no bars.
     */
    public function test_withheld_work(): void {
        global $DB;
        set_config('hint_plagiarism', 1, 'block_le_dashboard');
        $cmid = $this->a['overdue']->cmid;
        $this->assertSame(1, $DB->count_records('block_le_dashboard_mark', ['cmid' => $cmid]));
        $DB->set_field('block_le_dashboard_mark', 'flagged', marking_row::FLAG_WITHHELD, ['cmid' => $cmid]);
        $DB->set_field('block_le_dashboard_mark', 'counted', 0, ['cmid' => $cmid]);
        $withheld = get_string('hint_plagiarism_withheld_one', 'block_le_dashboard');

        $staff = (new staff($this->u['marker']->id))->export();
        $this->assertArrayNotHasKey('Overdue essay', self::rows($staff, 'tomark'));
        $row = self::rows($staff, 'completed')['Overdue essay'];
        $hint = array_column($row['hints'], null, 'key')['plagiarism'];
        $this->assertSame($withheld, $hint['text']);
        $this->assertSame('1 flagged', $hint['title']);
        $this->assertSame([], $row['bars'] ?? []);

        $convenor = (new convenor($this->u['convenor']->id))->export();
        $rows = [];
        foreach ($convenor['sections'] as $section) {
            $rows += array_column($section['rows'], null, 'name');
        }
        $hint = array_column($rows['Overdue essay']['hints'], null, 'key')['plagiarism'];
        $this->assertSame($withheld, $hint['text']);

        // With one under investigation as well, both sentences, the open one first, over one count.
        $hint = \block_le_dashboard\local\output\format::plagiarism_hint('r', 2, 1);
        $this->assertSame('3 flagged', $hint['title']);
        $this->assertSame(get_string('hint_plagiarism_text_many', 'block_le_dashboard', 2) . ' ' . $withheld, $hint['text']);
    }

    /**
     * Beta27 hints audit: academic conduct flags are off on a new site, and off means their query isn't run either;
     * on, the marker and the convenor both see the flag.
     */
    public function test_plagiarism_switch(): void {
        global $DB;
        $DB->set_field('block_le_dashboard_mark', 'flagged', 1, ['cmid' => $this->a['overdue']->cmid]);
        $hints = fn(array $export, string $section) => array_column(self::rows($export, $section)['Overdue essay']['hints'], 'key');
        $views = [
            'tomark' => fn() => new staff($this->u['marker']->id),
            'inprogress' => fn() => new convenor($this->u['convenor']->id),
        ];

        $reads = [];
        foreach ($views as $section => $view) {
            // Once first, so caches are warm for both counts.
            $view()->export();
            $before = $DB->perf_get_reads();
            $this->assertNotContains('plagiarism', $hints($view()->export(), $section));
            $reads[$section] = $DB->perf_get_reads() - $before;
        }
        set_config('hint_plagiarism', 1, 'block_le_dashboard');
        foreach ($views as $section => $view) {
            $before = $DB->perf_get_reads();
            $this->assertContains('plagiarism', $hints($view()->export(), $section));
            $this->assertGreaterThan($reads[$section], $DB->perf_get_reads() - $before, "$section: the query runs only when on");
        }
    }

    /**
     * Beta27 regression: work with several markers and no allocation (coursework double marking, or an assignment
     * with 2 markers) is shared between the markers, and counts in Marking due soon for each one still to mark. In
     * beta27 building that count was a PHP error, so these markers' My marking didn't load at all.
     */
    public function test_shared_marking_due_soon(): void {
        global $DB;
        $cmid = $this->a['overdue']->cmid;
        $due = (int)$DB->get_field('block_le_dashboard_cm', 'due', ['cmid' => $cmid]);
        $DB->update_record('block_le_dashboard_cm', (object)[
            'id' => $DB->get_field('block_le_dashboard_cm', 'id', ['cmid' => $cmid]),
            'hasallocation' => 0, 'stages' => 'marker1,marker2', 'markingdue' => null]);
        $row = $DB->get_record(
            'block_le_dashboard_mark',
            ['cmid' => $cmid, 'unitid' => $this->u['s2']->id],
            '*',
            IGNORE_MULTIPLE
        );
        $DB->delete_records('block_le_dashboard_mark', ['cmid' => $cmid]);
        unset($row->id);
        foreach (['marker1', 'marker2'] as $stage) {
            $DB->insert_record('block_le_dashboard_mark', (object)(['stage' => $stage, 'allocatedto' => null, 'marked' => 0,
                'markedby' => null, 'timemarked' => null, 'released' => 0, 'submitted' => 1, 'unitdue' => $due] + (array)$row));
        }

        // The default marking period (12 days) puts the deadline 11 days ahead.
        foreach (['marker', 'other'] as $name) {
            $export = (new staff($this->u[$name]->id))->export();
            $this->assertSame(1, self::rows($export, 'tomark')['Overdue essay']['tomark'], $name);
            $box = array_column($export['summary'], null, 'key')['duesoon'];
            $counts = json_decode($box['counts'], true);
            $this->assertSame(1, $counts['15']['both'], "$name: within 15 days");
            $this->assertSame(0, $counts['7']['both'], "$name: not within 7 days");
        }
    }
}
