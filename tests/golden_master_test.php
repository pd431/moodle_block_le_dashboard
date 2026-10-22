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
use block_le_dashboard\local\view\convenor;
use block_le_dashboard\local\view\staff;
use block_le_dashboard\local\view\student;

/**
 * The golden master: what the three views, and the index behind them, hold for one course at a fixed time.
 *
 * The course has one assessment of each kind of row the views draw (due soon, overdue, extended, marked, released,
 * undated, not yet open, attempts used up, hidden, no grade, in person, generic, untagged, shared marking,
 * flagged). Each view is exported for each person at a fixed clock and compared, with ids and URLs normalised,
 * to tests/fixtures/golden_master.json. A cleanup build (beta29) must leave the fixture unchanged.
 *
 * To record the fixture again after a change that is meant to show: GOLDEN_MASTER_WRITE=1 vendor/bin/phpunit ...
 * and commit the new fixture with the change that explains it.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\view\student
 * @covers     \block_le_dashboard\local\view\staff
 * @covers     \block_le_dashboard\local\view\convenor
 * @covers     \block_le_dashboard\local\index\indexer
 */
final class golden_master_test extends \advanced_testcase {
    /** The fixed clock: 1 October 2026, 10:00 UTC. Every date in the course is set from it. */
    const NOW = 1790848800;

    /** The fixture. */
    const FIXTURE = __DIR__ . '/fixtures/golden_master.json';

    /** @var \stdClass[] Users by name. */
    protected array $users = [];

    /** @var \stdClass[] Activities by name: cmid, instance, modname. */
    protected array $cms = [];

    /** @var \stdClass The course. */
    protected \stdClass $course;

    /**
     * A time relative to the fixed clock.
     *
     * @param float $days
     * @return int
     */
    protected static function days(float $days): int {
        return (int)(self::NOW + $days * DAYSECS);
    }

    /**
     * Every view, for everyone, matches the fixture.
     */
    public function test_views_match_the_fixture(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->seed();

        $actual = $this->snapshot();
        if (getenv('GOLDEN_MASTER_WRITE')) {
            file_put_contents(self::FIXTURE, json_encode(
                $actual,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . "\n");
        }
        $this->assertFileExists(self::FIXTURE);
        $expected = json_decode(file_get_contents(self::FIXTURE), true);
        foreach ($actual as $key => $value) {
            $this->assertSame($expected[$key] ?? null, $value, "$key differs from the golden master");
        }
        $this->assertSame(array_keys($expected), array_keys($actual));
    }

    /**
     * Builds the course, as the fixture describes it.
     */
    protected function seed(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $ma = $gen->get_plugin_generator('block_le_dashboard');
        $agen = $gen->get_plugin_generator('mod_assign');
        $qgen = $gen->get_plugin_generator('mod_quiz');
        $convenorrole = $ma->configure(['assign', 'quiz', 'forum']);
        set_config('inpersontags', 'inperson', 'block_le_dashboard');
        set_config('feedbackdatemode', 'asset', 'block_le_dashboard');
        foreach (array_keys(local\config::HINTS) as $name) {
            set_config($name, 1, 'block_le_dashboard');
        }
        $this->course = $gen->create_course(['shortname' => 'GM1', 'fullname' => 'Golden Master module']);
        $courseid = $this->course->id;

        $people = [
            's1' => ['Sam', 'Student', 'student'],
            's2' => ['Sal', 'Student', 'student'],
            's3' => ['Sid', 'Student', 'student'],
            'marker1' => ['Mark', 'One', 'teacher'],
            'marker2' => ['Mark', 'Two', 'teacher'],
            'convenor' => ['Con', 'Venor', $convenorrole],
        ];
        foreach ($people as $name => [$first, $last, $role]) {
            $this->users[$name] = $gen->create_user(['username' => $name, 'firstname' => $first, 'lastname' => $last,
                'email' => "$name@example.com", 'timezone' => 'Europe/London', 'maildisplay' => 1]);
            $gen->enrol_user(
                $this->users[$name]->id,
                $courseid,
                $role,
                'manual',
                0,
                0,
                $name === 's3' ? ENROL_USER_SUSPENDED : ENROL_USER_ACTIVE
            );
        }
        $DB->set_field('user_enrolments', 'timecreated', self::days(-60));
        $DB->set_field('user_enrolments', 'timestart', 0);

        $assign = function (string $name, array $fields = []) use ($agen, $courseid): \stdClass {
            $a = $agen->create_instance($fields + ['course' => $courseid, 'name' => $name, 'intro' => 'The brief.',
                'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0]);
            return $this->cms[$name] = (object)['cmid' => (int)$a->cmid, 'instance' => (int)$a->id, 'modname' => 'assign',
                'record' => $a];
        };
        $quiz = function (string $name, array $fields = []) use ($qgen, $courseid): \stdClass {
            $q = $qgen->create_instance($fields + ['course' => $courseid, 'name' => $name, 'intro' => 'The brief.',
                'sumgrades' => 1, 'grade' => 100]);
            $cat = $this->getDataGenerator()->get_plugin_generator('core_question')
                ->create_question_category(['contextid' => \context_module::instance($q->cmid)->id]);
            $question = $this->getDataGenerator()->get_plugin_generator('core_question')
                ->create_question('shortanswer', null, ['category' => $cat->id]);
            quiz_add_quiz_question($question->id, $q);
            return $this->cms[$name] = (object)['cmid' => (int)$q->cmid, 'instance' => (int)$q->id, 'modname' => 'quiz',
                'record' => $q];
        };

        // Due soon, no brief: s1 handed in early, s2 hasn't.
        $assign('Essay due soon', ['duedate' => self::days(3), 'cutoffdate' => self::days(5)]);
        // The generator fills an empty intro, so the brief goes after the fact.
        $DB->set_field('assign', 'intro', '', ['id' => $this->cms['Essay due soon']->instance]);
        // Overdue with allocation: s1 late and allocated to marker1, s2 extended and unallocated, s3 (suspended)
        // on time and unallocated: the convenor's.
        $assign('Report overdue', ['duedate' => self::days(-3), 'gradingduedate' => self::days(10), 'markingworkflow' => 1,
            'markingallocation' => 1]);
        // Past its marking deadline: s1 marked by marker1, s2 allocated to marker2 and not marked.
        $assign('Report in marking', ['duedate' => self::days(-10), 'gradingduedate' => self::days(-2), 'markingworkflow' => 1,
            'markingallocation' => 1]);
        // Shared, no allocation: s1 marked and released by marker1, s2 arrived after that mark (returned), flagged.
        $assign('Essay shared', ['duedate' => self::days(-4)]);
        // Undated.
        $assign('Undated essay', ['duedate' => 0]);
        // Hidden from students.
        $assign('Hidden essay', ['duedate' => self::days(6), 'visible' => 0]);
        // Summative without a grade.
        $assign('Essay no grade', ['duedate' => self::days(8), 'grade' => 0]);
        // In person.
        $assign('In-person exam', ['duedate' => self::days(1)]);
        // Untagged.
        $assign('Untagged essay', ['duedate' => self::days(4)]);
        // Closed and auto-marked.
        $quiz('Quiz released', ['timeclose' => self::days(-20), 'attempts' => 1]);
        // Open, one attempt: s2 abandoned theirs.
        $quiz('Quiz open', ['timeopen' => self::days(-1), 'timeclose' => self::days(4), 'attempts' => 1]);
        // Not yet open.
        $quiz('Quiz not yet open', ['timeopen' => self::days(2), 'timeclose' => self::days(9)]);
        // Generic.
        $forum = $gen->create_module('forum', ['course' => $courseid, 'name' => 'Discussion', 'duedate' => self::days(-1)]);
        $this->cms['Discussion'] = (object)['cmid' => (int)$forum->cmid, 'instance' => (int)$forum->id, 'modname' => 'forum'];

        // What happened, in the activities' own tables.
        $this->submit_assign('Essay due soon', 's1', self::days(-1));
        $this->submit_assign('Report overdue', 's1', self::days(-2));
        $this->submit_assign('Report overdue', 's2', self::days(-1));
        $this->submit_assign('Report overdue', 's3', self::days(-4));
        $ma->allocate_assign($this->cms['Report overdue']->cmid, $this->users['s1']->id, $this->users['marker1']->id);
        $flags = (new \assign(\context_module::instance($this->cms['Report overdue']->cmid), null, null))
            ->get_user_flags($this->users['s2']->id, true);
        $flags->extensionduedate = self::days(2);
        $DB->update_record('assign_user_flags', $flags);
        $this->submit_assign('Report in marking', 's1', self::days(-11));
        $this->submit_assign('Report in marking', 's2', self::days(-9));
        $ma->allocate_assign($this->cms['Report in marking']->cmid, $this->users['s1']->id, $this->users['marker1']->id);
        $ma->allocate_assign($this->cms['Report in marking']->cmid, $this->users['s2']->id, $this->users['marker2']->id);
        $this->mark_assign('Report in marking', 's1', 'marker1', self::days(-8), ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->submit_assign('Essay shared', 's1', self::days(-5));
        $this->mark_assign('Essay shared', 's1', 'marker1', self::days(-3));
        $this->submit_assign('Essay shared', 's2', self::days(-2));
        $this->submit_assign('Undated essay', 's1', self::days(-6));
        $this->attempt_quiz('Quiz released', 's1', self::days(-21), 'finished');
        $this->attempt_quiz('Quiz open', 's2', self::days(-0.5), 'abandoned');

        // Tagged, which indexes each one.
        $tags = ['Essay due soon' => 'summative', 'Report overdue' => 'summative', 'Report in marking' => 'summative',
            'Essay shared' => 'summative', 'Undated essay' => 'summative', 'Hidden essay' => 'formative',
            'Essay no grade' => 'summative', 'In-person exam' => 'summative', 'Quiz released' => 'summative',
            'Quiz open' => 'formative', 'Quiz not yet open' => 'formative', 'Discussion' => 'summative'];
        foreach ($tags as $name => $type) {
            $ma->tag($this->cms[$name]->cmid, $type);
        }
        $cmid = $this->cms['In-person exam']->cmid;
        \core_tag_tag::set_item_tags('core', 'course_modules', $cmid, \context_module::instance($cmid), ['summative', 'inperson']);
        // Indexed again at the fixed clock, now that every timestamp is set.
        $indexer = new indexer(self::NOW);
        foreach ($tags as $name => $type) {
            $indexer->index_cm($this->cms[$name]->cmid);
        }
        // Flagged for an academic conduct check: the index knows, whatever the activity's own tables (no assign table).
        $DB->set_field('block_le_dashboard_mark', 'flagged', 1, ['cmid' => $this->cms['Essay shared']->cmid,
            'unitid' => $this->users['s2']->id]);
    }

    /**
     * Hands an assignment in at a time.
     *
     * @param string $name
     * @param string $user
     * @param int $time
     */
    protected function submit_assign(string $name, string $user, int $time): void {
        global $DB;
        $cm = $this->cms[$name];
        $userid = $this->users[$user]->id;
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission(['userid' => $userid,
            'cmid' => $cm->cmid, 'onlinetext' => 'My work']);
        $DB->set_field('assign_submission', 'status', 'submitted', ['assignment' => $cm->instance, 'userid' => $userid]);
        $DB->set_field('assign_submission', 'timecreated', $time, ['assignment' => $cm->instance, 'userid' => $userid]);
        $DB->set_field('assign_submission', 'timemodified', $time, ['assignment' => $cm->instance, 'userid' => $userid]);
    }

    /**
     * Marks an assignment at a time, with the grade's workflow state where the assignment has one.
     *
     * @param string $name
     * @param string $user
     * @param string $marker
     * @param int $time
     * @param string|null $state
     */
    protected function mark_assign(string $name, string $user, string $marker, int $time, ?string $state = null): void {
        global $DB;
        $cm = $this->cms[$name];
        $userid = $this->users[$user]->id;
        $assign = new \assign(\context_module::instance($cm->cmid), null, null);
        $grade = $assign->get_user_grade($userid, true);
        $grade->grade = 60;
        $grade->grader = $this->users[$marker]->id;
        $assign->update_grade($grade);
        if ($state !== null) {
            $flags = $assign->get_user_flags($userid, true);
            $flags->workflowstate = $state;
            $assign->update_user_flags($flags);
        }
        $params = ['assignment' => $cm->instance, 'userid' => $userid];
        $DB->set_field('assign_grades', 'timecreated', $time, $params);
        $DB->set_field('assign_grades', 'timemodified', $time, $params);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $this->course->id]);
        $DB->execute(
            "UPDATE {grade_grades} SET timecreated = :t1, timemodified = :t2 WHERE itemid = :itemid AND userid = :userid",
            ['t1' => $time, 't2' => $time, 'itemid' => $item->id, 'userid' => $userid]
        );
    }

    /**
     * A quiz attempt, finished or abandoned, at a time.
     *
     * @param string $name
     * @param string $user
     * @param int $time
     * @param string $state finished or abandoned
     */
    protected function attempt_quiz(string $name, string $user, int $time, string $state): void {
        global $DB, $USER;
        $cm = $this->cms[$name];
        $userid = $this->users[$user]->id;
        $qgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $saved = $USER;
        \core\session\manager::set_user(\core_user::get_user($userid));
        $attempt = $qgen->create_attempt($cm->instance, $userid);
        if ($state === 'finished') {
            $qgen->submit_responses($attempt->id, [1 => 'answer'], false, true);
        }
        \core\session\manager::set_user($saved);
        $record = ['id' => $attempt->id, 'timestart' => $time - HOURSECS, 'timemodified' => $time];
        if ($state === 'finished') {
            $record['timefinish'] = $time;
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $cm->instance,
                'itemnumber' => 0, 'courseid' => $this->course->id]);
            $DB->execute(
                "UPDATE {grade_grades} SET timecreated = :t1, timemodified = :t2 WHERE itemid = :itemid AND userid = :userid",
                ['t1' => $time, 't2' => $time, 'itemid' => $item->id, 'userid' => $userid]
            );
            $DB->execute(
                "UPDATE {question_attempt_steps} SET timecreated = :t WHERE questionattemptid IN
                            (SELECT id FROM {question_attempts} WHERE questionusageid = :quba)",
                ['t' => $time, 'quba' => $attempt->uniqueid]
            );
        } else {
            $record += ['state' => 'abandoned', 'timefinish' => 0];
        }
        $DB->update_record('quiz_attempts', (object)$record);
    }

    /**
     * Every view for every person, and the index rows, normalised.
     *
     * @return array
     */
    protected function snapshot(): array {
        global $DB;
        $out = [];
        foreach (['s1', 's2', 's3'] as $name) {
            $out["student:$name"] = (new student($this->users[$name]->id, self::NOW))->export();
        }
        foreach (['marker1', 'marker2', 'convenor'] as $name) {
            $out["staff:$name"] = (new staff($this->users[$name]->id, self::NOW, 0))->export();
        }
        $out['convenor:convenor'] = (new convenor($this->users['convenor']->id, self::NOW, 0))->export();

        $index = [];
        $courseid = $this->course->id;
        $cms = $DB->get_records('block_le_dashboard_cm', ['courseid' => $courseid], 'name');
        foreach ($cms as $cm) {
            $index[$cm->name] = ['cm' => $this->index_row($cm, ['id', 'cmid', 'courseid', 'name'])];
            $index[$cm->name]['students'] = array_values(array_map(
                fn($r) => $this->index_row($r, ['id', 'cmid']),
                $DB->get_records('block_le_dashboard_stu', ['cmid' => $cm->cmid], 'userid')
            ));
            $index[$cm->name]['marking'] = array_values(array_map(
                fn($r) => $this->index_row($r, ['id', 'cmid']),
                $DB->get_records('block_le_dashboard_mark', ['cmid' => $cm->cmid], 'unittype, unitid, stage')
            ));
        }
        $out['index'] = $index;
        return json_decode(json_encode($this->normalise($out)), true);
    }

    /**
     * An index row with people named and columns of no interest dropped.
     *
     * @param \stdClass $r
     * @param string[] $drop
     * @return array
     */
    protected function index_row(\stdClass $r, array $drop): array {
        $row = array_diff_key((array)$r, array_flip($drop));
        foreach (['userid', 'unitid', 'allocatedto', 'markedby'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = $this->normalise($row[$field]);
            }
        }
        return $row;
    }

    /**
     * Ids and URLs replaced by names, and what varies by run or site by placeholders.
     *
     * @param mixed $value
     * @param string|null $key The key the value sits under.
     * @return mixed
     */
    protected function normalise($value, ?string $key = null) {
        global $CFG;
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalise($v, is_string($k) ? $k : $key);
            }
            // Keys in one order, so the fixture pins what a row holds, not the order its builder set it in.
            if (!array_is_list($out)) {
                ksort($out);
            }
            return $out;
        }
        if ($key === 'uniqid' || $key === 'selectclass') {
            return "<$key>";
        }
        if ($key === 'icon' && is_string($value)) {
            // A glyph Font Awesome 6 has and 4.5's Font Awesome lacks is the block's own on 4.5 (format::hint_icon()).
            return preg_replace('~^ma-icon-~', 'fa-', $value);
        }
        if ($key === 'cmid' && is_numeric($value)) {
            return $this->cm_name((int)$value);
        }
        if (in_array($key, ['userid', 'unitid', 'allocatedto', 'markedby'], true) && is_numeric($value)) {
            return $this->user_name((int)$value);
        }
        if (in_array($key, ['userid', 'unitid', 'allocatedto', 'markedby'], true) && is_string($value) && $value !== '') {
            // The student index lists allocated markers as ",id,id,".
            return implode(',', array_map(fn($id) => $id === '' ? '' : $this->user_name((int)$id), explode(',', $value)));
        }
        if (!is_string($value)) {
            return $value;
        }
        $value = str_replace($CFG->wwwroot, '<wwwroot>', $value);
        $cm = fn(array $m) => $this->cm_name((int)$m[2]);
        $value = preg_replace_callback('~(/mod/\w+/view\.php\?id=)(\d+)~', fn($m) => $m[1] . $cm($m), $value);
        $value = preg_replace_callback('~(/course/modedit\.php\?update=)(\d+)~', fn($m) => $m[1] . $cm($m), $value);
        $value = preg_replace_callback('~(ma-(?:student|staff|convenor)-)(\d+)~', fn($m) => $m[1] . $cm($m), $value);
        $value = preg_replace_callback(
            '~(/course/view\.php\?id=)(\d+)~',
            fn($m) => $m[1] . ((int)$m[2] === (int)$this->course->id ? '{course}' : $m[2]),
            $value
        );
        // The activity icon URL carries the theme's revision; the icon itself is the course page's.
        $value = preg_replace(
            '~<wwwroot>/theme/image\.php/[^/]+/(\w+)/-?\d+/monologo~',
            '<wwwroot>/theme/image.php/{theme}/$1/{rev}/monologo',
            $value
        );
        return $value;
    }

    /**
     * An activity's name in place of its cmid.
     *
     * @param int $cmid
     * @return string
     */
    protected function cm_name(int $cmid): string {
        foreach ($this->cms as $name => $cm) {
            if ($cm->cmid === $cmid) {
                return '{' . $name . '}';
            }
        }
        return (string)$cmid;
    }

    /**
     * A person's name in place of their id.
     *
     * @param int $userid
     * @return string
     */
    protected function user_name(int $userid): string {
        foreach ($this->users as $name => $user) {
            if ((int)$user->id === $userid) {
                return '{' . $name . '}';
            }
        }
        return (string)$userid;
    }
}
