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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

// Step definitions are single-line regular expressions, so some lines are long.
// phpcs:disable moodle.Files.LineLength.TooLong

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use block_le_dashboard\local\index\indexer;
use block_le_dashboard\local\years;

/**
 * Steps for the My assessments scope (logic-overhaul/scope/*.feature).
 *
 * Test data is created through each plugin's own API where it has one. Where a step sets data
 * directly, it queues the re-index the plugin's own event would have queued, so "view my
 * dashboard" (which runs pending index tasks first) sees it.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_le_dashboard extends behat_base {
    /** Block selector. */
    const BLOCK = '.block_le_dashboard';

    /** @var bool Ad hoc tasks don't run on this site (index-passes.feature). */
    protected $noadhoc = false;

    /** @var int[] The courses the last index run did. */
    protected $lastrun = [];

    /** @var int[] The courses the nightly check has done in this scenario. */
    protected $nightlydone = [];

    /** @var int When the hourly index task last ran in this scenario. */
    protected $lasthourly = 0;

    /** Module tables searched when looking up an activity by name. */
    const MODULES = ['assign', 'quiz', 'peerwork', 'coursework', 'workshop', 'forum', 'page', 'lti', 'attendance'];

    /**
     * The module convenor role is needed before Background enrolments refer to it.
     *
     * @BeforeScenario @block_le_dashboard
     */
    public function create_convenor_role(): void {
        global $DB;
        // Ids restart with each scenario, so nothing memoised in this process still holds.
        \block_le_dashboard\local\memo::reset();
        if (!$DB->record_exists('role', ['shortname' => 'moduleconvenor'])) {
            $roleid = create_role('Module convenor', 'moduleconvenor', '', 'editingteacher');
            set_role_contextlevels($roleid, [CONTEXT_COURSE]);
            role_cap_duplicate($DB->get_record('role', ['shortname' => 'editingteacher']), $roleid);
        }
    }

    /**
     * Scenarios tagged @block_rhb need its Mitigate links: without block_rhb, a stand-in
     * gives one (tests/fixtures/rhb_stub.php).
     *
     * @BeforeScenario @block_rhb
     */
    public function stand_in_for_block_rhb(): void {
        global $CFG;
        if (!file_exists($CFG->dirroot . '/blocks/rhb/lib.php')) {
            set_config('behatrhbstub', 1, 'block_le_dashboard');
        }
    }

    // Helpers.

    /**
     * A time from words: now, tomorrow, yesterday, "3 days ago", "in 10 days", "in 1 minute".
     *
     * @param string $text
     * @return int
     */
    protected static function time(string $text): int {
        // An absolute time may name its zone ("12 October 2026 10:00 Europe/London"), which strtotime() reads
        // only as written.
        $absolute = trim($text);
        $text = strtolower($absolute);
        $map = ['now' => 0, 'tomorrow' => DAYSECS, 'yesterday' => -DAYSECS];
        if (isset($map[$text])) {
            return time() + $map[$text];
        }
        if (preg_match('/^in (\d+) (minute|hour|day|week)s?$/', $text, $m)) {
            return strtotime("+{$m[1]} {$m[2]}");
        }
        if (preg_match('/^(\d+) (minute|hour|day|week)s? ago$/', $text, $m)) {
            return strtotime("-{$m[1]} {$m[2]}");
        }
        $time = strtotime($absolute);
        if ($time === false) {
            throw new coding_exception("Unknown time '$absolute'");
        }
        return $time;
    }

    /**
     * Skips the scenario when a module it needs is not installed.
     *
     * @param string $modname
     */
    protected static function require_module(string $modname): void {
        if (!core_component::get_component_directory('mod_' . $modname)) {
            throw new \Moodle\BehatExtension\Exception\SkippedException("mod_$modname is not installed");
        }
    }

    /**
     * An activity by name.
     *
     * @param string $name
     * @return cm_info
     */
    protected function cm(string $name): cm_info {
        global $DB;
        foreach (self::MODULES as $modname) {
            if (!core_component::get_component_directory('mod_' . $modname)) {
                continue;
            }
            if ($instance = $DB->get_record($modname, ['name' => $name], 'id, course', IGNORE_MULTIPLE)) {
                $cm = get_coursemodule_from_instance($modname, $instance->id, $instance->course, false, MUST_EXIST);
                return get_fast_modinfo($cm->course)->get_cm($cm->id);
            }
        }
        throw new coding_exception("No activity named '$name'");
    }

    /**
     * A user id by username.
     *
     * @param string $username
     * @return int
     */
    protected static function userid(string $username): int {
        global $DB;
        return (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
    }

    /**
     * A course by short name.
     *
     * @param string $shortname
     * @return stdClass
     */
    protected static function course(string $shortname): stdClass {
        global $DB;
        return $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
    }

    /**
     * Queues the re-index that the plugin's own event would have queued.
     *
     * @param cm_info|int $cm
     */
    protected static function queue($cm): void {
        indexer::queue_cm(is_object($cm) ? (int)$cm->id : (int)$cm);
    }

    /**
     * Runs pending index tasks, including those held back to coalesce a rush of submissions: the scenario
     * means the time has passed.
     */
    protected function catch_up(): void {
        global $DB;
        if ($this->noadhoc) {
            return;
        }
        $due = time() - 1;
        $DB->set_field_select('task_adhoc', 'nextruntime', $due, "component = 'block_le_dashboard' AND nextruntime > $due");
        $this->execute('behat_general::i_run_all_adhoc_tasks');
    }

    /**
     * The block's row for an activity.
     *
     * @param string $name
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function row(string $name): \Behat\Mink\Element\NodeElement {
        $xpath = "//*[contains(@class, 'block_le_dashboard')]" .
            "//li[@data-region='ma-row'][.//a[contains(@class, 'ma-row-name')][normalize-space(.) = " .
            behat_context_helper::escape($name) . "]]";
        return $this->find('xpath', $xpath, new ExpectationException("No row for '$name'", $this->getSession()));
    }

    /**
     * A row's progress bar.
     *
     * @param string $name
     * @param string $label
     * @return \Behat\Mink\Element\NodeElement|null
     */
    protected function bar(string $name, string $label): ?\Behat\Mink\Element\NodeElement {
        // On the row, or first under Show marking on a finished row.
        $match = "[span[contains(@class, 'ma-bar-label')][normalize-space(.) = " . behat_context_helper::escape($label) . "]]";
        $xpath = "./div[contains(@class, 'ma-row-status')]/div[@data-bar]$match" .
            " | ./ul[contains(@class, 'ma-markers')]/li[contains(@class, 'ma-marker--all')]//div[@data-bar]$match";
        return $this->row($name)->find('xpath', $xpath);
    }

    /**
     * Adds a question an attempt can be made at (an essay, which needs manual grading).
     *
     * @param stdClass $quiz
     */
    protected static function add_essay(stdClass $quiz): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $qgen = testing_util::get_data_generator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => context_module::instance($quiz->cmid)->id]);
        $question = $qgen->create_question('essay', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);
    }

    // Set-up steps.

    /**
     * Configures the block's tags and roles, and puts it on the default dashboard.
     *
     * @Given /^the My assessments block is configured with summative tag "(?P<s>[^"]*)" and formative tag "(?P<f>[^"]*)"$/
     * @param string $summative
     * @param string $formative
     */
    public function the_block_is_configured(string $summative, string $formative): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/my/lib.php');
        $types = ['assign', 'quiz', 'peerwork', 'coursework'];
        testing_util::get_data_generator()->get_plugin_generator('block_le_dashboard')->configure($types);
        set_config('certaintags', $summative, 'block_le_dashboard');
        set_config('formativetags', $formative, 'block_le_dashboard');
        unset_config('backfillpending', 'block_le_dashboard');
        $page = my_get_page(null, MY_PAGE_PRIVATE);
        if (!$DB->record_exists('block_instances', ['blockname' => 'le_dashboard', 'subpagepattern' => $page->id])) {
            $DB->insert_record('block_instances', ['blockname' => 'le_dashboard',
                'parentcontextid' => context_system::instance()->id, 'showinsubcontexts' => 0, 'pagetypepattern' => 'my-index',
                'subpagepattern' => $page->id, 'defaultregion' => 'content', 'defaultweight' => -10, 'configdata' => '',
                'timecreated' => time(), 'timemodified' => time()]);
        }
        my_reset_page_for_all_users(MY_PAGE_PRIVATE, 'my-index');
    }

    /**
     * Creates an academic year and makes it the default.
     *
     * @Given /^the academic year "(?P<label>[^"]*)" starts on "(?P<date>[^"]*)"$/
     * @param string $label
     * @param string $date
     */
    public function the_academic_year_starts_on(string $label, string $date): void {
        global $DB;
        // A date, or a timestamp when written as ##date##.
        $start = ctype_digit($date) ? (int)$date : strtotime($date . ' 00:00');
        $DB->delete_records(years::TABLE, ['label' => $label]);
        $id = $DB->insert_record(years::TABLE, ['label' => $label, 'timestart' => $start,
            'timeend' => strtotime('+1 year', $start), 'timecreated' => time(), 'timemodified' => time()]);
        set_config('defaultacademicyear', $id, 'block_le_dashboard');
    }

    /**
     * Creates and tags an assessment.
     *
     * Options after "with": marker allocation, a marking deadline "...", an essay question,
     * grading method "First attempt|Last attempt|Highest grade", 2 markers, moderation,
     * marking workflow, reopened attempts, a cut-off "...", manual agreement, tagged "..." (a second tag),
     * N attempts allowed, marks never shown, marks shown immediately after only, marks shown after
     * close only, a maximum grade of 0 (quiz), submission type attendance (coursework), required Submit
     * (assign: students must click Submit, which makes the submission final). Also: opens "..." and (when
     * submissions open).
     *
     * @Given /^an? (?P<tag>summative|formative|"[^"]+") "(?P<mod>\w+)" "(?P<name>[^"]*)" in "(?P<course>[^"]*)"(?P<opts>.*?) (?:is|was) due "(?P<due>[^"]*)"$/
     * @param string $tag
     * @param string $modname
     * @param string $name
     * @param string $course
     * @param string $opts
     * @param string $due
     */
    public function an_assessment_is_due(
        string $tag,
        string $modname,
        string $name,
        string $course,
        string $opts,
        string $due
    ): void {
        // A quoted tag is any tag, such as one the settings don't list yet.
        $this->create_assessment(trim($tag, '"'), $modname, $name, $course, $opts, self::time($due));
    }

    /**
     * Creates and tags an assessment with no due date.
     *
     * @Given /^an? (?P<tag>summative|formative) "(?P<mod>\w+)" "(?P<name>[^"]*)" in "(?P<course>[^"]*)" with no due date(?P<opts> and no grade)? exists$/
     * @param string $tag
     * @param string $modname
     * @param string $name
     * @param string $course
     * @param string $opts
     */
    public function an_assessment_with_no_due_date(
        string $tag,
        string $modname,
        string $name,
        string $course,
        string $opts = ''
    ): void {
        $this->create_assessment($tag, $modname, $name, $course, $opts, null);
    }

    /**
     * Creates an activity of a type and tags it.
     *
     * @param string $tag
     * @param string $modname
     * @param string $name
     * @param string $shortname
     * @param string $opts
     * @param int|null $due
     */
    protected function create_assessment(
        string $tag,
        string $modname,
        string $name,
        string $shortname,
        string $opts,
        ?int $due
    ): void {
        global $CFG, $DB;
        self::require_module($modname);
        $course = self::course($shortname);
        $gen = testing_util::get_data_generator();
        $allocation = str_contains($opts, 'marker allocation');
        $markingdue = preg_match('/marking deadline "([^"]*)"/', $opts, $m) ? self::time($m[1]) : 0;
        $record = ['course' => $course->id, 'name' => $name];
        if (str_contains($opts, 'no description')) {
            $record['intro'] = '';
        } else if (preg_match('/description "([^"]*)"/', $opts, $m)) {
            $record['intro'] = $m[1];
        }
        switch ($modname) {
            case 'assign':
                // EC5: "with no online submission" means the assignment only records a mark.
                $online = str_contains($opts, 'no online submission') ? 0 : 1;
                $record += ['duedate' => (int)$due, 'submissiondrafts' => str_contains($opts, 'required Submit') ? 1 : 0,
                    'assignsubmission_onlinetext_enabled' => $online,
                    'assignsubmission_file_enabled' => 0,
                    'gradingduedate' => $markingdue,
                    'markingworkflow' => $allocation || str_contains($opts, 'marking workflow') ? 1 : 0,
                    'markingallocation' => $allocation ? 1 : 0];
                if (str_contains($opts, 'team submission')) {
                    $record += ['teamsubmission' => 1, 'requireallteammemberssubmit' => 0, 'preventsubmissionnotingroup' => 0];
                    // Teams from one grouping, so a student's other groups (an extension group) aren't teams.
                    if (preg_match('/team submission in grouping "([^"]*)"/', $opts, $m)) {
                        $grouping = $DB->get_record('groupings', ['courseid' => $course->id, 'name' => $m[1]])
                            ?: $gen->create_grouping(['courseid' => $course->id, 'name' => $m[1]]);
                        $record['teamsubmissiongroupingid'] = $grouping->id;
                    }
                }
                if (str_contains($opts, 'reopened attempts')) {
                    $record += ['attemptreopenmethod' => 'manual', 'maxattempts' => -1];
                }
                if (preg_match('/cut-off "([^"]*)"/', $opts, $m)) {
                    $record['cutoffdate'] = self::time($m[1]);
                }
                if (preg_match('/(\d+) markers/', $opts, $m)) {
                    if (!$DB->get_manager()->field_exists('assign', 'markercount')) {
                        throw new \Moodle\BehatExtension\Exception\SkippedException('Several assign markers need Moodle 5.0');
                    }
                    $record += ['markercount' => (int)$m[1],
                        'multimarkmethod' => str_contains($opts, 'manual agreement') ? 'manual' : 'average'];
                }
                break;
            case 'quiz':
                require_once($CFG->dirroot . '/mod/quiz/lib.php');
                $methods = ['first attempt' => QUIZ_ATTEMPTFIRST, 'last attempt' => QUIZ_ATTEMPTLAST,
                    'highest grade' => QUIZ_GRADEHIGHEST];
                $record += ['timeclose' => (int)$due, 'sumgrades' => 1];
                if (preg_match('/grading method "([^"]*)"/', $opts, $m)) {
                    $record['grademethod'] = $methods[strtolower($m[1])];
                }
                if (preg_match('/(\d+) attempts? allowed/', $opts, $m)) {
                    $record['attempts'] = (int)$m[1];
                }
                break;
            case 'peerwork':
                $record += ['duedate' => (int)$due];
                break;
            case 'coursework':
                $markers = preg_match('/(\d+) markers/', $opts, $m) ? (int)$m[1] : 1;
                $record += ['deadline' => (int)$due, 'numberofmarkers' => $markers, 'allocationenabled' => $allocation ? 1 : 0,
                    'moderationenabled' => str_contains($opts, 'moderation') ? 1 : 0,
                    'markingdeadlineenabled' => $markingdue ? 1 : 0, 'initialmarkingdeadline' => $markingdue];
                if (str_contains($opts, 'submission type attendance')) {
                    $record['submissiontype'] = 'attendance';
                }
                break;
            case 'workshop':
                $record += ['submissionend' => (int)$due];
                break;
            default:
                $record += ['duedate' => (int)$due];
        }
        if (preg_match('/opens "([^"]*)"/', $opts, $m)) {
            // When submissions open (Pierre's UAT, 2026-10-19), in each type's own field.
            $fields = ['assign' => 'allowsubmissionsfromdate', 'quiz' => 'timeopen', 'coursework' => 'startdate',
                'peerwork' => 'fromdate'];
            $record[$fields[$modname]] = self::time($m[1]);
        }
        if (str_contains($opts, 'no grade')) {
            // A grade of 0 is grade type None.
            $record['grade'] = 0;
        }
        $instance = $gen->create_module($modname, $record);
        if (isset($record['intro'])) {
            // Module generators fill in an empty intro, so set it afterwards.
            $DB->set_field($modname, 'intro', $record['intro'], ['id' => $instance->id]);
        }
        if (str_contains($opts, 'brief file')) {
            get_file_storage()->create_file_from_string(['contextid' => context_module::instance($instance->cmid)->id,
                'component' => 'mod_' . $modname, 'filearea' => 'intro', 'itemid' => 0, 'filepath' => '/',
                'filename' => 'brief.pdf'], 'Brief');
        }
        if ($modname === 'quiz') {
            self::add_essay($instance);
            $review = ['marks never shown' => 0, 'marks shown immediately after only' => 0x11000,
                'marks shown after close only' => 0x00010];
            foreach ($review as $phrase => $marks) {
                if (str_contains($opts, $phrase)) {
                    self::set_quiz((int)$instance->id, ['reviewmarks' => $marks]);
                }
            }
            if (str_contains($opts, 'a maximum grade of 0')) {
                self::set_quiz((int)$instance->id, ['grade' => 0]);
            }
        }
        if ($due) {
            self::joined_before($course->id, $due);
        }
        $tags = [$tag];
        if (preg_match('/tagged "([^"]*)"/', $opts, $m)) {
            $tags[] = $m[1];
        }
        core_tag_tag::set_item_tags('core', 'course_modules', $instance->cmid, context_module::instance($instance->cmid), $tags);
    }

    /**
     * Changes quiz settings in the database and updates its grade item, as saving the form would.
     *
     * @param int $quizid
     * @param array $fields
     */
    protected static function set_quiz(int $quizid, array $fields): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $DB->update_record('quiz', ['id' => $quizid] + $fields);
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        $quiz->cmidnumber = '';
        quiz_grade_item_update($quiz);
    }

    /**
     * Dates the enrolments already in a course before a deadline: students on the module when an
     * assessment is set were there before its deadline. Students enrolled later in a scenario are
     * late joiners (EC10).
     *
     * @param int $courseid
     * @param int $due
     */
    protected static function joined_before(int $courseid, int $due): void {
        global $DB;
        $before = $due - DAYSECS;
        $DB->execute(
            "UPDATE {user_enrolments}
                         SET timecreated = :t1, timestart = CASE WHEN timestart > :t2 THEN :t3 ELSE timestart END
                       WHERE timecreated > :t4 AND enrolid IN (SELECT id FROM {enrol} WHERE courseid = :courseid)",
            ['t1' => $before, 't2' => $before, 't3' => $before, 't4' => $before, 'courseid' => $courseid]
        );
    }

    /**
     * An activity of a type that has grade items, without tags.
     *
     * @Given /^an? "(?P<mod>\w+)" activity with grade items exists in "(?P<course>[^"]*)"$/
     * @param string $modname
     * @param string $shortname
     */
    public function an_activity_with_grade_items_exists(string $modname, string $shortname): void {
        testing_util::get_data_generator()->create_module($modname, ['course' => self::course($shortname)->id,
            'name' => ucfirst($modname) . ' with grades']);
    }

    /**
     * Enables or disables an activity type.
     *
     * @Given /^the "(?P<type>[^"]*)" activity type is (?P<not>not )?enabled in My assessments$/
     * @param string $type
     * @param string $not
     */
    public function the_activity_type_is_enabled(string $type, string $not = ''): void {
        global $DB;
        $modname = $DB->get_field_sql(
            "SELECT name FROM {modules} WHERE " . $DB->sql_like('name', ':n', false),
            ['n' => strtolower($type)],
            MUST_EXIST
        );
        \block_le_dashboard\local\adapter\registry::set_enabled($modname, $not === '');
    }

    /**
     * Saves the formative tag setting, as the settings page does: its callback queues a backfill.
     *
     * @When /^the formative tag is changed to "(?P<tag>[^"]*)" in the settings$/
     * @param string $tag
     */
    public function the_formative_tag_is_changed(string $tag): void {
        set_config('formativetags', $tag, 'block_le_dashboard');
        \block_le_dashboard\task\backfill::setting_changed('block_le_dashboard/formativetags');
    }

    /**
     * Unticks an activity type and saves, as the Activity types page does.
     *
     * @When /^the "(?P<type>[^"]*)" activity type is unticked on the Activity types page$/
     * @param string $type
     */
    public function the_activity_type_is_unticked(string $type): void {
        $this->the_activity_type_is_enabled($type, 'not ');
        \block_le_dashboard\task\backfill::queue();
    }

    /**
     * Makes indexing of a type fail.
     *
     * @Given /^indexing of "(?P<mod>\w+)" activities fails with an error$/
     * @param string $modname
     */
    public function indexing_fails(string $modname): void {
        set_config('testfailtypes', $modname, 'block_le_dashboard');
    }

    /**
     * Makes reading every student's data fail, for every activity.
     *
     * @Given /^reading student data fails for every student$/
     */
    public function reading_student_data_fails(): void {
        set_config('testfailstudent', -1, 'block_le_dashboard');
    }

    /**
     * Undoes the indexing failures.
     *
     * @Given /^reading student data works again$/
     */
    public function reading_student_data_works(): void {
        unset_config('testfailstudent', 'block_le_dashboard');
        unset_config('testfailtypes', 'block_le_dashboard');
    }

    /**
     * Asserts a module is not installed, skipping the scenario on a site that has it.
     *
     * @Given /^"mod_(?P<mod>\w+)" is not installed$/
     * @param string $modname
     */
    public function module_is_not_installed(string $modname): void {
        if (core_component::get_component_directory('mod_' . $modname)) {
            throw new \Moodle\BehatExtension\Exception\SkippedException("mod_$modname is installed on this site");
        }
    }

    // Access steps.

    /**
     * Hides an activity from students.
     *
     * @Given /^"(?P<name>[^"]*)" is hidden from students$/
     * @param string $name
     */
    public function is_hidden_from_students(string $name): void {
        $cm = $this->cm($name);
        set_coursemodule_visible($cm->id, 0);
        self::queue($cm);
    }

    /**
     * Hides the section an activity is in.
     *
     * @Given /^the section containing "(?P<name>[^"]*)" is hidden$/
     * @param string $name
     */
    public function the_section_is_hidden(string $name): void {
        $cm = $this->cm($name);
        set_section_visible($cm->course, $cm->sectionnum, 0);
        self::queue($cm);
    }

    /**
     * Hides a course.
     *
     * @Given /^the course "(?P<course>[^"]*)" is hidden from students$/
     * @param string $shortname
     */
    public function the_course_is_hidden(string $shortname): void {
        $course = self::course($shortname);
        update_course((object)['id' => $course->id, 'visible' => 0]);
    }

    /**
     * Sets an activity's availability.
     *
     * @param cm_info $cm
     * @param array $conditions
     */
    protected static function restrict(cm_info $cm, array $conditions): void {
        global $DB;
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(\core_availability\tree::get_root_json($conditions)),
            ['id' => $cm->id]
        );
        rebuild_course_cache($cm->course, true);
        self::queue($cm);
    }

    /**
     * Restricts an activity to a group.
     *
     * @Given /^"(?P<name>[^"]*)" is restricted to group "(?P<group>[^"]*)"$/
     * @param string $name
     * @param string $group
     */
    public function is_restricted_to_group(string $name, string $group): void {
        global $DB;
        $cm = $this->cm($name);
        $groupid = $DB->get_field('groups', 'id', ['courseid' => $cm->course, 'name' => $group], MUST_EXIST);
        self::restrict($cm, [\availability_group\condition::get_json((int)$groupid)]);
    }

    /**
     * Restricts an activity to one group's members who aren't in another, as Exeter's formal mitigation
     * does when it moves a student to an extension copy (Pierre, 2026-10-09).
     *
     * @Given /^"(?P<name>[^"]*)" is restricted to group "(?P<group>[^"]*)" and not group "(?P<other>[^"]*)"$/
     * @param string $name
     * @param string $group
     * @param string $other
     */
    public function is_restricted_to_group_not(string $name, string $group, string $other): void {
        global $DB;
        $cm = $this->cm($name);
        $in = $DB->get_field('groups', 'id', ['courseid' => $cm->course, 'name' => $group], MUST_EXIST);
        $out = $DB->get_field('groups', 'id', ['courseid' => $cm->course, 'name' => $other], MUST_EXIST);
        $not = \core_availability\tree::get_nested_json([\availability_group\condition::get_json((int)$out)], '!|');
        self::restrict($cm, [\availability_group\condition::get_json((int)$in), $not]);
    }

    /**
     * Adds a student to a group, or takes them out, the usual way (the group events fire).
     *
     * @When /^"(?P<user>[^"]*)" is (?P<how>added to|removed from) group "(?P<group>[^"]*)"$/
     * @param string $user
     * @param string $how
     * @param string $group
     */
    public function is_added_to_group(string $user, string $how, string $group): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $groupid = $DB->get_field('groups', 'id', ['name' => $group], MUST_EXIST);
        if ($how === 'added to') {
            groups_add_member($groupid, self::userid($user));
        } else {
            groups_remove_member($groupid, self::userid($user));
        }
    }

    /**
     * Makes two students a team of a team assignment whose teams come from a grouping.
     *
     * @Given /^"(?P<u1>[^"]*)" and "(?P<u2>[^"]*)" are team "(?P<group>[^"]*)" of "(?P<name>[^"]*)"$/
     * @param string $u1
     * @param string $u2
     * @param string $group
     * @param string $name
     */
    public function are_team_of(string $u1, string $u2, string $group, string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $groupingid = $DB->get_field('assign', 'teamsubmissiongroupingid', ['id' => $cm->instance], MUST_EXIST);
        $gen = testing_util::get_data_generator();
        $g = $gen->create_group(['courseid' => $cm->course, 'name' => $group]);
        $gen->create_grouping_group(['groupingid' => $groupingid, 'groupid' => $g->id]);
        foreach ([$u1, $u2] as $user) {
            $gen->create_group_member(['groupid' => $g->id, 'userid' => self::userid($user)]);
        }
    }

    /**
     * Restricts an activity by another one's completion.
     *
     * @Given /^"(?P<name>[^"]*)" is only available once "(?P<other>[^"]*)" in "(?P<course>[^"]*)" is complete$/
     * @param string $name
     * @param string $other
     * @param string $shortname
     */
    public function is_available_once_complete(string $name, string $other, string $shortname): void {
        global $DB;
        $course = self::course($shortname);
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
        $page = testing_util::get_data_generator()->create_module('page', ['course' => $course->id, 'name' => $other,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        self::restrict($this->cm($name), [\availability_completion\condition::get_json((int)$page->cmid, COMPLETION_COMPLETE)]);
    }

    /**
     * Restricts an activity to open later.
     *
     * @Given /^"(?P<name>[^"]*)" is only available from "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function is_only_available_from(string $name, string $when): void {
        self::restrict($this->cm($name), [\availability_date\condition::get_json(
            \availability_date\condition::DIRECTION_FROM,
            self::time($when)
        )]);
    }

    /**
     * The user's enrolment.
     *
     * @param string $username
     * @param string $shortname
     * @return array [plugin, instance, userid]
     */
    protected static function enrolment(string $username, string $shortname): array {
        global $DB;
        $userid = self::userid($username);
        $course = self::course($shortname);
        $instance = $DB->get_record_sql(
            "SELECT e.*
                                           FROM {enrol} e
                                           JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                          WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $course->id, 'userid' => $userid],
            IGNORE_MULTIPLE
        );
        return [enrol_get_plugin($instance->enrol), $instance, $userid];
    }

    /**
     * Ends an enrolment.
     *
     * @Given /^the enrolment of "(?P<user>[^"]*)" in "(?P<course>[^"]*)" ended "(?P<when>[^"]*)"$/
     * @param string $username
     * @param string $shortname
     * @param string $when
     */
    public function the_enrolment_ended(string $username, string $shortname, string $when): void {
        [$plugin, $instance, $userid] = self::enrolment($username, $shortname);
        $plugin->update_user_enrol($instance, $userid, null, null, self::time($when));
    }

    /**
     * Suspends an enrolment.
     *
     * @Given /^the enrolment of "(?P<user>[^"]*)" in "(?P<course>[^"]*)" is suspended$/
     * @param string $username
     * @param string $shortname
     */
    public function the_enrolment_is_suspended(string $username, string $shortname): void {
        [$plugin, $instance, $userid] = self::enrolment($username, $shortname);
        $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
    }

    /**
     * Unenrols a user.
     *
     * @Given /^"(?P<user>[^"]*)" is unenrolled from "(?P<course>[^"]*)"$/
     * @param string $username
     * @param string $shortname
     */
    public function is_unenrolled(string $username, string $shortname): void {
        [$plugin, $instance, $userid] = self::enrolment($username, $shortname);
        $plugin->unenrol_user($instance, $userid);
    }

    /**
     * Creates a role from an archetype.
     *
     * @Given /^the role "(?P<name>[^"]*)" exists, based on "(?P<archetype>[^"]*)"$/
     * @param string $name
     * @param string $archetype
     */
    public function the_role_exists(string $name, string $archetype): void {
        testing_util::get_data_generator()->create_role(['name' => $name,
            'shortname' => strtolower(preg_replace('/\W/', '', $name)), 'archetype' => $archetype]);
    }

    /**
     * Adds a role to one of the block's role settings.
     *
     * @Given /^the My assessments block treats "(?P<name>[^"]*)" as a (?P<kind>student|staff|module convenor) role$/
     * @param string $name Role name or shortname
     * @param string $kind
     */
    public function treats_as_role(string $name, string $kind): void {
        global $DB;
        $shortname = $DB->get_field('role', 'shortname', ['shortname' => $name])
            ?: $DB->get_field('role', 'shortname', ['name' => $name], MUST_EXIST);
        $setting = ['student' => 'studentrole', 'staff' => 'staffrole', 'module convenor' => 'moduleconvenorrole'][$kind];
        $current = array_filter(explode(',', (string)get_config('block_le_dashboard', $setting)));
        set_config($setting, implode(',', array_unique(array_merge($current, [$shortname]))), 'block_le_dashboard');
    }

    /**
     * Saves the view a user last opened.
     *
     * @Given /^"(?P<user>[^"]*)" last opened the "(?P<view>[^"]*)"$/
     * @param string $username
     * @param string $view
     */
    public function last_opened(string $username, string $view): void {
        set_user_preference('block_le_dashboard_view', self::view_key($view), self::userid($username));
    }

    /**
     * Enrols a user with one role, removing any others in that course.
     *
     * @Given /^"(?P<user>[^"]*)" is enrolled in "(?P<course>[^"]*)" as "(?P<role>[^"]*)"(?P<only> only)?$/
     * @param string $username
     * @param string $shortname
     * @param string $role
     * @param string $only
     */
    public function is_enrolled_as(string $username, string $shortname, string $role, string $only = ''): void {
        global $DB;
        $userid = self::userid($username);
        $course = self::course($shortname);
        $roleid = (int)($DB->get_field('role', 'id', ['shortname' => $role]) ?: $DB->get_field(
            'role',
            'id',
            ['name' => $role],
            MUST_EXIST
        ));
        $context = context_course::instance($course->id);
        if ($only) {
            role_unassign_all(['userid' => $userid, 'contextid' => $context->id]);
        }
        testing_util::get_data_generator()->enrol_user($userid, $course->id, $roleid);
        if (user_has_role_assignment($userid, $roleid, $context->id) === false) {
            role_assign($roleid, $userid, $context->id);
        }
    }

    /**
     * Customises a section title through the local language pack.
     *
     * @Given /^the language string for the "(?P<title>[^"]*)" section title is customised to "(?P<text>[^"]*)"$/
     * @param string $title
     * @param string $text
     */
    public function the_section_title_is_customised(string $title, string $text): void {
        global $CFG;
        $strings = get_string_manager()->load_component_strings('block_le_dashboard', 'en');
        $keys = array_keys(array_filter(
            $strings,
            fn($v, $k) => $v === $title && str_starts_with($k, 'section_'),
            ARRAY_FILTER_USE_BOTH
        ));
        $dir = $CFG->langlocalroot . '/en_local';
        check_dir_exists($dir);
        $php = "<?php\n";
        foreach ($keys as $key) {
            $php .= '$string[' . var_export($key, true) . '] = ' . var_export($text, true) . ";\n";
        }
        file_put_contents($dir . '/block_le_dashboard.php', $php);
        get_string_manager()->reset_caches();
    }

    // Submissions and marking.

    /**
     * Submits an assessment for one or two students.
     *
     * @Given /^"(?P<u1>[^"]*)"(?: and "(?P<u2>[^"]*)")? (?:has|have) submitted "(?P<name>[^"]*)"$/
     * @param string $u1
     * @param string $u2
     * @param string $name
     */
    public function have_submitted(string $u1, string $u2, string $name): void {
        foreach (array_filter([$u1, $u2]) as $username) {
            $this->submit($this->cm($name), self::userid($username));
        }
    }

    /**
     * Submits an assessment at a given time.
     *
     * @Given /^"(?P<user>[^"]*)" submitted "(?P<name>[^"]*)" "(?P<when>[^"]*)"$/
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function submitted_at(string $username, string $name, string $when): void {
        global $DB;
        $cm = $this->cm($name);
        $userid = self::userid($username);
        $this->submit($cm, $userid);
        $time = self::time($when);
        if ($cm->modname === 'assign') {
            $DB->set_field('assign_submission', 'timemodified', $time, ['assignment' => $cm->instance, 'userid' => $userid]);
        } else if ($cm->modname === 'coursework') {
            $DB->set_field(
                'coursework_submissions',
                'timesubmitted',
                $time,
                ['courseworkid' => $cm->instance, 'allocatableid' => $userid, 'allocatabletype' => 'user']
            );
        } else {
            throw new coding_exception("Setting the submission time of {$cm->modname} is not supported");
        }
        self::queue($cm);
    }

    /**
     * Grants an extension.
     *
     * @Given /^"(?P<user>[^"]*)" has an extension on "(?P<name>[^"]*)" to "(?P<when>[^"]*)"$/
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function has_an_extension(string $username, string $name, string $when): void {
        global $CFG;
        $cm = $this->cm($name);
        if ($cm->modname !== 'assign') {
            throw new coding_exception("Extensions on {$cm->modname} are not supported");
        }
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assign = new assign($cm->context, $cm, null);
        $flags = $assign->get_user_flags(self::userid($username), true);
        $flags->extensionduedate = self::time($when);
        $assign->update_user_flags($flags);
        self::queue($cm);
    }

    /**
     * A self-certified extension on a coursework.
     *
     * @Given /^"(?P<user>[^"]*)" self-certified "(?P<name>[^"]*)" to "(?P<when>[^"]*)"$/
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function self_certified(string $username, string $name, string $when): void {
        global $DB;
        $cm = $this->cm($name);
        $record = ['courseworkid' => $cm->instance, 'allocatableid' => self::userid($username), 'allocatabletype' => 'user',
            'extended_deadline' => self::time($when), 'type' => 'extension', 'selfcert' => 1,
            'timecreated' => time(), 'timemodified' => time()];
        $columns = $DB->get_columns('coursework_mitigations');
        $DB->insert_record('coursework_mitigations', array_intersect_key($record, $columns));
        self::queue($cm);
    }

    /**
     * Does nothing, for scenario outlines where one example needs no event.
     *
     * @Given /^nothing happens$/
     */
    public function nothing_happens(): void {
    }

    /**
     * Submits a peerwork or a team assignment for a group.
     *
     * @Given /^"(?P<user>[^"]*)" has submitted "(?P<name>[^"]*)" for "(?P<group>[^"]*)"$/
     * @param string $username
     * @param string $name
     * @param string $group
     */
    public function has_submitted_for_group(string $username, string $name, string $group): void {
        global $DB;
        $cm = $this->cm($name);
        $groupid = $DB->get_field('groups', 'id', ['courseid' => $cm->course, 'name' => $group], MUST_EXIST);
        if ($cm->modname === 'assign') {
            // A team assignment submits for the member's group (CI caught this step using peerwork's generator).
            $this->submit($cm, self::userid($username));
            if (
                !$DB->record_exists('assign_submission', ['assignment' => $cm->instance, 'groupid' => $groupid,
                    'status' => 'submitted'])
            ) {
                throw new coding_exception("'$username' did not submit '$name' for '$group'");
            }
            self::queue($cm);
            return;
        }
        testing_util::get_data_generator()->get_plugin_generator('mod_peerwork')->create_submission([
            'peerworkid' => $cm->instance, 'groupid' => $groupid, 'userid' => self::userid($username),
            'timecreated' => time(), 'timemodified' => time()]);
        self::queue($cm);
    }

    /**
     * One submission through the plugin's own API or generator.
     *
     * @param cm_info $cm
     * @param int $userid
     */
    protected function submit(cm_info $cm, int $userid): void {
        global $DB;
        $gen = testing_util::get_data_generator();
        switch ($cm->modname) {
            case 'assign':
                $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $userid, 'cmid' => $cm->id,
                    'onlinetext' => 'My work']);
                // Where students must click Submit, the generator leaves a draft: submit it, as the student would.
                $DB->set_field(
                    'assign_submission',
                    'status',
                    'submitted',
                    ['assignment' => $cm->instance, 'userid' => $userid, 'latest' => 1]
                );
                break;
            case 'quiz':
                $this->finish_attempts($cm, $userid, 1);
                return;
            case 'coursework':
                $gen->get_plugin_generator('mod_coursework')->create_submission(['courseworkid' => $cm->instance,
                    'allocatableid' => $userid, 'allocatabletype' => 'user',
                    'finalisedstatus' => \mod_coursework\models\submission::FINALISED_STATUS_FINALISED]);
                break;
            default:
                throw new coding_exception("Submitting {$cm->modname} is not supported");
        }
        self::queue($cm);
    }

    /**
     * Finishes quiz attempts, answering the essay.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param int $count
     */
    protected function finish_attempts(cm_info $cm, int $userid, int $count): void {
        global $USER;
        $qgen = testing_util::get_data_generator()->get_plugin_generator('mod_quiz');
        $saved = $USER;
        \core\session\manager::set_user(core_user::get_user($userid));
        for ($i = 0; $i < $count; $i++) {
            $attempt = $qgen->create_attempt($cm->instance, $userid);
            $qgen->submit_responses(
                $attempt->id,
                [1 => 'My answer'],
                false,
                true
            );
        }
        \core\session\manager::set_user($saved);
        self::queue($cm);
    }

    /**
     * Quiz attempts for one or two students.
     *
     * @Given /^"(?P<u1>[^"]*)"(?: and "(?P<u2>[^"]*)")? (?:has|have) finished "(?P<name>[^"]*)"$/
     * @param string $u1
     * @param string $u2
     * @param string $name
     */
    public function have_finished(string $u1, string $u2, string $name): void {
        foreach (array_filter([$u1, $u2]) as $username) {
            $this->finish_attempts($this->cm($name), self::userid($username), 1);
        }
    }

    /**
     * Several quiz attempts.
     *
     * @Given /^"(?P<user>[^"]*)" has finished (?P<n>\d+) attempts at "(?P<name>[^"]*)"$/
     * @param string $username
     * @param int $count
     * @param string $name
     */
    public function has_finished_attempts(string $username, int $count, string $name): void {
        $this->finish_attempts($this->cm($name), self::userid($username), $count);
    }

    /**
     * A quiz attempt the student never submitted, left to expire: it uses up an attempt but hands nothing in.
     *
     * @Given /^"(?P<user>[^"]*)" has abandoned an attempt at "(?P<name>[^"]*)"$/
     * @param string $username
     * @param string $name
     */
    public function has_abandoned_attempt(string $username, string $name): void {
        global $DB, $USER;
        $cm = $this->cm($name);
        $userid = self::userid($username);
        $saved = $USER;
        \core\session\manager::set_user(core_user::get_user($userid));
        $attempt = testing_util::get_data_generator()->get_plugin_generator('mod_quiz')->create_attempt($cm->instance, $userid);
        \core\session\manager::set_user($saved);
        $DB->update_record('quiz_attempts', (object)['id' => $attempt->id, 'state' => 'abandoned',
            'timefinish' => 0, 'timemodified' => time() - 60]);
        self::queue($cm);
    }

    /**
     * Grades or leaves ungraded one attempt.
     *
     * @Given /^the (?P<which>first|last) attempt of "(?P<user>[^"]*)" at "(?P<name>[^"]*)" (?P<state>still needs grading|has been graded)$/
     * @param string $which
     * @param string $username
     * @param string $name
     * @param string $state
     */
    public function the_attempt_is(string $which, string $username, string $name, string $state): void {
        global $DB;
        $cm = $this->cm($name);
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $cm->instance, 'userid' => self::userid($username)], 'attempt');
        $attempt = $which === 'first' ? reset($attempts) : end($attempts);
        if ($state === 'has been graded') {
            $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            $quba->manual_grade(1, 'Good', 1, FORMAT_HTML);
            question_engine::save_questions_usage_by_activity($quba);
            $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $attempt->id]);
            self::regrade_quiz($cm, (int)$attempt->userid);
        } else {
            $this->assert_ungraded($attempt);
        }
        self::queue($cm);
    }

    /**
     * Writes a student's quiz grade to the gradebook, as finishing a manual grade does.
     *
     * @param cm_info|stdClass $cm
     * @param int $userid
     */
    protected static function regrade_quiz($cm, int $userid): void {
        \mod_quiz\quiz_settings::create((int)$cm->instance)->get_grade_calculator()->recompute_final_grade($userid);
    }

    /**
     * Checks an attempt is still waiting for manual grading.
     *
     * @param stdClass $attempt
     */
    protected function assert_ungraded(stdClass $attempt): void {
        if ($attempt->sumgrades !== null) {
            throw new ExpectationException('The attempt is already graded', $this->getSession());
        }
    }

    /**
     * Allocates a submission's marking.
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is (?:allocated to "(?P<marker>[^"]*)"(?: and "(?P<marker2>[^"]*)")?|not allocated)$/
     * @param string $student
     * @param string $name
     * @param string $marker
     * @param string $marker2 A second marker (Moodle 5.0+ assign with several markers).
     */
    public function the_submission_is_allocated(string $student, string $name, string $marker = '', string $marker2 = ''): void {
        global $DB;
        $cm = $this->cm($name);
        $this->allocate($cm, self::userid($student), $marker ? self::userid($marker) : 0);
        if ($marker2) {
            $DB->insert_record('assign_allocated_marker', ['assignment' => $cm->instance, 'student' => self::userid($student),
                'marker' => self::userid($marker2)]);
            self::queue($cm);
        }
    }

    /**
     * Allocates every submission.
     *
     * @Given /^both submissions of "(?P<name>[^"]*)" are allocated to "(?P<marker>[^"]*)"$/
     * @param string $name
     * @param string $marker
     */
    public function both_submissions_are_allocated(string $name, string $marker): void {
        global $DB;
        $cm = $this->cm($name);
        if ($cm->modname === 'assign') {
            $students = $DB->get_fieldset_select(
                'assign_submission',
                'userid',
                "assignment = :id AND status = 'submitted' AND latest = 1",
                ['id' => $cm->instance]
            );
        } else {
            $students = $DB->get_fieldset_select(
                'coursework_submissions',
                'allocatableid',
                "courseworkid = :id AND allocatabletype = 'user'",
                ['id' => $cm->instance]
            );
        }
        foreach ($students as $userid) {
            $this->allocate($cm, (int)$userid, self::userid($marker));
        }
    }

    /**
     * Allocates one student's marking.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param int $markerid 0 for unallocated.
     */
    protected function allocate(cm_info $cm, int $userid, int $markerid): void {
        global $CFG;
        if ($cm->modname === 'assign') {
            testing_util::get_data_generator()->get_plugin_generator('block_le_dashboard')
                ->allocate_assign($cm->id, $userid, $markerid);
        } else if ($cm->modname === 'coursework') {
            if ($markerid) {
                testing_util::get_data_generator()->get_plugin_generator('mod_coursework')->create_allocation([
                    'courseworkid' => $cm->instance, 'allocatableid' => $userid, 'allocatabletype' => 'user',
                    'assessorid' => $markerid, 'stageidentifier' => 'assessor_1', 'ismanual' => 1]);
            }
        } else {
            throw new coding_exception("{$cm->modname} has no allocation");
        }
        self::queue($cm);
    }

    /**
     * Makes someone the moderator of every submission of a coursework.
     *
     * @Given /^"(?P<marker>[^"]*)" moderates "(?P<name>[^"]*)"$/
     * @param string $marker
     * @param string $name
     */
    public function moderates(string $marker, string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $students = $DB->get_fieldset_select(
            'coursework_submissions',
            'allocatableid',
            "courseworkid = :id AND allocatabletype = 'user'",
            ['id' => $cm->instance]
        );
        foreach ($students as $userid) {
            testing_util::get_data_generator()->get_plugin_generator('mod_coursework')->create_allocation([
                'courseworkid' => $cm->instance, 'allocatableid' => (int)$userid, 'allocatabletype' => 'user',
                'assessorid' => self::userid($marker), 'stageidentifier' => 'moderator', 'ismanual' => 1]);
        }
        self::queue($cm);
    }

    /**
     * A marker marks a submission.
     *
     * @Given /^"(?P<marker>[^"]*)" marks the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)"$/
     * @param string $marker
     * @param string $student
     * @param string $name
     */
    public function marks_the_submission(string $marker, string $student, string $name): void {
        $this->feedback($this->cm($name), self::userid($student), self::userid($marker), 'assessor_1', false);
    }

    /**
     * Releases an assign grade to the student, then moves what happened so far a minute or
     * two into the past, so that anything later in the scenario is visibly newer.
     *
     * @Given /^the grade of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is released$/
     * @param string $student
     * @param string $name
     */
    public function the_grade_is_released(string $student, string $name): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        $cm = $this->cm($name);
        $userid = self::userid($student);
        $assign = new assign($cm->context, $cm, null);
        if ($assign->get_instance()->markingworkflow) {
            $flags = $assign->get_user_flags($userid, true);
            $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
            $assign->update_user_flags($flags);
            // Pushes the grade to the gradebook, now that its state allows it.
            $assign->update_grade($assign->get_user_grade($userid, false));
        }
        $where = 'assignment = :id AND userid = :userid';
        $params = ['id' => $cm->instance, 'userid' => $userid];
        $DB->execute("UPDATE {assign_submission} SET timemodified = timemodified - 120 WHERE $where", $params);
        $DB->execute("UPDATE {assign_grades} SET timemodified = timemodified - 60 WHERE $where", $params);
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        $DB->execute("UPDATE {grade_grades} SET timemodified = timemodified - 60, timecreated = timecreated - 60
                       WHERE itemid = :itemid AND userid = :userid", ['itemid' => $item->id, 'userid' => $userid]);
        self::queue($cm);
    }

    /**
     * Reopens a student's assign submission as a new attempt, as a teacher would.
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is reopened$/
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_reopened(string $student, string $name): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cm = $this->cm($name);
        $assign = new assign($cm->context, $cm, null);
        // Core's add_attempt() is what "Allow another attempt" calls; it isn't public.
        $method = new ReflectionMethod($assign, 'add_attempt');
        $method->setAccessible(true);
        if (!$method->invoke($assign, self::userid($student))) {
            throw new coding_exception("Could not reopen the submission of $student for $name");
        }
        self::queue($cm);
    }

    /**
     * Both markers give feedback.
     *
     * @Given /^both markers have given feedback on the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)"$/
     * @param string $student
     * @param string $name
     */
    public function both_markers_have_given_feedback(string $student, string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $userid = self::userid($student);
        if ($cm->modname === 'assign') {
            // Moodle 5.0+: each allocated marker gives their own mark.
            $markers = $DB->get_fieldset_select(
                'assign_allocated_marker',
                'marker',
                'assignment = :a AND student = :s',
                ['a' => $cm->instance, 's' => $userid]
            );
            foreach ($markers as $markerid) {
                $this->feedback($cm, $userid, (int)$markerid, 'assessor_1', false);
            }
            return;
        }
        $this->feedback($cm, $userid, get_admin()->id, 'assessor_1', false);
        $this->feedback($cm, $userid, get_admin()->id, 'assessor_2', false);
    }

    /**
     * Publishes the agreed grade.
     *
     * @Given /^the agreed grade for "(?P<student>[^"]*)" on "(?P<name>[^"]*)" is published$/
     * @param string $student
     * @param string $name
     */
    public function the_agreed_grade_is_published(string $student, string $name): void {
        $cm = $this->cm($name);
        $this->feedback($cm, self::userid($student), get_admin()->id, 'final_agreed_1', true);
        $coursework = \mod_coursework\models\coursework::get_from_id($cm->instance);
        $coursework->publish_grades();
        self::queue($cm);
    }

    /**
     * Coursework feedback at a stage, or an assign grade.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param int $markerid
     * @param string $stage
     * @param bool $final
     * @param float $mark
     */
    protected function feedback(cm_info $cm, int $userid, int $markerid, string $stage, bool $final, float $mark = 60): void {
        global $CFG;
        if ($cm->modname === 'assign') {
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            $assign = new assign($cm->context, $cm, null);
            $grade = $assign->get_user_grade($userid, true);
            if ((int)($assign->get_instance()->markercount ?? 1) > 1) {
                // Moodle 5.0+ with several markers: a marker's own mark, not the final grade.
                $grade->grader = $markerid;
                $assign->update_mark($grade, 60);
                self::queue($cm);
                return;
            }
            $grade->grade = $mark;
            $grade->grader = $markerid;
            $assign->update_grade($grade);
            if ($assign->get_instance()->markingworkflow) {
                // Under marking workflow, a marker's work is done when it goes to review.
                $flags = $assign->get_user_flags($userid, true);
                $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW;
                $assign->update_user_flags($flags);
            }
        } else {
            testing_util::get_data_generator()->get_plugin_generator('mod_coursework')->create_feedback([
                'courseworkid' => $cm->instance, 'allocatableid' => $userid, 'assessorid' => $markerid,
                'stage_identifier' => $stage, 'grade' => 60, 'isfinalgrade' => $final ? 1 : 0, 'finalised' => 1]);
        }
        self::queue($cm);
    }

    // Freshness steps (perf review F1 to F5, F9).

    /** @var array|null A student index row saved by "without the index being told". */
    protected static ?array $savedrow = null;

    /**
     * A re-index of an assessment is running: its task has started and read the old data.
     *
     * @Given /^a re-index of "(?P<name>[^"]*)" has started$/
     * @param string $name
     */
    public function a_reindex_has_started(string $name): void {
        global $DB;
        self::queue($this->cm($name));
        $DB->set_field_select(
            'task_adhoc',
            'timestarted',
            time(),
            'classname = :c AND timestarted IS NULL',
            ['c' => '\\block_le_dashboard\\task\\reindex']
        );
    }

    /**
     * A student submits (the activity's own events fire).
     *
     * @When /^"(?P<user>[^"]*)" submits "(?P<name>[^"]*)"$/
     * @param string $username
     * @param string $name
     */
    public function submits(string $username, string $name): void {
        $this->submit($this->cm($name), self::userid($username));
    }

    /**
     * The running re-index finishes, having read the data before the change.
     *
     * @When /^the running re-index of "(?P<name>[^"]*)" finishes$/
     * @param string $name
     */
    public function the_running_reindex_finishes(string $name): void {
        global $DB;
        $DB->delete_records_select(
            'task_adhoc',
            'classname = :c AND timestarted IS NOT NULL',
            ['c' => '\\block_le_dashboard\\task\\reindex']
        );
    }

    /** @var string[] Output of the parallel re-indexes. */
    protected static array $parallel = [];

    /**
     * Re-indexes an assessment in two processes at once.
     *
     * @When /^"(?P<name>[^"]*)" is re-indexed twice at the same time$/
     * @param string $name
     */
    public function reindexed_twice_at_once(string $name): void {
        global $CFG;
        $cm = $this->cm($name);
        $script = $CFG->dirroot . '/blocks/le_dashboard/tests/fixtures/behat_reindex.php';
        $procs = [];
        foreach ([1, 2] as $i) {
            $procs[$i] = proc_open([PHP_BINARY, $script, (string)$cm->id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        self::$parallel = [];
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
            $code = proc_close($proc);
            self::$parallel[] = "exit $code: " . trim($out);
        }
    }

    /**
     * Both parallel re-indexes succeeded.
     *
     * @Then /^neither re-index should fail$/
     */
    public function neither_reindex_should_fail(): void {
        foreach (self::$parallel as $result) {
            if (!str_starts_with($result, 'exit 0: ok')) {
                throw new ExpectationException("A re-index failed: $result", $this->getSession());
            }
        }
    }

    /**
     * The index has the assessment and a row for every student.
     *
     * @Then /^the index for "(?P<name>[^"]*)" should match the activity$/
     * @param string $name
     */
    public function the_index_should_match(string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $expected = count(indexer::students((int)$cm->course));
        $rows = $DB->count_records('block_le_dashboard_stu', ['cmid' => $cm->id]);
        if (!$DB->record_exists('block_le_dashboard_cm', ['cmid' => $cm->id]) || $rows !== $expected) {
            throw new ExpectationException("'$name' has $rows student rows, not $expected", $this->getSession());
        }
    }

    /**
     * The next write of an assessment's index rows fails.
     *
     * @Given /^writing the index for "(?P<name>[^"]*)" fails once$/
     * @param string $name
     */
    public function writing_fails_once(string $name): void {
        set_config('testfailwrite', $this->cm($name)->id, 'block_le_dashboard');
    }

    /**
     * An assessment is in the index.
     *
     * @Then /^"(?P<name>[^"]*)" should (?P<not>not )?be in the index$/
     * @param string $name
     * @param string $not
     */
    public function should_be_in_the_index(string $name, string $not = ''): void {
        global $DB;
        $in = $DB->record_exists('block_le_dashboard_cm', ['cmid' => $this->cm($name)->id]);
        if ($in === (bool)$not) {
            throw new ExpectationException("'$name' is " . ($in ? '' : 'not ') . 'in the index', $this->getSession());
        }
    }

    /**
     * Moves an existing assessment's deadline, through the activity, and re-indexes it.
     *
     * @Given /^"(?P<name>[^"]*)" was due "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function was_due(string $name, string $when): void {
        $this->the_deadline_is_changed_directly($name, $when);
        (new indexer())->index_cm((int)$this->cm($name)->id);
    }

    /**
     * Hides or shows an activity's grade item in the gradebook.
     *
     * @Given /^the grade item of "(?P<name>[^"]*)" is (?P<state>hidden|shown)$/
     * @param string $name
     * @param string $state
     */
    public function the_grade_item_is(string $name, string $state): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $cm = $this->cm($name);
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        $item->set_hidden($state === 'hidden' ? 1 : 0);
    }

    /**
     * A marker grades a student.
     *
     * @Given /^"(?P<marker>[^"]*)" grades "(?P<student>[^"]*)" on "(?P<name>[^"]*)" with "(?P<grade>[\d.]+)"$/
     * @param string $marker
     * @param string $student
     * @param string $name
     * @param string $grade
     */
    public function grades_with(string $marker, string $student, string $name, string $grade): void {
        $this->feedback($this->cm($name), self::userid($student), self::userid($marker), 'assessor_1', true, (float)$grade);
    }

    /**
     * A row's status pill.
     *
     * @Then /^"(?P<name>[^"]*)" should show the status "(?P<status>[^"]*)"$/
     * @param string $name
     * @param string $status
     */
    public function should_show_the_status(string $name, string $status): void {
        $pill = $this->row($name)->find('css', '[data-status]');
        if (!$pill || trim($pill->getText()) !== $status) {
            throw new ExpectationException(
                "'$name' shows '" . ($pill ? trim($pill->getText()) : '') . "', not '$status'",
                $this->getSession()
            );
        }
    }

    /**
     * The logged-in student submits an assignment through its page.
     *
     * @When /^I submit "(?P<name>[^"]*)"(?: for my group)?$/
     * @param string $name
     */
    public function i_submit(string $name): void {
        $this->getSession()->visit($this->locate_path('/mod/assign/view.php?id=' . $this->cm($name)->id));
        $this->execute('behat_forms::press_button', [get_string('addsubmission', 'assign')]);
        $this->execute('behat_forms::i_set_the_field_to', [get_string('onlinetext', 'assignsubmission_onlinetext'), 'My work']);
        $this->execute('behat_forms::press_button', [get_string('savechanges')]);
    }

    /**
     * Opens the dashboard without running pending index tasks.
     *
     * @When /^I view my dashboard$/
     */
    public function i_view_my_dashboard(): void {
        $this->getSession()->visit($this->locate_path('/my/'));
        $this->wait_for_pending_js();
    }

    /**
     * A student submits but the index hears nothing: their task is dropped. Saves their row.
     *
     * @Given /^"(?P<user>[^"]*)" has submitted "(?P<name>[^"]*)" without the index being told$/
     * @param string $username
     * @param string $name
     */
    public function submitted_untold(string $username, string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $userid = self::userid($username);
        $this->submit($cm, $userid);
        $DB->delete_records('task_adhoc', ['classname' => '\\block_le_dashboard\\task\\reindex']);
        self::$savedrow = (array)$DB->get_record('block_le_dashboard_stu', ['cmid' => $cm->id, 'userid' => $userid]);
    }

    /**
     * A student's index row is as it was saved.
     *
     * @Then /^the index row of "(?P<user>[^"]*)" for "(?P<name>[^"]*)" should be unchanged$/
     * @param string $username
     * @param string $name
     */
    public function the_index_row_should_be_unchanged(string $username, string $name): void {
        global $DB;
        $row = (array)$DB->get_record('block_le_dashboard_stu', ['cmid' => $this->cm($name)->id,
            'userid' => self::userid($username)]);
        unset($row['id'], self::$savedrow['id']);
        if ($row != self::$savedrow) {
            throw new ExpectationException("The index row of '$username' changed", $this->getSession());
        }
    }

    /**
     * Puts two users in a new group of the course they share.
     *
     * @Given /^"(?P<u1>[^"]*)" and "(?P<u2>[^"]*)" are in group "(?P<group>[^"]*)"$/
     * @param string $u1
     * @param string $u2
     * @param string $group
     */
    public function are_in_group(string $u1, string $u2, string $group): void {
        $ids = [self::userid($u1), self::userid($u2)];
        $shared = array_intersect_key(enrol_get_all_users_courses($ids[0]), enrol_get_all_users_courses($ids[1]));
        $gen = testing_util::get_data_generator();
        $g = $gen->create_group(['courseid' => reset($shared)->id, 'name' => $group]);
        foreach ($ids as $id) {
            $gen->create_group_member(['groupid' => $g->id, 'userid' => $id]);
        }
    }

    /** Setting names in steps, to their keys. */
    const SETTINGS = ['Live window for the nightly reconcile' => 'reconcilemonths'];

    /**
     * Sets a number setting.
     *
     * @Given /^the "(?P<setting>[^"]*)" setting is "(?P<value>\d+)" months$/
     * @param string $setting
     * @param int $value
     */
    public function the_setting_is(string $setting, int $value): void {
        set_config(self::SETTINGS[$setting], $value, 'block_le_dashboard');
    }

    /**
     * A new course holding one tagged assessment.
     *
     * @Given /^a course "(?P<course>[^"]*)" with an? (?P<tag>summative|formative) "(?P<mod>\w+)" "(?P<name>[^"]*)" was due "(?P<due>[^"]*)"$/
     * @param string $course
     * @param string $tag
     * @param string $modname
     * @param string $name
     * @param string $due
     */
    public function a_course_with(string $course, string $tag, string $modname, string $name, string $due): void {
        testing_util::get_data_generator()->create_course(['shortname' => $course, 'fullname' => $course]);
        $this->create_assessment($tag, $modname, $name, $course, '', self::time($due));
    }

    /**
     * What the index holds for an assessment.
     *
     * @Then /^the index should (?:still )?show "(?P<name>[^"]*)"(?: due "(?P<when>[^"]*)")?$/
     * @param string $name
     * @param string $when
     */
    public function the_index_should_show(string $name, string $when = ''): void {
        global $DB;
        $row = $DB->get_record('block_le_dashboard_cm', ['cmid' => $this->cm($name)->id]);
        if (!$row || $row->name !== $name) {
            throw new ExpectationException("The index doesn't show '$name'", $this->getSession());
        }
        if ($when !== '' && date('Y-m-d', (int)$row->due) !== date('Y-m-d', self::time($when))) {
            throw new ExpectationException("The index shows '$name' due " . date('Y-m-d', (int)$row->due), $this->getSession());
        }
    }

    /**
     * Renames an activity the usual way (course_module_updated fires).
     *
     * @When /^"(?P<name>[^"]*)" is renamed to "(?P<new>[^"]*)"$/
     * @param string $name
     * @param string $new
     */
    public function is_renamed(string $name, string $new): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        set_coursemodule_name($this->cm($name)->id, $new);
    }

    /**
     * An assessment was not re-indexed since time last passed: its index time is still in the past.
     *
     * @Then /^"(?P<name>[^"]*)" should have been re-indexed once$/
     * @param string $name
     */
    public function reindexed_once(string $name): void {
        global $DB;
        $time = (int)$DB->get_field('block_le_dashboard_cm', 'timeindexed', ['cmid' => $this->cm($name)->id]);
        if ($time > time() - MINSECS) {
            throw new ExpectationException("'$name' was re-indexed again", $this->getSession());
        }
    }

    // Index steps.

    /**
     * Runs pending index tasks.
     *
     * @Given /^the My assessments index (?:is up to date|catches up)$/
     */
    public function the_index_catches_up(): void {
        $this->catch_up();
    }

    /**
     * Runs the nightly reconcile.
     *
     * @When /^the My assessments nightly reconcile runs$/
     */
    public function the_nightly_reconcile_runs(): void {
        // A whole nightly pass, whatever the hour: the hourly task's timing has its own steps (index-passes.feature).
        try {
            \block_le_dashboard\local\index\pass::run(\block_le_dashboard\local\index\pass::NIGHTLY);
        } finally {
            \block_le_dashboard\local\adapter\registry::flush_errors();
        }
    }

    /**
     * Changes a deadline without any event.
     *
     * @Given /^the deadline of "(?P<name>[^"]*)" is changed directly in the database to "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function the_deadline_is_changed_directly(string $name, string $when): void {
        global $DB;
        $cm = $this->cm($name);
        $field = ['assign' => 'duedate', 'quiz' => 'timeclose', 'coursework' => 'deadline', 'workshop' => 'submissionend',
            'peerwork' => 'duedate'][$cm->modname];
        $DB->set_field($cm->modname, $field, self::time($when), ['id' => $cm->instance]);
    }

    /**
     * Time passes: stored dates move back, so nothing is re-indexed.
     *
     * @When /^(?P<n>\d+) minutes pass$/
     * @param int $minutes
     */
    public function minutes_pass(int $minutes): void {
        global $DB;
        $shift = $minutes * MINSECS;
        // The index and the activities' own times move together, so a re-index agrees.
        $fields = [
            'block_le_dashboard_cm' => ['due', 'cutoff', 'markingdue', 'timeindexed'],
            'block_le_dashboard_stu' => ['due', 'cutoff', 'timesubmitted', 'timereleased'],
            'block_le_dashboard_mark' => ['timeready', 'unitdue', 'timemarked'],
            'quiz' => ['timeclose'],
            'assign' => ['duedate', 'cutoffdate', 'gradingduedate', 'allowsubmissionsfromdate'],
            'assign_overrides' => ['duedate', 'cutoffdate'],
            'assign_user_flags' => ['extensionduedate'],
            'assign_submission' => ['timecreated', 'timemodified'],
            'assign_grades' => ['timecreated', 'timemodified'],
            'assign_mark' => ['timecreated', 'timemodified'],
            'grade_grades' => ['timecreated', 'timemodified'],
        ];
        $dbman = $DB->get_manager();
        foreach ($fields as $table => $columns) {
            if (!$dbman->table_exists($table)) {
                continue;
            }
            foreach ($columns as $field) {
                if ($dbman->field_exists($table, $field)) {
                    $DB->execute("UPDATE {{$table}} SET $field = $field - :s WHERE $field > 0", ['s' => $shift]);
                }
            }
        }
    }

    /**
     * Gives a convenor many modules, each with a tagged assessment.
     *
     * @Given /^"(?P<user>[^"]*)" convenes (?P<n>\d+) modules with tagged assessments$/
     * @param string $username
     * @param int $count
     */
    public function convenes_modules(string $username, int $count): void {
        global $DB;
        $gen = testing_util::get_data_generator();
        $userid = self::userid($username);
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'moduleconvenor'], MUST_EXIST);
        for ($i = 1; $i <= $count; $i++) {
            $course = $gen->create_course(['shortname' => "BULK$i", 'fullname' => "Bulk module $i"]);
            $gen->enrol_user($userid, $course->id, $roleid);
            $a = $gen->create_module('assign', ['course' => $course->id, 'name' => "Bulk assessment $i",
                'duedate' => time() + 7 * DAYSECS]);
            core_tag_tag::set_item_tags('core', 'course_modules', $a->cmid, context_module::instance($a->cmid), ['summative']);
        }
        $this->catch_up();
    }

    // Navigation.

    /**
     * Logs in and opens the dashboard after the index catches up.
     *
     * @Given /^I log in as "(?P<user>[^"]*)" and view my dashboard$/
     * @param string $username
     */
    public function i_log_in_and_view_my_dashboard(string $username): void {
        $this->catch_up();
        $this->execute('behat_auth::i_log_in_as', [$username]);
        $this->i_view_my_dashboard_again();
    }

    /**
     * Opens the dashboard after the index catches up.
     *
     * @When /^I view my dashboard again$/
     */
    public function i_view_my_dashboard_again(): void {
        $this->catch_up();
        $this->getSession()->visit($this->locate_path('/my/'));
        $this->wait_for_pending_js();
    }

    /**
     * Logs in and opens one view.
     *
     * @Given /^I log in as "(?P<user>[^"]*)" and view the "(?P<view>[^"]*)"(?: for academic year "(?P<year>[^"]*)")?$/
     * @param string $username
     * @param string $view
     * @param string $year
     */
    public function i_log_in_and_view(string $username, string $view, string $year = ''): void {
        $this->i_log_in_and_view_my_dashboard($username);
        $this->open_view($view);
        if ($year !== '') {
            $this->execute('behat_forms::i_set_the_field_to', [get_string('academicyear', 'block_le_dashboard'), $year]);
            $this->wait_for_pending_js();
        }
    }

    /**
     * Opens one view again.
     *
     * @When /^I view the "(?P<view>[^"]*)" again$/
     * @param string $view
     */
    public function i_view_again(string $view): void {
        $this->i_view_my_dashboard_again();
        $this->open_view($view);
    }

    /**
     * Clicks a view's tab, unless it is the only (or already active) view.
     *
     * @param string $view
     */
    protected function open_view(string $view): void {
        $tab = $this->view_tab($view);
        if ($tab && !$tab->hasClass('active')) {
            $tab->click();
            $this->wait_for_pending_js();
        }
    }

    /**
     * A view's tab.
     *
     * @param string $view
     * @return \Behat\Mink\Element\NodeElement|null
     */
    protected function view_tab(string $view): ?\Behat\Mink\Element\NodeElement {
        return $this->getSession()->getPage()->find('xpath', "//div[contains(@class, 'block_le_dashboard')]" .
            "//button[@data-action='switch-view'][normalize-space(.) = " . behat_context_helper::escape($view) . "]");
    }

    /**
     * The key of a view from its tab label.
     *
     * @param string $label
     * @return string
     */
    protected static function view_key(string $label): string {
        foreach (['student', 'staff', 'convenor'] as $key) {
            if (get_string('view_' . $key, 'block_le_dashboard') === $label) {
                return $key;
            }
        }
        throw new coding_exception("Unknown view '$label'");
    }

    /**
     * The key of the view on screen.
     *
     * @return string|null
     */
    protected function view_on_screen(): ?string {
        $root = $this->getSession()->getPage()->find('css', self::BLOCK . ' [data-region="ma-view-root"]');
        return $root ? $root->getAttribute('data-view') : null;
    }

    /**
     * Whether a view can be opened: it has a tab, or it is the only view and is on screen.
     *
     * @Then /^I should (?P<not>not )?be able to open the "(?P<view>[^"]*)"$/
     * @param string $not
     * @param string $view
     */
    public function i_should_be_able_to_open(string $not, string $view): void {
        $can = $this->view_tab($view) || $this->view_on_screen() === self::view_key($view);
        if ($not && $can) {
            throw new ExpectationException("The '$view' can be opened", $this->getSession());
        }
        if (!$not && !$can) {
            throw new ExpectationException("The '$view' can't be opened", $this->getSession());
        }
    }

    /**
     * Which view is on screen.
     *
     * @Then /^the "(?P<view>[^"]*)" should be open$/
     * @param string $view
     */
    public function the_view_should_be_open(string $view): void {
        $open = $this->view_on_screen();
        if ($open !== self::view_key($view)) {
            throw new ExpectationException("The '$view' is not open (open: '$open')", $this->getSession());
        }
    }

    // Activity icons and tips.

    /**
     * A row's activity icon button.
     *
     * @param string $name
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function activity_icon(string $name): \Behat\Mink\Element\NodeElement {
        return $this->row($name)->find('css', '.ma-row-iconbtn')
            ?? throw new ExpectationException("'$name' has no activity icon", $this->getSession());
    }

    /**
     * Clicks a row's activity icon.
     *
     * @When /^I click on the activity icon of "(?P<name>[^"]*)"$/
     * @param string $name
     */
    public function i_click_on_the_activity_icon(string $name): void {
        $this->activity_icon($name)->click();
    }

    /**
     * Hovers over a row's activity icon.
     *
     * @When /^I hover over the activity icon of "(?P<name>[^"]*)"$/
     * @param string $name
     */
    public function i_hover_over_the_activity_icon(string $name): void {
        $this->activity_icon($name)->mouseOver();
    }

    /**
     * Presses Tab until a row's activity icon has focus.
     *
     * @When /^I press the tab key until the activity icon of "(?P<name>[^"]*)" is focused$/
     * @param string $name
     */
    public function i_tab_to_the_activity_icon(string $name): void {
        $id = $this->activity_icon($name)->getAttribute('aria-controls');
        $js = 'return document.activeElement && document.activeElement.getAttribute("aria-controls") === ' . json_encode($id);
        for ($i = 0; $i < 100; $i++) {
            if ($this->evaluate_script($js)) {
                return;
            }
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
        }
        throw new ExpectationException("Tab never reached the activity icon of '$name'", $this->getSession());
    }

    /**
     * A tip's text, or null when it is not shown.
     *
     * @param \Behat\Mink\Element\NodeElement $trigger
     * @return string|null
     */
    protected function tip_text(\Behat\Mink\Element\NodeElement $trigger): ?string {
        $tip = $this->getSession()->getPage()->find('css', '#' . $trigger->getAttribute('aria-controls'));
        return $tip && $tip->isVisible() ? trim(preg_replace('/\s+/', ' ', $tip->getText())) : null;
    }

    /**
     * What a row's activity icon tip says.
     *
     * @Then /^the activity icon tooltip of "(?P<name>[^"]*)" should say "(?P<text>[^"]*)"$/
     * @param string $name
     * @param string $text
     */
    public function the_activity_icon_tooltip_should_say(string $name, string $text): void {
        $icon = $this->activity_icon($name);
        $shown = $this->tip_text($icon);
        if ($shown !== $text) {
            throw new ExpectationException("The tip of '$name' says '" . ($shown ?? '(not shown)') . "'", $this->getSession());
        }
        if ($icon->getAttribute('aria-label') !== $text) {
            throw new ExpectationException("The icon of '$name' is not named '$text'", $this->getSession());
        }
    }

    /**
     * A row's activity icon tip is closed.
     *
     * @Then /^the activity icon tooltip of "(?P<name>[^"]*)" should not be visible$/
     * @param string $name
     */
    public function the_activity_icon_tooltip_should_not_be_visible(string $name): void {
        if ($this->tip_text($this->activity_icon($name)) !== null) {
            throw new ExpectationException("The tip of '$name' is open", $this->getSession());
        }
    }

    /**
     * A row's activity icon is the module's own, as on the course page (Pierre's UAT, 2026-10-19).
     *
     * @Then /^the activity icon of "(?P<name>[^"]*)" should be the "(?P<mod>[a-z]+)" module's own icon$/
     * @param string $name
     * @param string $mod
     */
    public function the_activity_icon_should_be_the_modules(string $name, string $mod): void {
        global $OUTPUT;
        $img = $this->activity_icon($name)->find('css', '.ma-row-modicon img.activityicon');
        $expected = $OUTPUT->image_url('monologo', $mod)->out(false);
        if (!$img || $img->getAttribute('src') !== $expected) {
            $src = $img ? $img->getAttribute('src') : 'no image';
            throw new ExpectationException("The icon of '$name' is $src, not $expected", $this->getSession());
        }
    }

    /**
     * A row's activity icon has its module's purpose colour.
     *
     * @Then /^the activity icon of "(?P<name>[^"]*)" should use the "(?P<purpose>[a-z]+)" purpose colour$/
     * @param string $name
     * @param string $purpose
     */
    public function the_activity_icon_should_use_purpose(string $name, string $purpose): void {
        if (!$this->activity_icon($name)->find('css', '.ma-row-modicon.activityiconcontainer.' . $purpose)) {
            throw new ExpectationException("The icon of '$name' doesn't use the $purpose colour", $this->getSession());
        }
    }

    /**
     * Which modifier a row's activity icon carries (overdue, notsubmitted, submitted, late, duesoon,
     * selfcert, extension).
     *
     * @Then /^the activity icon of "(?P<name>[^"]*)" should (?P<not>not )?have the "(?P<state>[a-z]+)" modifier$/
     * @param string $name
     * @param string $not
     * @param string $state
     */
    public function the_activity_icon_should_have_modifier(string $name, string $not, string $state): void {
        $has = (bool)$this->row($name)->find('css', '.ma-row-overlay--' . $state);
        if ($has === ($not !== '')) {
            throw new ExpectationException(
                "The icon of '$name' " . ($has ? 'has' : 'has no') . " $state modifier",
                $this->getSession()
            );
        }
    }

    /**
     * Switches off an activity's due date, as unticking Enable in its settings does.
     *
     * @When /^the due date of "(?P<name>[^"]*)" is switched off$/
     * @param string $name
     */
    public function the_due_date_is_switched_off(string $name): void {
        global $CFG, $DB;
        $cm = $this->cm($name);
        $field = ['quiz' => 'timeclose', 'workshop' => 'submissionend', 'coursework' => 'deadline'][$cm->modname] ?? 'duedate';
        $DB->set_field($cm->modname, $field, 0, ['id' => $cm->instance]);
        if ($cm->modname === 'assign') {
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            $assign = new assign(context_module::instance($cm->id), null, null);
            $assign->update_calendar($cm->id);
        }
        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm(get_fast_modinfo($cm->course)->get_cm($cm->id))->trigger();
    }

    /**
     * Sets the brief guidance link.
     *
     * @Given /^the My assessments block guidance link is "(?P<url>[^"]*)"$/
     * @param string $url
     */
    public function the_guidance_link_is(string $url): void {
        set_config('briefguidanceurl', $url, 'block_le_dashboard');
        // The old setting, carried into its guidance box as the upgrade does.
        \block_le_dashboard\local\guidance::migrate();
    }

    /**
     * Sets how long Past work keeps Mitigate after the deadline.
     *
     * @Given /^the My assessments Mitigate stays open "(?P<hours>\d+)" hours after the deadline$/
     * @param int $hours
     */
    public function the_mitigate_window_is(int $hours): void {
        set_config('mitigationcloses', $hours, 'block_le_dashboard');
    }

    /**
     * What the block_rhb stand-in offers (tests/fixtures/rhb_stub.php).
     *
     * @Given /^block_rhb offers "(?P<what>a link|a delete link|a message only|nothing|no link|an unreadable link)" for (?P<which>self-certification|the request form)$/
     * @param string $what
     * @param string $which
     */
    public function block_rhb_offers(string $what, string $which): void {
        set_config($which === 'the request form' ? 'behatrhbsrm' : 'behatrhbselfcert', $what, 'block_le_dashboard');
    }

    /**
     * Sets the feedback contact.
     *
     * @Given /^the My assessments feedback contact is "(?P<text>[^"]*)" linking to "(?P<url>[^"]*)"$/
     * @param string $text
     * @param string $url
     */
    public function the_feedback_contact_is(string $text, string $url): void {
        set_config('feedbackcontact', $text, 'block_le_dashboard');
        set_config('feedbackcontacturl', $url, 'block_le_dashboard');
        // The old setting, carried into its guidance box as the upgrade does.
        \block_le_dashboard\local\guidance::migrate();
    }

    /**
     * A link in the block, by its text and address.
     *
     * @Then /^I should see "(?P<text>[^"]*)" linking to "(?P<url>[^"]*)"$/
     * @param string $text
     * @param string $url
     */
    public function i_should_see_linking_to(string $text, string $url): void {
        foreach ($this->find_all('css', self::BLOCK . ' a') as $link) {
            if ($link->isVisible() && trim($link->getText()) === $text && $link->getAttribute('href') === $url) {
                return;
            }
        }
        throw new ExpectationException("No visible '$text' link to '$url' in the block", $this->getSession());
    }

    /**
     * A link in the block to an activity's settings page.
     *
     * @Then /^I should see "(?P<text>[^"]*)" linking to the settings page of "(?P<name>[^"]*)"$/
     * @param string $text
     * @param string $name
     */
    public function i_should_see_linking_to_settings(string $text, string $name): void {
        $url = (new moodle_url('/course/modedit.php', ['update' => $this->cm($name)->id]))->out(false);
        $this->i_should_see_linking_to($text, $url);
    }

    /**
     * Releases a grade as of a time, so its feedback date is that time.
     *
     * @Given /^the grade of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" was released "(?P<when>[^"]*)"$/
     * @param string $student
     * @param string $name
     * @param string $when
     */
    public function the_grade_was_released(string $student, string $name, string $when): void {
        global $DB;
        $this->the_grade_is_released($student, $name);
        $cm = $this->cm($name);
        $time = self::time($when);
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        $DB->set_field('grade_grades', 'timemodified', $time, ['itemid' => $item->id, 'userid' => self::userid($student)]);
        self::queue($cm);
    }

    /**
     * The rows of a section, in order.
     *
     * @Then /^the "(?P<section>[^"]*)" section should list "(?P<names>[^"]*)" in that order$/
     * @param string $section
     * @param string $names Comma-separated.
     */
    public function the_section_should_list(string $section, string $names): void {
        $xpath = "//div[contains(@class, 'block_le_dashboard')]//section[@data-region='ma-section']" .
            "[.//h3[normalize-space(.) = " . behat_context_helper::escape($section) . "]]//a[contains(@class, 'ma-row-name')]";
        $found = array_map(fn($a) => trim($a->getText()), $this->find_all('xpath', $xpath));
        $expected = array_map('trim', explode(',', $names));
        if ($found !== $expected) {
            throw new ExpectationException("'$section' lists " . implode(', ', $found), $this->getSession());
        }
    }

    /**
     * A row without a status pill.
     *
     * @Then /^"(?P<name>[^"]*)" should have no status pill$/
     * @param string $name
     */
    public function should_have_no_status_pill(string $name): void {
        if ($pill = $this->row($name)->find('css', '[data-status]')) {
            throw new ExpectationException("'$name' has the pill '{$pill->getText()}'", $this->getSession());
        }
    }

    /**
     * An activity icon with no modifier icons.
     *
     * @Then /^the activity icon of "(?P<name>[^"]*)" should have no modifiers$/
     * @param string $name
     */
    public function should_have_no_modifiers(string $name): void {
        if ($this->row($name)->find('css', '.ma-row-overlay')) {
            throw new ExpectationException("The icon of '$name' has modifiers", $this->getSession());
        }
    }

    /**
     * Whether an element of a row takes a tone: its --ma-solid is that tone's.
     *
     * @param \Behat\Mink\Element\NodeElement $node
     * @param string $tone
     * @return bool
     */
    protected function has_tone(\Behat\Mink\Element\NodeElement $node, string $tone): bool {
        $id = 'ma-tone-' . random_string(8);
        $this->execute_script("document.evaluate(" . json_encode($node->getXpath()) . ", document, null, " .
            "XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue.setAttribute('data-tonecheck', '$id')");
        return (bool)$this->evaluate_script("return (function() {
            var el = document.querySelector('[data-tonecheck=\"$id\"]');
            var root = el.closest('.block_le_dashboard_dashboard');
            var get = function(e, p) { return getComputedStyle(e).getPropertyValue(p).trim(); };
            return get(el, '--ma-solid') === get(root, '--ma-$tone-solid');
        })()");
    }

    /**
     * A modifier icon's glyph and tone.
     *
     * @Then /^the "(?P<state>[a-z]+)" modifier of "(?P<name>[^"]*)" should be an? "(?P<icon>[^"]*)" icon in the "(?P<tone>[a-z]+)" tone$/
     * @param string $state
     * @param string $name
     * @param string $icon
     * @param string $tone
     */
    public function the_modifier_should_be(string $state, string $name, string $icon, string $tone): void {
        $badge = $this->row($name)->find('css', '.ma-row-overlay--' . $state)
            ?? throw new ExpectationException("'$name' has no $state modifier", $this->getSession());
        if (!$badge->find('css', '.' . $icon)) {
            throw new ExpectationException("The $state modifier of '$name' is not $icon", $this->getSession());
        }
        if (!$this->has_tone($badge, $tone)) {
            throw new ExpectationException("The $state modifier of '$name' is not in the $tone tone", $this->getSession());
        }
    }

    /**
     * The Mitigate button's icon and tone.
     *
     * @Then /^the Mitigate button of "(?P<name>[^"]*)" should show an? "(?P<icon>[^"]*)" icon in the "(?P<tone>[a-z]+)" tone$/
     * @param string $name
     * @param string $icon
     * @param string $tone
     */
    public function the_mitigate_button_should_show(string $name, string $icon, string $tone): void {
        $button = $this->row($name)->find('css', '.ma-btn--mitigate')
            ?? throw new ExpectationException("'$name' has no Mitigate button", $this->getSession());
        if (!$button->find('css', '.' . $icon) || !$this->has_tone($button, $tone)) {
            throw new ExpectationException("The Mitigate button of '$name' is not a $icon in the $tone tone", $this->getSession());
        }
    }

    /**
     * A row's modifiers are on one line, not stacked.
     *
     * @Then /^the modifiers of "(?P<name>[^"]*)" should sit side by side$/
     * @param string $name
     */
    public function the_modifiers_should_sit_side_by_side(string $name): void {
        $rowid = $this->row($name)->getAttribute('id');
        $tops = $this->evaluate_script("return Array.from(document.querySelectorAll('#$rowid .ma-row-overlay'))" .
            ".map(function(e) { return Math.round(e.getBoundingClientRect().top); })");
        if (count($tops) < 2 || count(array_unique($tops)) !== 1) {
            throw new ExpectationException("The modifiers of '$name' are not side by side", $this->getSession());
        }
    }

    /**
     * Sets the late penalties link.
     *
     * @Given /^the My assessments late penalty link is "(?P<url>[^"]*)"$/
     * @param string $url
     */
    public function the_late_penalty_link_is(string $url): void {
        set_config('latenoteurl', $url, 'block_le_dashboard');
        // The old setting, carried into its guidance box as the upgrade does.
        \block_le_dashboard\local\guidance::migrate();
    }

    /**
     * The late penalties popover sits with the grade.
     *
     * @Then /^the late penalty hint of "(?P<name>[^"]*)" should sit next to its grade$/
     * @param string $name
     */
    public function the_late_hint_should_sit_next_to_grade(string $name): void {
        if (!$this->row($name)->find('css', '[data-region="ma-grade"] [data-hint="late"]')) {
            throw new ExpectationException("The late penalty hint of '$name' is not next to its grade", $this->getSession());
        }
    }

    /**
     * Flags a coursework submission for an academic conduct (plagiarism) check, in mod_coursework's
     * own table. Columns the block doesn't read get their defaults, or 0 / '' when required.
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is flagged for plagiarism(?: with status "(?P<status>[^"]*)")?$/
     * @param string $student
     * @param string $name
     * @param string $status
     */
    public function the_submission_is_flagged(string $student, string $name, string $status = '0'): void {
        global $DB;
        $cm = $this->cm($name);
        $submissionid = $DB->get_field('coursework_submissions', 'id', ['courseworkid' => $cm->instance,
            'allocatableid' => self::userid($student), 'allocatabletype' => 'user'], MUST_EXIST);
        $record = ['courseworkid' => $cm->instance, 'submissionid' => $submissionid, 'status' => $status];
        foreach ($DB->get_columns('coursework_plagiarism_flags') as $column) {
            if ($column->name !== 'id' && !isset($record[$column->name]) && $column->not_null && !$column->has_default) {
                $record[$column->name] = in_array($column->meta_type, ['C', 'X']) ? '' : 0;
            }
        }
        $DB->insert_record('coursework_plagiarism_flags', $record);
        self::queue($cm);
    }

    /** Control names in steps, to the control bar's parts. */
    const CONTROLS = ['ma-year' => 'Academic year', 'ma-type-filter' => 'Type filter',
        'ma-control--apart' => 'Show grades', 'ma-search' => 'Search'];

    /**
     * The controls in the open view's control bar, in order.
     *
     * @Then /^the control bar should hold "(?P<names>[^"]*)"$/
     * @param string $names Comma-separated control names.
     */
    public function the_control_bar_should_hold(string $names): void {
        $found = [];
        foreach ($this->find_all('css', self::BLOCK . ' [data-region="ma-controls"] > .ma-control') as $control) {
            foreach (self::CONTROLS as $class => $name) {
                if ($control->isVisible() && $control->hasClass($class)) {
                    $found[] = $name;
                }
            }
        }
        $expected = array_map('trim', explode(',', $names));
        if ($found !== $expected) {
            throw new ExpectationException('The control bar holds "' . implode(', ', $found) . '"', $this->getSession());
        }
    }

    /**
     * The order of two parts of the open view.
     *
     * @Then /^the (?P<first>control bar|freshness line) should come (?P<rel>before|after) the (?P<second>control bar|summary boxes)$/
     * @param string $first
     * @param string $rel
     * @param string $second
     */
    public function should_come_before(string $first, string $rel, string $second): void {
        $regions = ['control bar' => 'ma-controls', 'freshness line' => 'ma-freshness', 'summary boxes' => 'ma-summary'];
        $root = '.block_le_dashboard [data-region="ma-view-root"]';
        $a = json_encode("$root [data-region=\"{$regions[$first]}\"]");
        $b = json_encode("$root [data-region=\"{$regions[$second]}\"]");
        $follows = $this->evaluate_script("return (function() {
            var a = document.querySelector($a), b = document.querySelector($b);
            return !!(a && b && (b.compareDocumentPosition(a) & Node.DOCUMENT_POSITION_FOLLOWING));
        })()");
        if ($follows !== ($rel === 'after')) {
            throw new ExpectationException("The $first is not $rel the $second", $this->getSession());
        }
    }

    /**
     * Whether a toggle button in the block is pressed.
     *
     * @Then /^"(?P<label>[^"]*)" should (?P<not>not )?be pressed$/
     * @param string $label
     * @param string $not
     */
    public function should_be_pressed(string $label, string $not = ''): void {
        foreach ($this->find_all('css', self::BLOCK . ' button[aria-pressed]') as $button) {
            if ($button->isVisible() && trim($button->getText()) === $label) {
                $pressed = $button->getAttribute('aria-pressed') === 'true';
                // The icon shows the state too: an eye when pressed, crossed out when not.
                $mark = $button->find('css', $pressed ? '.ma-pressed-mark' : '.ma-unpressed-mark');
                if ($pressed === ($not !== '') || !$mark || !$mark->isVisible()) {
                    throw new ExpectationException("'$label' is " . ($pressed ? '' : 'not ') . 'pressed', $this->getSession());
                }
                return;
            }
        }
        throw new ExpectationException("No '$label' toggle in the block", $this->getSession());
    }

    /**
     * Whether a row's released grade is shown.
     *
     * @Then /^the grade of "(?P<name>[^"]*)" should (?P<not>not )?be visible$/
     * @param string $name
     * @param string $not
     */
    public function the_grade_should_be_visible(string $name, string $not = ''): void {
        $grade = $this->row($name)->find('css', '[data-region="ma-grade"]');
        $visible = $grade && $grade->isVisible();
        if ($visible === ($not !== '')) {
            throw new ExpectationException("The grade of '$name' is " . ($visible ? '' : 'not ') . 'visible', $this->getSession());
        }
    }

    /**
     * The number a summary box shows.
     *
     * @Then /^the "(?P<label>[^"]*)" summary box should show "(?P<value>[^"]*)"$/
     * @param string $label
     * @param string $value
     */
    public function the_summary_box_should_show(string $label, string $value): void {
        foreach ($this->find_all('css', self::BLOCK . ' [data-region="ma-box"]') as $box) {
            if (trim($box->find('css', '.ma-box-label')->getText()) === $label) {
                $shown = trim($box->find('css', '.ma-box-value')->getText());
                if ($shown !== $value) {
                    throw new ExpectationException("The '$label' box shows '$shown'", $this->getSession());
                }
                return;
            }
        }
        throw new ExpectationException("No '$label' summary box", $this->getSession());
    }

    /**
     * A hint's accessible name, which is the title its popover shows.
     *
     * @Then /^the "(?P<key>[a-z]+)" hint of "(?P<name>[^"]*)" should be titled "(?P<title>[^"]*)"$/
     * @param string $key
     * @param string $name
     * @param string $title
     */
    public function the_hint_should_be_titled(string $key, string $name, string $title): void {
        $hint = $this->row($name)->find('css', '[data-hint="' . $key . '"]')
            ?? throw new ExpectationException("'$name' has no $key hint", $this->getSession());
        $shown = (string)$hint->getAttribute('aria-label');
        if ($shown !== $title) {
            throw new ExpectationException("The $key hint of '$name' is titled '$shown'", $this->getSession());
        }
    }

    /**
     * Whether a marker's name on a convenor row links to their email.
     *
     * @Then /^the marker "(?P<marker>[^"]*)" of "(?P<name>[^"]*)" should (?P<not>not )?link to their email$/
     * @param string $marker
     * @param string $name
     * @param string $not
     */
    public function the_marker_should_link_to_their_email(string $marker, string $name, string $not = ''): void {
        foreach ($this->row($name)->findAll('css', '.ma-marker-name') as $el) {
            if (trim($el->getText()) !== $marker) {
                continue;
            }
            $links = $el->getTagName() === 'a' && str_starts_with((string)$el->getAttribute('href'), 'mailto:');
            if ($links === (bool)$not) {
                throw new ExpectationException(
                    "'$marker' " . ($links ? 'links' : 'does not link') . ' to their email',
                    $this->getSession()
                );
            }
            return;
        }
        throw new ExpectationException("'$name' lists no marker '$marker'", $this->getSession());
    }

    /**
     * A site upgraded from before beta27 whose admin never saved the hint and guidance settings.
     *
     * @Given /^the My assessments guidance settings were never saved before the beta27 upgrade$/
     */
    public function guidance_never_saved(): void {
        foreach (\block_le_dashboard\local\guidance::KEYS as $key) {
            unset_config('guidance_' . $key, 'block_le_dashboard');
        }
        foreach (array_keys(\block_le_dashboard\local\config::HINTS) as $name) {
            unset_config($name, 'block_le_dashboard');
        }
        unset_config('feedbackdatemode', 'block_le_dashboard');
    }

    /**
     * Hints and guidance as a site upgraded to beta27 has them (config::keep_today()): every hint on, marking deadlines
     * shown to students as set, and the old guidance text. For scenarios written before beta27's neutral defaults.
     *
     * @Given /^the My assessments block keeps its hints and guidance from before beta27$/
     */
    public function keeps_hints_from_before_beta27(): void {
        $this->guidance_never_saved();
        \block_le_dashboard\local\config::keep_today();
    }

    /**
     * Runs the beta27 upgrade step.
     *
     * @When /^the beta27 upgrade runs$/
     */
    public function the_beta27_upgrade_runs(): void {
        \block_le_dashboard\local\config::keep_today();
    }

    /**
     * A plugin setting's saved value.
     *
     * @Then /^the "(?P<name>[a-z_]+)" setting should (?P<how>contain|be) "(?P<value>[^"]*)"$/
     * @param string $name
     * @param string $how
     * @param string $value
     */
    public function the_setting_should_be(string $name, string $how, string $value): void {
        $saved = get_config('block_le_dashboard', $name);
        $ok = $saved !== false && ($how === 'be' ? (string)$saved === $value : str_contains((string)$saved, $value));
        if (!$ok) {
            throw new ExpectationException("'$name' is " . var_export($saved, true), $this->getSession());
        }
    }

    /**
     * A theme with rounder corners than Bootstrap's default, as Boost Union can set (raw SCSS on Boost).
     *
     * @Given /^the theme sets its border radius to "(?P<radius>[0-9.]+rem)"$/
     * @param string $radius
     */
    public function the_theme_sets_its_border_radius(string $radius): void {
        set_config('scss', ":root { --bs-border-radius: $radius; }", 'theme_boost');
        theme_reset_all_caches();
        self::$themeradius = $radius;
    }

    /** @var string|null The radius the theme was given, in rem. */
    protected static ?string $themeradius = null;

    /**
     * The type filter's outer corners against the search box's.
     *
     * @Then /^the outer corners of the type filter should have the same radius as the search box$/
     */
    public function the_type_filter_outer_corners(): void {
        $js = "(function() {
            var root = document.querySelector('" . self::BLOCK . "');
            var r = function(el, corner) { return getComputedStyle(el)['border' + corner + 'Radius']; };
            var buttons = root.querySelectorAll('.ma-type-filter > .ma-filter-btn');
            var search = root.querySelector('.ma-search .input-group-text');
            return JSON.stringify({search: r(search, 'TopLeft'), first: [r(buttons[0], 'TopLeft'), r(buttons[0], 'BottomLeft')],
                last: [r(buttons[buttons.length - 1], 'TopRight'), r(buttons[buttons.length - 1], 'BottomRight')]});
        })()";
        $got = json_decode($this->evaluate_script($js), true);
        $corners = array_merge($got['first'], $got['last']);
        // The theme's radius must have reached the page, or the check proves nothing.
        $expected = self::$themeradius === null ? null : round((float)self::$themeradius * 16) . 'px';
        if (($expected !== null && $got['search'] !== $expected) || array_unique($corners) !== [$got['search']]) {
            throw new ExpectationException('The type filter has corners ' . implode(', ', $corners) . ' and the search box '
                . $got['search'], $this->getSession());
        }
    }

    /**
     * The joins between the type filter's buttons.
     *
     * @Then /^the inner corners of the type filter should be square$/
     */
    public function the_type_filter_inner_corners(): void {
        $js = "(function() {
            var buttons = document.querySelectorAll('" . self::BLOCK . " .ma-type-filter > .ma-filter-btn');
            var out = [];
            buttons.forEach(function(b, i) {
                var cs = getComputedStyle(b);
                if (i > 0) { out.push(cs.borderTopLeftRadius, cs.borderBottomLeftRadius); }
                if (i < buttons.length - 1) { out.push(cs.borderTopRightRadius, cs.borderBottomRightRadius); }
            });
            return JSON.stringify(out);
        })()";
        $corners = json_decode($this->evaluate_script($js), true);
        if (array_unique($corners) !== ['0px']) {
            throw new ExpectationException('Inner corners: ' . implode(', ', $corners), $this->getSession());
        }
    }

    /**
     * Picks a summary box's window, as the user's own preference, then reloads the page (with or without JavaScript).
     *
     * @When /^I set the "(?P<label>[^"]*)" summary box window to "(?P<option>[^"]*)"$/
     * @param string $label
     * @param string $option
     */
    public function i_set_the_summary_box_window(string $label, string $option): void {
        foreach ($this->find_all('css', self::BLOCK . ' [data-region="ma-box"]') as $box) {
            if (trim($box->find('css', '.ma-box-label')->getText()) !== $label) {
                continue;
            }
            foreach ($box->findAll('css', 'select option') as $opt) {
                if (trim($opt->getText()) === $option) {
                    set_user_preference('block_le_dashboard_window', $opt->getAttribute('value'), $this->get_session_user());
                    $this->getSession()->reload();
                    return;
                }
            }
            throw new ExpectationException("The '$label' box has no '$option' option", $this->getSession());
        }
        throw new ExpectationException("No '$label' summary box", $this->getSession());
    }

    /**
     * Nothing in the block is wider than the block.
     *
     * @Then /^the My assessments block should not scroll sideways$/
     */
    public function the_block_should_not_scroll_sideways(): void {
        $wide = $this->evaluate_script("return (function() {
            var view = document.querySelector('.block_le_dashboard [data-region=\"ma-view-root\"]');
            return view ? view.scrollWidth - view.clientWidth : -1;
        })()");
        if ($wide < 0 || $wide > 0) {
            throw new ExpectationException("The block is {$wide}px wider than it shows", $this->getSession());
        }
    }

    /**
     * The search box fills its own line of the control bar once the bar wraps.
     *
     * @Then /^the search box should take the full width of the control bar$/
     */
    public function the_search_should_take_full_width(): void {
        $gap = $this->evaluate_script("return (function() {
            var bar = document.querySelector('.block_le_dashboard [data-region=\"ma-controls\"]');
            var search = bar && bar.querySelector('.ma-search');
            return search ? Math.round(bar.clientWidth - search.getBoundingClientRect().width) : -1;
        })()");
        if ($gap < 0 || $gap > 2) {
            throw new ExpectationException("The search box is {$gap}px short of the control bar", $this->getSession());
        }
    }

    /** Hint names in steps, to the hint keys rows carry. */
    const HINTS = ['brief' => 'brief', 'unallocated' => 'unallocated', 'returned work' => 'returned',
        'returned' => 'returned', 'feedback contact' => 'feedbackcontact', 'feedback' => 'feedbackcontact',
        'feedbackcontact' => 'feedbackcontact', 'extension' => 'extensions', 'extensions' => 'extensions',
        'setup' => 'setup', 'check settings' => 'setup', 'nodeadline' => 'nodeadline', 'received' => 'received', 'late' => 'late',
        'late penalty' => 'late', 'plagiarism' => 'plagiarism', 'hidden' => 'hidden', 'nosubmission' => 'nosubmission',
        'load error' => 'loaderror', 'generic name' => 'genericname', 'defaultmarkingdue' => 'defaultmarkingdue',
        'default marking deadline' => 'defaultmarkingdue'];

    /**
     * Clicks a row's hint.
     *
     * @When /^I click on the (?P<hint>[a-z ]+?) hint of "(?P<name>[^"]*)"$/
     * @param string $hint
     * @param string $name
     */
    public function i_click_on_the_hint(string $hint, string $name): void {
        $button = $this->row($name)->find('css', '[data-hint="' . self::HINTS[$hint] . '"]')
            ?? throw new ExpectationException("'$name' has no $hint hint", $this->getSession());
        $button->click();
    }

    /**
     * Whether a row has a hint.
     *
     * @Then /^"(?P<name>[^"]*)" should (?P<not>not )?have an? "?(?P<hint>[a-z ]+?)"? hint$/
     * @param string $name
     * @param string $not
     * @param string $hint
     */
    public function should_have_a_hint(string $name, string $not, string $hint): void {
        $has = (bool)$this->row($name)->find('css', '[data-hint="' . self::HINTS[$hint] . '"]');
        if ($has === (bool)$not) {
            throw new ExpectationException("'$name' " . ($has ? 'has' : 'has no') . " $hint hint", $this->getSession());
        }
    }

    // Block interaction.

    /**
     * Presses a button in the block.
     *
     * @When /^I press "(?P<button>[^"]*)" in the "My assessments" block$/
     * @param string $button
     */
    public function i_press_in_the_block(string $button): void {
        $this->execute('behat_general::i_click_on_in_the', [$button, 'button', self::BLOCK, 'css_element']);
        $this->wait_for_pending_js();
    }

    /**
     * Searches the block.
     *
     * @When /^I search the "My assessments" block for "(?P<text>[^"]*)"$/
     * @param string $text
     */
    public function i_search_the_block(string $text): void {
        $input = $this->find('css', self::BLOCK . ' input[data-action="search"]');
        $input->setValue($text);
        $this->wait_for_pending_js();
    }

    // Assertions.

    /**
     * Text in the block, or not.
     *
     * @Then /^I should (?P<not>not )?see "(?P<text>[^"]*)" in the "My assessments" block$/
     * @param string $not
     * @param string $text
     */
    public function i_should_see_in_the_block(string $not, string $text): void {
        $step = $not ? 'assert_element_not_contains_text' : 'assert_element_contains_text';
        $this->execute('behat_general::' . $step, [$text, self::BLOCK, 'css_element']);
    }

    /**
     * A row in a section, by section title.
     *
     * @Then /^"(?P<name>[^"]*)" should (?P<not>not )?appear in the "(?P<section>[^"]*)" section(?: with "(?P<n>\d+)" to mark)?$/
     * @param string $name
     * @param string $not
     * @param string $section
     * @param string $tomark
     */
    public function should_appear_in_section(string $name, string $not, string $section, string $tomark = ''): void {
        $xpath = "//div[contains(@class, 'block_le_dashboard')]//section[@data-region='ma-section']" .
            "[.//h3[normalize-space(.) = " . behat_context_helper::escape($section) . "]]" .
            "//li[@data-region='ma-row'][.//a[contains(@class, 'ma-row-name')][normalize-space(.) = " .
            behat_context_helper::escape($name) . "]]";
        $row = $this->getSession()->getPage()->find('xpath', $xpath);
        if ($not) {
            if ($row) {
                throw new ExpectationException("'$name' is in '$section'", $this->getSession());
            }
            return;
        }
        if (!$row) {
            throw new ExpectationException("'$name' is not in '$section'", $this->getSession());
        }
        if ($tomark !== '') {
            $this->should_show_to_mark($name, $tomark);
        }
    }

    /**
     * A row's to-mark count.
     *
     * @Then /^"(?P<name>[^"]*)" (?:should show|shows) "(?P<n>\d+)" to mark$/
     * @param string $name
     * @param string $count
     */
    public function should_show_to_mark(string $name, string $count): void {
        $actual = $this->row($name)->getAttribute('data-tomark') ?? '0';
        if ($actual !== $count) {
            throw new ExpectationException("'$name' shows $actual to mark, not $count", $this->getSession());
        }
    }

    /**
     * An action button on a row, such as Submit or View.
     *
     * @Then /^"(?P<name>[^"]*)" should (?P<not>not )?have an? "(?P<label>[^"]*)" button$/
     * @param string $name
     * @param string $not
     * @param string $label
     */
    public function row_should_have_button(string $name, string $not, string $label): void {
        $has = false;
        foreach ($this->row($name)->findAll('css', 'a.ma-btn, button.ma-btn') as $button) {
            $text = $button->find('css', '.accesshide');
            $shown = trim(str_replace($text ? $text->getText() : '', '', $button->getText()));
            $has = $has || $shown === $label;
        }
        if ($has === (bool)$not) {
            throw new ExpectationException("'$name' " . ($not ? 'has' : 'has no') . " '$label' button", $this->getSession());
        }
    }

    /**
     * Text in a row, or a dated item.
     *
     * @Then /^"(?P<name>[^"]*)" should (?P<not>not )?show "(?P<text>[^"]*)"(?: with the date "(?P<when>[^"]*)")?$/
     * @param string $name
     * @param string $not
     * @param string $text
     * @param string $when
     */
    public function row_should_show(string $name, string $not, string $text, string $when = ''): void {
        $row = $this->row($name);
        if ($when === '') {
            $shown = $this->row_text($row);
            if (str_contains($shown, $text) === (bool)$not) {
                // What the row does show, so a failure on CI can be read from the log.
                $what = $not ? 'shows' : 'does not show';
                throw new ExpectationException("'$name' $what '$text': '$shown'", $this->getSession());
            }
            return;
        }
        $this->row_date($row, $name, $text, $when);
    }

    /**
     * The text a row shows, leaving out closed popovers: without a browser, getText() would read them too.
     *
     * @param \Behat\Mink\Element\NodeElement $row
     * @return string
     */
    protected function row_text(\Behat\Mink\Element\NodeElement $row): string {
        if ($this->running_javascript()) {
            return $row->getText();
        }
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $row->getHtml() . '</div>');
        $xpath = new DOMXPath($doc);
        foreach (iterator_to_array($xpath->query("//*[@data-region='ma-tip'][@hidden]")) as $tip) {
            $tip->parentNode->removeChild($tip);
        }
        return trim(preg_replace('/\s+/', ' ', $doc->textContent));
    }

    /**
     * A row's due date.
     *
     * @Then /^"(?P<name>[^"]*)" should show the due date "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function should_show_the_due_date(string $name, string $when): void {
        $this->row_date($this->row($name), $name, get_string('date_due', 'block_le_dashboard'), $when);
    }

    /**
     * Checks the order of the open view's sections, empty (hidden) ones included. A view with no
     * assessments at all shows a message instead of sections.
     *
     * @Then /^the sections should be in the order "(?P<titles>[^"]*)"$/
     * @param string $titles Comma-separated section titles.
     */
    public function the_sections_should_be_in_the_order(string $titles): void {
        $actual = $this->evaluate_script("return Array.from(document.querySelectorAll(
            '.block_le_dashboard [data-region=\"ma-view-root\"] [data-region=\"ma-section\"] .ma-section-title'
        )).map((e) => e.textContent.trim());");
        $expected = array_map('trim', explode(',', $titles));
        if ($actual !== $expected) {
            throw new ExpectationException('Sections are in the order "' . implode(', ', $actual) . '"', $this->getSession());
        }
    }

    /**
     * Checks a labelled date in a row is on the expected day.
     *
     * @param \Behat\Mink\Element\NodeElement $row
     * @param string $name
     * @param string $label
     * @param string $when
     */
    protected function row_date(\Behat\Mink\Element\NodeElement $row, string $name, string $label, string $when): void {
        $xpath = ".//div[@data-date][span[contains(@class, 'ma-row-date-label')][normalize-space(.) = " .
            behat_context_helper::escape($label) . "]]/time";
        $time = $row->find('xpath', $xpath);
        if (!$time) {
            throw new ExpectationException("'$name' has no '$label' date", $this->getSession());
        }
        $expected = date('Y-m-d', self::time($when));
        if (substr($time->getAttribute('datetime'), 0, 10) !== $expected) {
            throw new ExpectationException(
                "'$name' $label is {$time->getAttribute('datetime')}, not $expected",
                $this->getSession()
            );
        }
    }

    /**
     * A progress bar's figures.
     *
     * @Then /^"(?P<name>[^"]*)" should show an? "(?P<label>[^"]*)" progress bar with "(?P<value>\d+)"(?: of "(?P<max>\d+)")?$/
     * @param string $name
     * @param string $label
     * @param string $value
     * @param string $max
     */
    public function should_show_progress_bar(string $name, string $label, string $value, string $max = ''): void {
        $bar = $this->bar($name, $label);
        if (!$bar) {
            throw new ExpectationException("'$name' has no '$label' bar", $this->getSession());
        }
        if ($bar->getAttribute('data-value') !== $value || ($max !== '' && $bar->getAttribute('data-max') !== $max)) {
            throw new ExpectationException("'$name' $label bar is {$bar->getAttribute('data-value')} of " .
                "{$bar->getAttribute('data-max')}", $this->getSession());
        }
    }

    /**
     * No such progress bar.
     *
     * @Then /^"(?P<name>[^"]*)" should not show an? "(?P<label>[^"]*)" progress bar$/
     * @param string $name
     * @param string $label
     */
    public function should_not_show_progress_bar(string $name, string $label): void {
        if ($this->bar($name, $label)) {
            throw new ExpectationException("'$name' has a '$label' bar", $this->getSession());
        }
    }

    /**
     * Exactly these bars, in order.
     *
     * @Then /^"(?P<name>[^"]*)" should show progress bars "(?P<labels>[^"]*)"$/
     * @param string $name
     * @param string $labels
     */
    public function should_show_progress_bars(string $name, string $labels): void {
        $actual = array_map(
            fn($n) => trim($n->getText()),
            $this->row($name)->findAll(
                'xpath',
                "./div[contains(@class, 'ma-row-status')]/div[@data-bar]/span[contains(@class, 'ma-bar-label')]"
            )
        );
        $expected = array_map('trim', explode(',', $labels));
        if ($actual !== $expected) {
            throw new ExpectationException("'$name' shows bars " . implode(', ', $actual), $this->getSession());
        }
    }

    /**
     * The grading status of a finished staff row.
     *
     * @Then /^"(?P<name>[^"]*)" should show the grading status "(?P<text>[^"]*)"$/
     * @param string $name
     * @param string $text
     */
    public function should_show_grading_status(string $name, string $text): void {
        $status = $this->row($name)->find('css', '[data-grading]');
        $actual = $status ? trim($status->getText()) : null;
        if ($actual !== $text) {
            throw new ExpectationException("'$name' shows the grading status '$actual'", $this->getSession());
        }
    }

    /**
     * No grading status.
     *
     * @Then /^"(?P<name>[^"]*)" should not show a grading status$/
     * @param string $name
     */
    public function should_not_show_grading_status(string $name): void {
        if ($this->row($name)->find('css', '[data-grading]')) {
            throw new ExpectationException("'$name' shows a grading status", $this->getSession());
        }
    }

    /**
     * No bars on the row itself (they may be under Show marking).
     *
     * @Then /^"(?P<name>[^"]*)" should not show any progress bars$/
     * @param string $name
     */
    public function should_not_show_any_bars(string $name): void {
        if ($this->row($name)->find('xpath', "./div[contains(@class, 'ma-row-status')]/div[@data-bar]")) {
            throw new ExpectationException("'$name' shows progress bars", $this->getSession());
        }
    }

    /**
     * Presses a button on a row.
     *
     * @When /^I press "(?P<button>[^"]*)" in the "(?P<name>[^"]*)" row$/
     * @param string $button
     * @param string $name
     */
    public function i_press_in_the_row(string $button, string $name): void {
        $this->row($name)->pressButton($button);
    }

    /**
     * Markers listed.
     *
     * @Then /^"(?P<name>[^"]*)" should list markers? "(?P<m1>[^"]*)"(?: and "(?P<m2>[^"]*)")?$/
     * @param string $name
     * @param string $m1
     * @param string $m2
     */
    public function should_list_markers(string $name, string $m1, string $m2 = ''): void {
        $names = array_map(fn($n) => $n->getHtml(), $this->row($name)->findAll('css', '.ma-marker-name'));
        $names = array_map('html_entity_decode', $names);
        foreach (array_filter([$m1, $m2], 'strlen') as $marker) {
            if (!in_array($marker, $names)) {
                throw new ExpectationException("'$name' does not list $marker", $this->getSession());
            }
        }
    }

    /**
     * A summary count.
     *
     * @Then /^the "(?P<box>[^"]*)"(?: summary)? count should be "(?P<n>\d+)"$/
     * @param string $box
     * @param string $count
     */
    public function the_summary_count_should_be(string $box, string $count): void {
        $xpath = "//div[contains(@class, 'block_le_dashboard')]//div[@data-region='ma-box']" .
            "[div/div[contains(@class, 'ma-box-label')][normalize-space(.) = " . behat_context_helper::escape($box) . "]]" .
            "//div[@data-region='ma-box-value']";
        $node = $this->find('xpath', $xpath, new ExpectationException("No '$box' summary", $this->getSession()));
        if (trim($node->getText()) !== $count) {
            throw new ExpectationException("'$box' count is {$node->getText()}, not $count", $this->getSession());
        }
    }

    /**
     * The freshness line, or not.
     *
     * @Then /^I should (?P<not>not )?see "Updated" with the time (?:the index was last updated|"now")$/
     * @param string $not
     */
    public function i_should_see_updated(string $not = ''): void {
        $node = $this->find('css', self::BLOCK . ' .ma-freshness-text');
        $recent = (bool)preg_match('/^Updated (just now|\d+ min ago)$/', trim($node->getText()));
        if ($recent === (bool)$not) {
            throw new ExpectationException("Freshness says '{$node->getText()}'", $this->getSession());
        }
    }

    /**
     * Ages every assessment's last full index, as if the nightly reconcile last ran then.
     *
     * @Given /^the My assessments index was last updated "(?P<when>[^"]*)"$/
     * @param string $when
     */
    public function the_index_was_last_updated(string $when): void {
        global $DB;
        $DB->set_field('block_le_dashboard_cm', 'timeindexed', self::time($when));
    }

    /**
     * No error on the page.
     *
     * @Then /^I should not see any error$/
     */
    public function i_should_not_see_any_error(): void {
        $page = $this->getSession()->getPage();
        foreach (['.alert-danger', '.errorbox', '.debuggingmessage'] as $selector) {
            if ($page->find('css', $selector)) {
                throw new ExpectationException("Error on the page ($selector)", $this->getSession());
            }
        }
    }

    /**
     * The admin activity types table.
     *
     * @Then /^I should see the following activity types:$/
     * @param TableNode $table
     */
    public function i_should_see_the_following_activity_types(TableNode $table): void {
        foreach ($table->getHash() as $row) {
            $tr = $this->find(
                'xpath',
                "//table[@data-region='ma-types']//tr[th[normalize-space(.) = " .
                behat_context_helper::escape($row['Activity']) . "]]",
                new ExpectationException("No row for {$row['Activity']}", $this->getSession())
            );
            if (!str_contains($tr->getText(), $row['Support'])) {
                throw new ExpectationException("{$row['Activity']} support is not {$row['Support']}", $this->getSession());
            }
            $checked = $tr->find('css', 'input[type=checkbox]')->isChecked();
            if ($checked !== ($row['Enabled'] === 'yes')) {
                throw new ExpectationException("{$row['Activity']} enabled is wrong", $this->getSession());
            }
        }
    }

    /**
     * The admin activity types table shows an error.
     *
     * @Then /^the "(?P<type>[^"]*)" activity type should show its last indexing error$/
     * @param string $type
     */
    public function the_activity_type_should_show_its_last_error(string $type): void {
        $tr = $this->find('xpath', "//table[@data-region='ma-types']//tr[th[normalize-space(.) = " .
            behat_context_helper::escape($type) . "]]");
        if (!$tr->find('css', '.text-danger')) {
            throw new ExpectationException("$type shows no error", $this->getSession());
        }
    }

    /**
     * Deletes a group, which removes its members without a member-removed event.
     *
     * @Given /^the group "(?P<group>[^"]*)" is deleted$/
     * @param string $group
     */
    public function the_group_is_deleted(string $group): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        groups_delete_group($DB->get_field('groups', 'id', ['idnumber' => $group], MUST_EXIST));
    }

    /**
     * Enables or disables an enrolment method of a course.
     *
     * @Given /^the "(?P<method>[^"]*)" enrolment method of "(?P<course>[^"]*)" is (?P<state>enabled|disabled)$/
     * @param string $method
     * @param string $shortname
     * @param string $state
     */
    public function the_enrolment_method_is(string $method, string $shortname, string $state): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => self::course($shortname)->id, 'enrol' => $method], '*', MUST_EXIST);
        enrol_get_plugin($method)->update_status($instance, $state === 'enabled' ? ENROL_INSTANCE_ENABLED : ENROL_INSTANCE_DISABLED);
    }

    /**
     * Deletes an activity from the course page: it is only flagged, and core's deletion task
     * is held back, as when the recycle bin's backup fails (EC7).
     *
     * @Given /^"(?P<name>[^"]*)" is deleted from the course page$/
     * @param string $name
     */
    public function deleted_from_the_course_page(string $name): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        course_delete_module($this->cm($name)->id, true);
        $DB->delete_records('task_adhoc', ['classname' => '\\core_course\\task\\course_delete_modules']);
    }

    /**
     * Reverts an assign submission to draft, as a marker does to let the student rework it.
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is reverted to draft$/
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_reverted_to_draft(string $student, string $name): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cm = $this->cm($name);
        (new assign($cm->context, $cm, null))->revert_to_draft(self::userid($student));
    }

    /**
     * A teacher locks a student's assignment submission (Prevent submission changes).
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is locked by a teacher$/
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_locked(string $student, string $name): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cm = $this->cm($name);
        $assign = new assign($cm->context, $cm, null);
        $flags = $assign->get_user_flags(self::userid($student), true);
        $flags->locked = 1;
        $assign->update_user_flags($flags);
        self::queue($cm);
    }

    /**
     * A teacher locks or unlocks a student's submissions through the assignment, firing Moodle's events. Nothing
     * is queued or run here, so the dashboard shows only what the block's observer did (B1, B4).
     *
     * @When /^"(?P<teacher>[^"]*)" (?P<action>locks|unlocks) submissions for "(?P<student>[^"]*)" on "(?P<name>[^"]*)"$/
     * @param string $teacher
     * @param string $action
     * @param string $student
     * @param string $name
     */
    public function the_teacher_locks_submissions(string $teacher, string $action, string $student, string $name): void {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cm = $this->cm($name);
        $saved = $USER;
        \core\session\manager::set_user(core_user::get_user(self::userid($teacher)));
        try {
            $assign = new assign($cm->context, $cm, null);
            $action === 'locks' ? $assign->lock_submission(self::userid($student))
                : $assign->unlock_submission(self::userid($student));
        } finally {
            \core\session\manager::set_user($saved);
        }
    }

    /**
     * Sets the resubmission contact, and its link.
     *
     * @Given /^the "Resubmission contact" setting is "(?P<text>[^"]*)"(?: with link "(?P<url>[^"]*)")?$/
     * @param string $text
     * @param string $url
     */
    public function the_resubmission_contact_is(string $text, string $url = ''): void {
        set_config('resubmitcontact', $text, 'block_le_dashboard');
        set_config('resubmitcontacturl', $url, 'block_le_dashboard');
    }

    /**
     * Sets a guidance box. Quotes inside the HTML are written \" in the step.
     *
     * @Given /^the "(?P<name>guidance_[a-z]+)" setting is "(?P<html>.*)"$/
     * @param string $name
     * @param string $html
     */
    public function the_guidance_setting_is(string $name, string $html): void {
        set_config($name, str_replace('\\"', '"', $html), 'block_le_dashboard');
    }

    /**
     * Empties a guidance box.
     *
     * @Given /^the "(?P<name>guidance_[a-z]+)" setting is empty$/
     * @param string $name
     */
    public function the_guidance_setting_is_empty(string $name): void {
        set_config($name, '', 'block_le_dashboard');
    }

    /**
     * A popover's guidance block, or null.
     *
     * @param string $key
     * @param string $name
     * @return \Behat\Mink\Element\NodeElement|null
     */
    protected function guidance_block(string $key, string $name): ?\Behat\Mink\Element\NodeElement {
        $tip = $this->find('css', '#' . $this->flag($key, $name)->getAttribute('aria-controls'));
        return $tip->find('css', '.ma-tip-guidance');
    }

    /**
     * A popover with no guidance under its factual line.
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should contain only its factual line$/
     * @param string $key
     * @param string $name
     */
    public function the_popover_has_no_guidance(string $key, string $name): void {
        if ($this->guidance_block($key, $name)) {
            throw new ExpectationException("The $key popover of '$name' has guidance", $this->getSession());
        }
    }

    /**
     * The guidance a popover shows, read from the page (it needn't be open).
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should (?P<not>not )?show the guidance "(?P<text>[^"]*)"$/
     * @param string $key
     * @param string $name
     * @param string $not
     * @param string $text
     */
    public function the_popover_shows_guidance(string $key, string $name, string $not, string $text): void {
        $block = $this->guidance_block($key, $name);
        $shown = $block ? trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($block->getHtml()), ENT_QUOTES))) : '';
        if (str_contains($shown, $text) === (bool)$not) {
            throw new ExpectationException("The $key popover of '$name' has guidance '$shown'", $this->getSession());
        }
    }

    /**
     * A link in a popover's guidance.
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should link "(?P<text>[^"]*)" to "(?P<url>[^"]*)"$/
     * @param string $key
     * @param string $name
     * @param string $text
     * @param string $url
     */
    public function the_popover_guidance_links(string $key, string $name, string $text, string $url): void {
        $block = $this->guidance_block($key, $name);
        foreach ($block ? $block->findAll('css', 'a') : [] as $link) {
            if (trim(html_entity_decode(strip_tags($link->getHtml()), ENT_QUOTES)) === $text && $link->getAttribute('href') === $url) {
                return;
            }
        }
        throw new ExpectationException("No '$text' link to $url in the $key popover of '$name'", $this->getSession());
    }

    /**
     * Nothing from a guidance box ran or was kept as a script.
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should hold no script$/
     * @param string $key
     * @param string $name
     */
    public function the_popover_holds_no_script(string $key, string $name): void {
        $block = $this->guidance_block($key, $name);
        if ($block && stripos($block->getHtml(), '<script') !== false) {
            throw new ExpectationException("The $key popover of '$name' holds a script", $this->getSession());
        }
    }

    /**
     * The guidance's first and last blocks have no outer margins, and it sits under the factual line.
     *
     * @Then /^the guidance in the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should be its own block with no outer margins$/
     * @param string $key
     * @param string $name
     */
    public function the_guidance_has_no_outer_margins(string $key, string $name): void {
        $id = $this->flag($key, $name)->getAttribute('aria-controls');
        $js = "(function () {
            var g = document.getElementById(" . json_encode($id) . ").querySelector('.ma-tip-guidance');
            if (!g || getComputedStyle(g).display !== 'block') { return 'not a block'; }
            var a = getComputedStyle(g.firstElementChild), b = getComputedStyle(g.lastElementChild);
            return a.marginTop + ' ' + b.marginBottom;
        })()";
        $result = $this->evaluate_script($js);
        if ($result !== '0px 0px') {
            throw new ExpectationException("The guidance of '$name' has margins or isn't a block: $result", $this->getSession());
        }
    }

    /**
     * The settings page lists an HTML editor for each guidance box.
     *
     * @Then /^I should see an HTML editor for each guidance setting$/
     */
    public function i_should_see_guidance_editors(): void {
        foreach (\block_le_dashboard\local\guidance::KEYS as $key) {
            $this->find('css', 'textarea[name="s_block_le_dashboard_guidance_' . $key . '"]');
        }
    }

    /**
     * A row's name links to its activity.
     *
     * @Then /^the name of "(?P<name>[^"]*)" should link to its activity$/
     * @param string $name
     */
    public function the_name_links_to_its_activity(string $name): void {
        $link = $this->row($name)->find('css', 'a.ma-row-name');
        if (!$link || $link->getAttribute('href') !== $this->cm($name)->url->out(false)) {
            throw new ExpectationException("The name of '$name' doesn't link to its activity", $this->getSession());
        }
    }

    /**
     * A row's course links to the course page.
     *
     * @Then /^the course of "(?P<name>[^"]*)" should link to the course page of "(?P<course>[^"]*)"$/
     * @param string $name
     * @param string $shortname
     */
    public function the_course_links_to_its_page(string $name, string $shortname): void {
        $url = (new moodle_url('/course/view.php', ['id' => self::course($shortname)->id]))->out(false);
        $link = $this->row($name)->find('css', 'a.ma-row-course');
        if (!$link || $link->getAttribute('href') !== $url) {
            throw new ExpectationException("The course of '$name' doesn't link to $url", $this->getSession());
        }
    }

    /**
     * A marker's name in a row's marking detail links somewhere (their email).
     *
     * @Then /^the marker "(?P<person>[^"]*)" of "(?P<name>[^"]*)" should link to "(?P<url>[^"]*)"$/
     * @param string $person
     * @param string $name
     * @param string $url
     */
    public function the_marker_links_to(string $person, string $name, string $url): void {
        foreach ($this->row($name)->findAll('css', 'a.ma-marker-name') as $link) {
            if (trim(html_entity_decode($link->getHtml(), ENT_QUOTES)) === $person && $link->getAttribute('href') === $url) {
                return;
            }
        }
        throw new ExpectationException("'$person' on '$name' doesn't link to $url", $this->getSession());
    }

    /**
     * A course in the untagged activities list links to its page.
     *
     * @Then /^the course "(?P<fullname>[^"]*)" in the untagged activities list should link to the course page of "(?P<course>[^"]*)"$/
     * @param string $fullname
     * @param string $shortname
     */
    public function the_untagged_course_links(string $fullname, string $shortname): void {
        $url = (new moodle_url('/course/view.php', ['id' => self::course($shortname)->id]))->out(false);
        foreach ($this->untagged_list()->findAll('css', '.ma-untagged-course > summary a') as $link) {
            if (trim(html_entity_decode($link->getHtml(), ENT_QUOTES)) === $fullname && $link->getAttribute('href') === $url) {
                return;
            }
        }
        throw new ExpectationException("'$fullname' in the untagged list doesn't link to $url", $this->getSession());
    }

    /**
     * The "Can't resubmit" control of a row.
     *
     * @param string $name
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function lock_control(string $name): \Behat\Mink\Element\NodeElement {
        return $this->row($name)->find('css', '[data-hint="locked"]')
            ?? throw new ExpectationException("'$name' has no Can't resubmit control", $this->getSession());
    }

    /**
     * Opens the "Can't resubmit" control.
     *
     * @When /^I open the "Can't resubmit" control of "(?P<name>[^"]*)"$/
     * @param string $name
     */
    public function i_open_the_lock_control(string $name): void {
        $this->lock_control($name)->click();
        $this->wait_for_pending_js();
    }

    /**
     * What the "Can't resubmit" explanation says. Without a browser it is read from the page.
     *
     * @Then /^the "Can't resubmit" popover of "(?P<name>[^"]*)" should (?P<not>not )?say "(?P<text>[^"]*)"$/
     * @param string $name
     * @param string $not
     * @param string $text
     */
    public function the_lock_popover_should_say(string $name, string $not, string $text): void {
        $tip = $this->find('css', '#' . $this->lock_control($name)->getAttribute('aria-controls'));
        $shown = $this->running_javascript() ? $tip->getText() : html_entity_decode(strip_tags($tip->getHtml()), ENT_QUOTES);
        $shown = trim(preg_replace('/\s+/', ' ', $shown));
        if (str_contains($shown, $text) === (bool)$not) {
            throw new ExpectationException("The Can't resubmit popover of '$name' says '$shown'", $this->getSession());
        }
    }

    /**
     * Opens every popover, tip and menu in the dashboard one at a time, and checks each one opens next to the
     * control that opened it (within 16 px) and inside the window (Pierre, 2026-10-09: "check anchoring of all
     * popover, tooltips and other things, for all breakpoints").
     *
     * @Then /^every popover in the dashboard should open next to its control and on screen$/
     */
    public function every_popover_opens_next_to_its_control(): void {
        $controls = '.block_le_dashboard [data-action="tip"], .block_le_dashboard [data-region="ma-mitigate"] [data-toggle="dropdown"]';
        $count = (int)$this->evaluate_script("return document.querySelectorAll('$controls').length");
        $problems = [];
        $checked = 0;
        for ($i = 0; $i < $count; $i++) {
            $shown = $this->evaluate_script("return (function() {
                const c = document.querySelectorAll('$controls')[$i];
                const r = c.getBoundingClientRect();
                if (!r.width || !r.height) {
                    return false;
                }
                c.scrollIntoView({block: 'center'});
                c.click();
                return true;
            })()");
            if (!$shown) {
                continue;
            }
            $this->getSession()->wait(400);
            $box = $this->evaluate_script("return (function() {
                const c = document.querySelectorAll('$controls')[$i];
                const pop = c.dataset.toggle === 'dropdown' ? c.parentNode.querySelector('.dropdown-menu')
                    : document.getElementById(c.getAttribute('aria-controls'));
                const b = c.getBoundingClientRect();
                const p = pop.getBoundingClientRect();
                const row = c.closest('[data-region=\"ma-row\"]');
                const name = row ? (row.querySelector('.ma-row-name') || {}).textContent : '';
                return {name: (name || '').trim(), what: c.dataset.hint || c.dataset.region || c.dataset.toggle,
                    open: p.width > 0 && p.height > 0 && getComputedStyle(pop).visibility !== 'hidden',
                    gapx: Math.max(0, p.left - b.right, b.left - p.right), gapy: Math.max(0, p.top - b.bottom, b.top - p.bottom),
                    left: Math.round(p.left), right: Math.round(p.right), width: document.documentElement.clientWidth};
            })()");
            $checked++;
            $where = "{$box['what']} of '{$box['name']}'";
            if (!$box['open']) {
                $problems[] = "$where didn't open";
            } else if ($box['gapx'] > 16 || $box['gapy'] > 16) {
                $problems[] = "$where opened {$box['gapx']} px across and {$box['gapy']} px down from its control";
            } else if ($box['left'] < 0 || $box['right'] > $box['width']) {
                $problems[] = "$where runs off screen ({$box['left']} to {$box['right']} of {$box['width']} px)";
            }
            $this->evaluate_script("return (function() {
                const c = document.querySelectorAll('$controls')[$i];
                if (c.getAttribute('aria-expanded') === 'true') {
                    c.click();
                }
                return true;
            })()");
            $this->getSession()->wait(200);
        }
        if (!$checked) {
            throw new ExpectationException('No popovers to check', $this->getSession());
        }
        if ($problems) {
            throw new ExpectationException("Of $checked popovers:\n" . implode("\n", $problems), $this->getSession());
        }
    }

    /**
     * The "Can't resubmit" popover sits under its button and overlaps it sideways (beta22: it opened at the
     * far left of the list).
     *
     * @Then /^the "Can't resubmit" popover of "(?P<name>[^"]*)" should open under its button$/
     * @param string $name
     */
    public function the_lock_popover_opens_under_its_button(string $name): void {
        $id = $this->lock_control($name)->getAttribute('aria-controls');
        $box = $this->evaluate_script("return (function() {
            const tip = document.getElementById('$id');
            const b = document.querySelector('[aria-controls=\"$id\"]').getBoundingClientRect();
            const t = tip.getBoundingClientRect();
            return {open: !tip.hidden, under: t.top >= b.bottom - 1 && t.top <= b.bottom + 24,
                beside: t.left <= b.right && t.right >= b.left, tip: [t.left, t.top, t.right], button: [b.left, b.bottom, b.right]};
        })()");
        if (!$box['open'] || !$box['under'] || !$box['beside']) {
            throw new ExpectationException("The Can't resubmit popover of '$name' isn't under its button: "
                . json_encode($box), $this->getSession());
        }
    }

    /**
     * The "Can't resubmit" popover is inside the window.
     *
     * @Then /^the "Can't resubmit" popover of "(?P<name>[^"]*)" should be fully on screen$/
     * @param string $name
     */
    public function the_lock_popover_is_on_screen(string $name): void {
        $id = $this->lock_control($name)->getAttribute('aria-controls');
        $box = $this->evaluate_script("return (function() {
            const t = document.getElementById('$id').getBoundingClientRect();
            return {left: t.left, right: t.right, width: document.documentElement.clientWidth};
        })()");
        if ($box['left'] < 0 || $box['right'] > $box['width']) {
            throw new ExpectationException(
                "The Can't resubmit popover of '$name' runs off screen: " . json_encode($box),
                $this->getSession()
            );
        }
    }

    /**
     * A link in the "Can't resubmit" explanation.
     *
     * @Then /^"(?P<text>[^"]*)" in the "Can't resubmit" popover of "(?P<name>[^"]*)" should link to "(?P<url>[^"]*)"$/
     * @param string $text
     * @param string $name
     * @param string $url
     */
    public function the_lock_popover_should_link(string $text, string $name, string $url): void {
        $tip = $this->find('css', '#' . $this->lock_control($name)->getAttribute('aria-controls'));
        foreach ($tip->findAll('css', 'a') as $link) {
            if (trim(html_entity_decode(strip_tags($link->getHtml()), ENT_QUOTES)) === $text && $link->getAttribute('href') === $url) {
                return;
            }
        }
        throw new ExpectationException("No '$text' link to $url in the popover of '$name'", $this->getSession());
    }

    /**
     * The control is a button screen readers hear the explanation from.
     *
     * @Then /^the "Can't resubmit" control of "(?P<name>[^"]*)" should be a button described by its explanation$/
     * @param string $name
     */
    public function the_lock_control_is_described(string $name): void {
        $control = $this->lock_control($name);
        $describedby = (string)$control->getAttribute('aria-describedby');
        $tip = $this->find('css', '#' . $control->getAttribute('aria-controls'));
        if ($control->getTagName() !== 'button' || $describedby === '' || !$tip->find('css', '#' . $describedby)) {
            throw new ExpectationException(
                "The Can't resubmit control of '$name' isn't described by its explanation",
                $this->getSession()
            );
        }
    }

    /**
     * Removes a course's start date, which core's API refuses while it has an end date.
     *
     * @Given /^the start date of "(?P<course>[^"]*)" is removed$/
     * @param string $shortname
     */
    public function the_start_date_is_removed(string $shortname): void {
        global $DB;
        $id = self::course($shortname)->id;
        $DB->set_field('course', 'startdate', 0, ['id' => $id]);
        rebuild_course_cache($id, true);
    }

    /**
     * Runs the rebuild (the backfill ad hoc task).
     *
     * @When /^the rebuild task runs$/
     */
    public function the_rebuild_task_runs(): void {
        $this->catch_up();
    }

    /**
     * Adds an index row for a course module that doesn't exist, as a lost delete event would leave.
     *
     * @Given /^the index holds a row for an activity that no longer exists$/
     */
    public function the_index_holds_an_orphan(): void {
        global $DB;
        $row = $DB->get_record(\block_le_dashboard\local\index\indexer::CM, [], '*', IGNORE_MULTIPLE);
        unset($row->id);
        $row->cmid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {course_modules}') + 1000;
        $DB->insert_record(\block_le_dashboard\local\index\indexer::CM, $row);
    }

    /**
     * Empties the index as the admin page does, without letting the rebuild run.
     *
     * @Given /^an admin has rebuilt the index and the rebuild task has not run yet$/
     */
    public function the_index_was_rebuilt_but_not_run(): void {
        \block_le_dashboard\local\index\indexer::purge();
        set_config('backfillpending', time(), 'block_le_dashboard');
    }

    // Beta17: flags and popovers, submittability, no online submission, audit fixes.

    /**
     * A row's hint icon by its key.
     *
     * @param string $key
     * @param string $name
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function flag(string $key, string $name): \Behat\Mink\Element\NodeElement {
        $key = self::HINTS[$key] ?? $key;
        return $this->row($name)->find('css', '[data-hint="' . $key . '"]')
            ?? throw new ExpectationException("'$name' has no $key icon", $this->getSession());
    }

    /**
     * Skips the scenario unless the site runs that Moodle version.
     *
     * @Given /^the site runs Moodle (?P<version>4\.5|5\.0 or later)$/
     * @param string $version
     */
    public function the_site_runs_moodle(string $version): void {
        global $CFG;
        if (((int)$CFG->branch >= 500) !== ($version !== '4.5')) {
            throw new \Moodle\BehatExtension\Exception\SkippedException("Needs Moodle $version");
        }
    }

    /**
     * Which Font Awesome icon a row's flag shows.
     *
     * @Then /^the "(?P<key>[a-z ]+)" flag of "(?P<name>[^"]*)" should show the "(?P<icon>fa-[a-z-]+)" icon$/
     * @param string $key
     * @param string $name
     * @param string $icon
     */
    public function the_flag_should_show_the_icon(string $key, string $name, string $icon): void {
        if (!$this->flag($key, $name)->find('css', 'i.' . $icon)) {
            throw new ExpectationException("The $key flag of '$name' doesn't show $icon", $this->getSession());
        }
    }

    /**
     * The plugin's own half-dashed file, used where Moodle's Font Awesome has none (Moodle 4.5).
     *
     * @Then /^the "(?P<key>[a-z ]+)" flag of "(?P<name>[^"]*)" should show the plugin's own half-dashed file$/
     * @param string $key
     * @param string $name
     */
    public function the_flag_should_show_own_half_dashed_file(string $key, string $name): void {
        $this->the_flag_should_show_the_icon($key, $name, 'ma-icon-file-half-dashed');
    }

    /**
     * The plugin's half-dashed file is drawn, as a mask filled with the flag's text colour.
     *
     * @Then /^the "(?P<key>[a-z ]+)" flag of "(?P<name>[^"]*)" should draw the plugin's own half-dashed file in the flag's text colour$/
     * @param string $key
     * @param string $name
     */
    public function the_flag_should_draw_own_half_dashed_file(string $key, string $name): void {
        $this->the_flag_should_show_own_half_dashed_file($key, $name);
        $xpath = json_encode($this->flag($key, $name)->find('css', 'i.ma-icon-file-half-dashed')->getXpath());
        $result = $this->evaluate_script("return (function() {
            const i = document.evaluate($xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;
            const before = getComputedStyle(i, '::before');
            const mask = before.maskImage || before.webkitMaskImage || '';
            if (!mask.includes('file-half-dashed')) {
                return 'no mask: ' + mask;
            }
            if (before.backgroundColor !== getComputedStyle(i).color) {
                return 'filled ' + before.backgroundColor + ', text is ' + getComputedStyle(i).color;
            }
            return parseFloat(before.width) > 0 && parseFloat(before.height) > 0 ? '' : 'not drawn: ' + before.width;
        })();");
        if ($result !== '') {
            throw new ExpectationException("The half-dashed file of '$name' isn't drawn right: $result", $this->getSession());
        }
    }

    /**
     * Waits for a condition for up to two seconds.
     *
     * @param callable $test
     * @return bool
     */
    protected static function within(callable $test): bool {
        for ($i = 0; $i < 20; $i++) {
            if ($test()) {
                return true;
            }
            usleep(100000);
        }
        return (bool)$test();
    }

    /**
     * Rests the pointer on a hint icon for a time.
     *
     * @When /^I hover over the "(?P<key>[a-z]+)" (?:flag|icon) of "(?P<name>[^"]*)" for "(?P<ms>\d+)" milliseconds$/
     * @param string $key
     * @param string $name
     * @param int $ms
     */
    public function i_hover_over_the_flag_for(string $key, string $name, int $ms): void {
        $this->flag($key, $name)->mouseOver();
        usleep($ms * 1000);
    }

    /**
     * Rests the pointer on a row's activity icon for a time.
     *
     * @When /^I hover over the activity icon of "(?P<name>[^"]*)" for "(?P<ms>\d+)" milliseconds$/
     * @param string $name
     * @param int $ms
     */
    public function i_hover_over_the_activity_icon_for(string $name, int $ms): void {
        $this->activity_icon($name)->mouseOver();
        usleep($ms * 1000);
    }

    /**
     * Passes the pointer over a hint icon and away again.
     *
     * @When /^I pass the pointer over the "(?P<key>[a-z]+)" (?:flag|icon) of "(?P<name>[^"]*)" for "(?P<ms>\d+)" milliseconds$/
     * @param string $key
     * @param string $name
     * @param int $ms
     */
    public function i_pass_over_the_flag(string $key, string $name, int $ms): void {
        $this->pass_over(fn() => $this->flag($key, $name), $ms);
    }

    /**
     * Passes the pointer over a row's activity icon and away again.
     *
     * @When /^I pass the pointer over the activity icon of "(?P<name>[^"]*)" for "(?P<ms>\d+)" milliseconds$/
     * @param string $name
     * @param int $ms
     */
    public function i_pass_over_the_activity_icon(string $name, int $ms): void {
        $this->pass_over(fn() => $this->activity_icon($name), $ms);
    }

    /**
     * Checks that no tip opened during the last pass of the pointer.
     *
     * @Then /^no tooltip should have opened$/
     */
    public function no_tooltip_should_have_opened(): void {
        if ($this->evaluate_script('return window.maTipOpened')) {
            throw new ExpectationException('A tooltip opened during the pass', $this->getSession());
        }
    }

    /**
     * Rests the pointer on an element, then moves it to the navigation bar, as one W3C action sequence so the
     * browser times the pause itself; then waits past the hover delay (500ms). Separate WebDriver calls took over
     * 450ms on a loaded runner and opened the tip (beta21). "No tooltip should have opened" checks the pass.
     *
     * @param callable $element Finds the element.
     * @param int $ms
     */
    protected function pass_over(callable $element, int $ms): void {
        $driver = $this->getSession()->getDriver();
        $webdriver = $driver->getWebDriver();
        $origin = function (\Behat\Mink\Element\NodeElement $node) use ($webdriver): array {
            $id = $webdriver->findElement(\Facebook\WebDriver\WebDriverBy::xpath($node->getXpath()))->getID();
            return ['element-6066-11e4-a52e-4f735466cecf' => $id];
        };
        // Records any tip opening during the pass, since the pointer leaving closes it again.
        $this->execute_script('window.maTipOpened = false; new MutationObserver(ms => {
            window.maTipOpened = window.maTipOpened || ms.some(m => m.target.getAttribute("aria-expanded") === "true");
        }).observe(document.body, {subtree: true, attributes: true, attributeFilter: ["aria-expanded"]});');
        $move = fn($node) => ['type' => 'pointerMove', 'duration' => 0, 'origin' => $origin($node), 'x' => 0, 'y' => 0];
        $webdriver->execute(\Facebook\WebDriver\Remote\DriverCommand::ACTIONS, ['actions' => [[
            'type' => 'pointer',
            'id' => 'ma-pass',
            'parameters' => ['pointerType' => 'mouse'],
            'actions' => [$move($element()), ['type' => 'pause', 'duration' => $ms], $move($this->find('css', 'nav.navbar'))],
        ]]]);
        usleep(700000);
    }

    /**
     * Moves the pointer off a row, to the page's navigation bar.
     *
     * @When /^I move the pointer away from "(?P<name>[^"]*)"$/
     * @param string $name
     */
    public function i_move_the_pointer_away(string $name): void {
        $this->row($name);
        $this->find('css', 'nav.navbar')->mouseOver();
        usleep(400000);
    }

    /**
     * Clicks a hint icon.
     *
     * @When /^I click on the "(?P<key>[a-z]+)" (?:flag|icon) of "(?P<name>[^"]*)"$/
     * @param string $key
     * @param string $name
     */
    public function i_click_on_the_flag(string $key, string $name): void {
        $this->flag($key, $name)->click();
    }

    /**
     * Presses Tab until a hint icon has focus.
     *
     * @When /^I press the tab key until the "(?P<key>[a-z]+)" (?:flag|icon) of "(?P<name>[^"]*)" is focused$/
     * @param string $key
     * @param string $name
     */
    public function i_tab_to_the_flag(string $key, string $name): void {
        $id = $this->flag($key, $name)->getAttribute('aria-controls');
        $js = 'return document.activeElement && document.activeElement.getAttribute("aria-controls") === ' . json_encode($id);
        for ($i = 0; $i < 100; $i++) {
            if ($this->evaluate_script($js)) {
                return;
            }
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
        }
        throw new ExpectationException("Tab never reached the $key icon of '$name'", $this->getSession());
    }

    /**
     * A hint's popover title and description.
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should say "(?P<title>[^"]*)" then "(?P<text>[^"]*)"$/
     * @param string $key
     * @param string $name
     * @param string $title
     * @param string $text
     */
    public function the_popover_should_say(string $key, string $name, string $title, string $text): void {
        $flag = $this->flag($key, $name);
        if (!self::within(fn() => $this->tip_text($flag) !== null)) {
            throw new ExpectationException("The $key popover of '$name' is not open", $this->getSession());
        }
        $tip = $this->getSession()->getPage()->find('css', '#' . $flag->getAttribute('aria-controls'));
        $shown = trim(preg_replace('/\s+/', ' ', $tip->find('css', '.ma-tip-title')->getText()));
        $all = $this->tip_text($flag);
        if ($shown !== $title || !str_contains($all, $text) || strpos($all, $text) < strlen($title)) {
            throw new ExpectationException("The $key popover of '$name' says '$all'", $this->getSession());
        }
    }

    /**
     * Whether a hint's popover is open.
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should (?P<not>not )?be visible$/
     * @param string $key
     * @param string $name
     * @param string $not
     */
    public function the_popover_should_be_visible(string $key, string $name, string $not = ''): void {
        $flag = $this->flag($key, $name);
        $want = !$not;
        if (!self::within(fn() => ($this->tip_text($flag) !== null) === $want)) {
            throw new ExpectationException("The $key popover of '$name' is " . ($want ? 'closed' : 'open'), $this->getSession());
        }
    }

    /**
     * A flag and its number.
     *
     * @Then /^"(?P<name>[^"]*)" should have an? "(?P<key>[a-z]+)" flag showing (?:"(?P<n>\d+)"|no number)$/
     * @param string $name
     * @param string $key
     * @param string $n
     */
    public function should_have_a_flag_showing(string $name, string $key, string $n = ''): void {
        $count = $this->flag($key, $name)->find('css', '.ma-flag-count');
        $shown = $count ? trim($count->getText()) : '';
        if ($shown !== $n) {
            throw new ExpectationException("The $key flag of '$name' shows '$shown'", $this->getSession());
        }
    }

    /**
     * A flag's accessible name.
     *
     * @Then /^the "(?P<key>[a-z]+)" (?:flag|icon) of "(?P<name>[^"]*)" should be labelled "(?P<label>[^"]*)"$/
     * @param string $key
     * @param string $name
     * @param string $label
     */
    public function the_flag_should_be_labelled(string $key, string $name, string $label): void {
        $shown = $this->flag($key, $name)->getAttribute('aria-label');
        if ($shown !== $label) {
            throw new ExpectationException("The $key flag of '$name' is labelled '$shown'", $this->getSession());
        }
    }

    /**
     * Where a student's hint icon sits.
     *
     * @Then /^the "(?P<key>[a-z]+)" icon of "(?P<name>[^"]*)" should sit next to its (?:(?P<grade>grade)|"(?P<pill>[^"]*)" pill|"(?P<date>[^"]*)" date)$/
     * @param string $key
     * @param string $name
     * @param string $grade
     * @param string $pill
     * @param string $date
     */
    public function the_icon_should_sit_next_to(string $key, string $name, string $grade, string $pill = '', string $date = ''): void {
        $key = self::HINTS[$key] ?? $key;
        $row = $this->row($name);
        if ($grade) {
            $where = $row->find('css', '[data-region="ma-grade"]');
        } else if ($pill !== '') {
            $where = $row->find('xpath', ".//div[contains(@class, 'ma-status-wrap')][span[contains(@class, 'ma-pill')]" .
                "[normalize-space(.) = " . behat_context_helper::escape($pill) . "]]");
        } else {
            $where = $row->find('xpath', ".//div[contains(@class, 'ma-row-date')][span[contains(@class, 'ma-row-date-label')]" .
                "[normalize-space(.) = " . behat_context_helper::escape($date) . "]]");
        }
        if (!$where || !$where->find('css', '[data-hint="' . $key . '"]')) {
            throw new ExpectationException("The $key icon of '$name' is not where expected", $this->getSession());
        }
    }

    /**
     * A student's hint is a bare glyph.
     *
     * @Then /^the "(?P<key>[a-z]+)" icon of "(?P<name>[^"]*)" should have no circle$/
     * @param string $key
     * @param string $name
     */
    public function the_icon_should_have_no_circle(string $key, string $name): void {
        if (!$this->flag($key, $name)->hasClass('ma-flag--glyph')) {
            throw new ExpectationException("The $key icon of '$name' is not a bare glyph", $this->getSession());
        }
    }

    /**
     * The link in an open popover is the next stop for Tab.
     *
     * @Then /^the (?:late policy|guidance) link in the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" can be reached with the tab key$/
     * @param string $key
     * @param string $name
     */
    public function the_popover_link_can_be_reached(string $key, string $name): void {
        $flag = $this->flag($key, $name);
        $id = $flag->getAttribute('aria-controls');
        $this->execute('behat_general::i_press_named_key', ['', 'tab']);
        $js = '!!(document.activeElement && document.activeElement.tagName === "A" && '
            . 'document.activeElement.closest("#' . $id . '"))';
        if (!$this->evaluate_script($js)) {
            throw new ExpectationException("Tab doesn't reach the link in the $key popover of '$name'", $this->getSession());
        }
    }

    /**
     * A grade entered straight into the gradebook.
     *
     * @Given /^the gradebook grade of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is set to "(?P<grade>[\d.]+)"$/
     * @param string $student
     * @param string $name
     * @param string $grade
     */
    public function the_gradebook_grade_is_set(string $student, string $name, string $grade): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $cm = $this->cm($name);
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        if (!$item) {
            throw new coding_exception("'$name' has no grade item");
        }
        $item->update_final_grade(self::userid($student), (float)$grade, 'gradebook', false, FORMAT_MOODLE, get_admin()->id);
        self::queue($cm);
    }

    /**
     * A quiz override that moves one student's close.
     *
     * @Given /^"(?P<user>[^"]*)" has a quiz override on "(?P<name>[^"]*)" closing "(?P<when>[^"]*)"$/
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function has_a_quiz_override(string $username, string $name, string $when): void {
        global $DB;
        $cm = $this->cm($name);
        $DB->insert_record('quiz_overrides', ['quiz' => $cm->instance, 'userid' => self::userid($username),
            'timeclose' => self::time($when)]);
        self::queue($cm);
    }

    /**
     * Turns off marks in every review option of a quiz.
     *
     * @Given /^the review options of "(?P<name>[^"]*)" never show marks$/
     * @param string $name
     */
    public function the_review_options_never_show_marks(string $name): void {
        $cm = $this->cm($name);
        self::set_quiz((int)$cm->instance, ['reviewmarks' => 0]);
        self::queue($cm);
    }

    /**
     * Grades a quiz essay by hand as a marker, so the question engine records who graded it.
     *
     * @Given /^"(?P<marker>[^"]*)" grades the essay of "(?P<student>[^"]*)" in "(?P<name>[^"]*)"$/
     * @param string $marker
     * @param string $student
     * @param string $name
     */
    public function grades_the_essay(string $marker, string $student, string $name): void {
        global $DB, $USER;
        $cm = $this->cm($name);
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $cm->instance, 'userid' => self::userid($student)], 'attempt');
        $attempt = end($attempts);
        $saved = $USER;
        \core\session\manager::set_user(core_user::get_user(self::userid($marker)));
        try {
            $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            $quba->manual_grade(1, 'Good', 1, FORMAT_HTML);
            question_engine::save_questions_usage_by_activity($quba);
            $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $attempt->id]);
            self::regrade_quiz($cm, (int)$attempt->userid);
        } finally {
            \core\session\manager::set_user($saved);
        }
        self::queue($cm);
    }

    /**
     * A backfill that is running: it has started and has read its data.
     *
     * @Given /^a backfill has started$/
     */
    public function a_backfill_has_started(): void {
        global $DB;
        \block_le_dashboard\task\backfill::queue();
        $DB->set_field_select(
            'task_adhoc',
            'timestarted',
            time(),
            'classname = :c AND timestarted IS NULL',
            ['c' => '\\' . \block_le_dashboard\task\backfill::class]
        );
    }

    /**
     * How many backfills are queued and not started.
     *
     * @Then /^"(?P<n>\d+)" backfills? should be waiting to start$/
     * @param int $n
     */
    public function backfills_should_be_waiting(int $n): void {
        global $DB;
        $waiting = $DB->count_records_select(
            'task_adhoc',
            'classname = :c AND timestarted IS NULL',
            ['c' => '\\' . \block_le_dashboard\task\backfill::class]
        );
        if ($waiting !== $n) {
            throw new ExpectationException("$waiting backfills are waiting", $this->getSession());
        }
    }

    /**
     * Assigns a role on a category, as the category's Assign roles page does.
     *
     * @When /^"(?P<user>[^"]*)" is given the "(?P<role>[^"]*)" role on the category "(?P<category>[^"]*)"$/
     * @param string $username
     * @param string $role
     * @param string $category
     */
    public function is_given_the_role_on_the_category(string $username, string $role, string $category): void {
        global $DB;
        $catid = $DB->get_field('course_categories', 'id', ['name' => $category], MUST_EXIST);
        $roleid = $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
        role_assign($roleid, self::userid($username), context_coursecat::instance($catid)->id);
    }

    /**
     * Whether a course re-index is waiting.
     *
     * @Then /^a re-index of the course "(?P<course>[^"]*)" should (?P<not>not )?be queued$/
     * @param string $shortname
     * @param string $not
     */
    public function a_course_reindex_should_be_queued(string $shortname, string $not = ''): void {
        global $DB;
        $courseid = (int)self::course($shortname)->id;
        $queued = false;
        foreach ($DB->get_records('task_adhoc', ['classname' => '\\' . \block_le_dashboard\task\reindex::class]) as $task) {
            $data = json_decode($task->customdata);
            $queued = $queued || (isset($data->courseid) && (int)$data->courseid === $courseid);
        }
        if ($queued === (bool)$not) {
            throw new ExpectationException(
                "A re-index of $shortname is " . ($queued ? '' : 'not ') . 'queued',
                $this->getSession()
            );
        }
    }

    /**
     * A draft: an assignment saved but not submitted under required Submit, or coursework not finalised.
     *
     * @Given /^"(?P<user>[^"]*)" has saved a draft of "(?P<name>[^"]*)"$/
     * @param string $username
     * @param string $name
     */
    public function has_saved_a_draft_of(string $username, string $name): void {
        $cm = $this->cm($name);
        $gen = testing_util::get_data_generator();
        if ($cm->modname === 'assign') {
            // Under required Submit the generator leaves a draft.
            $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => self::userid($username),
                'cmid' => $cm->id, 'onlinetext' => 'Draft']);
        } else {
            $gen->get_plugin_generator('mod_coursework')->create_submission(['courseworkid' => $cm->instance,
                'allocatableid' => self::userid($username), 'allocatabletype' => 'user', 'finalised' => 0,
                'finalisedstatus' => 0]);
        }
        self::queue($cm);
    }

    /**
     * Coursework feedback saved with no grade.
     *
     * @Given /^"(?P<marker>[^"]*)" has saved blank feedback on the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)"$/
     * @param string $marker
     * @param string $student
     * @param string $name
     */
    public function has_saved_blank_feedback(string $marker, string $student, string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $submissionid = $DB->get_field(
            'coursework_submissions',
            'id',
            ['courseworkid' => $cm->instance, 'allocatableid' => self::userid($student)],
            MUST_EXIST
        );
        $gen = testing_util::get_data_generator()->get_plugin_generator('mod_coursework');
        $gen->create_feedback(['submissionid' => $submissionid, 'assessorid' => self::userid($marker),
            'grade' => null, 'feedbackcomment' => '', 'stage_identifier' => 'assessor_1', 'finalised' => 0]);
        self::queue($cm);
    }

    /**
     * A convenor's quiet list: the untagged activities, or the settings to check.
     *
     * @param string $which "untagged activities" or "settings check"
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function untagged_list(string $which = 'untagged activities'): \Behat\Mink\Element\NodeElement {
        $region = $which === 'settings check' ? 'ma-setupchecks' : 'ma-untagged';
        return $this->find('css', self::BLOCK . ' [data-region="' . $region . '"]');
    }

    /**
     * Text in the untagged activities hint.
     *
     * @Then /^I should (?P<not>not )?see "(?P<text>[^"]*)" in the (?P<which>untagged activities|settings check) list$/
     * @param string $not
     * @param string $text
     * @param string $which
     */
    public function i_should_see_in_the_untagged_list(string $not, string $text, string $which): void {
        $node = $this->untagged_list($which);
        $has = str_contains($node->getText(), $text);
        if (!$this->running_javascript()) {
            $has = str_contains(strip_tags($node->getHtml()), $text);
        }
        if ($has === (bool)$not) {
            throw new ExpectationException(
                "The untagged list " . ($has ? 'shows' : 'does not show') . " '$text'",
                $this->getSession()
            );
        }
    }

    /**
     * The hint, or one course in it, is closed.
     *
     * @Then /^the (?:(?P<which>settings check) list|untagged activities (?:list|of "(?P<course>[^"]*)")) should be collapsed$/
     * @param string $which
     * @param string $course
     */
    public function the_untagged_list_should_be_collapsed(string $which = '', string $course = ''): void {
        $node = $this->untagged_list($which ?: 'untagged activities');
        if ($course !== '') {
            $node = $node->find('xpath', ".//details[contains(@class, 'ma-untagged-course')][summary[starts-with(normalize-space(.), " .
                behat_context_helper::escape($course . ' (') . ")]]")
                ?? throw new ExpectationException("No untagged course '$course'", $this->getSession());
        }
        if ($node->hasAttribute('open')) {
            throw new ExpectationException('The untagged activities are open', $this->getSession());
        }
    }

    /**
     * A link to an activity's own page.
     *
     * @Then /^I should see "(?P<text>[^"]*)" linking to the page of "(?P<name>[^"]*)"$/
     * @param string $text
     * @param string $name
     */
    public function i_should_see_linking_to_the_page_of(string $text, string $name): void {
        $this->execute('behat_block_le_dashboard::i_should_see_linking_to', [$text, $this->cm($name)->url->out(false)]);
    }

    /**
     * A hidden activity in the untagged hint is marked as hidden.
     *
     * @Then /^"(?P<name>[^"]*)" in the untagged activities list should be marked "(?P<text>[^"]*)"$/
     * @param string $name
     * @param string $text
     */
    public function in_the_untagged_list_should_be_marked(string $name, string $text): void {
        $li = $this->untagged_list()->find('xpath', ".//li[a[normalize-space(.) = " . behat_context_helper::escape($name) . "]]")
            ?? throw new ExpectationException("'$name' is not in the untagged list", $this->getSession());
        $mark = $li->find('css', '.ma-untagged-hidden');
        if (!$mark || !str_contains(strip_tags($mark->getHtml()), $text)) {
            throw new ExpectationException("'$name' is not marked '$text'", $this->getSession());
        }
    }

    /**
     * Several tagged assessments with the same deadline, named "Assessment 1", "Assessment 2"...
     *
     * @Given /^(?P<n>\d+) (?P<tag>summative|formative) "(?P<mod>\w+)" activities in "(?P<course>[^"]*)" are due "(?P<due>[^"]*)"$/
     * @param int $n
     * @param string $tag
     * @param string $modname
     * @param string $course
     * @param string $due
     */
    public function several_assessments_are_due(int $n, string $tag, string $modname, string $course, string $due): void {
        for ($i = 1; $i <= $n; $i++) {
            $this->create_assessment($tag, $modname, "Assessment $i", $course, '', self::time($due));
        }
    }

    /**
     * Computed styles of elements in the block, keyed as asked.
     *
     * @param array $selectors key => CSS selector inside the block
     * @param string[] $properties
     * @return array key => property => value (null when the element is missing)
     */
    protected function computed(array $selectors, array $properties): array {
        $js = 'return (function() { var block = document.querySelector(' . json_encode(self::BLOCK) . '), out = {};' .
            ' var sel = ' . json_encode($selectors) . ', props = ' . json_encode($properties) . ';' .
            ' Object.keys(sel).forEach(function(k) { var el = block.querySelector(sel[k]);' .
            ' if (!el) { out[k] = null; return; } var cs = getComputedStyle(el), o = {};' .
            ' props.forEach(function(p) { o[p] = p === "height" ? String(el.getBoundingClientRect().height)' .
            ' : cs.getPropertyValue(p); }); out[k] = o; }); return JSON.stringify(out); })();';
        return json_decode($this->evaluate_script($js), true);
    }

    /**
     * Marks one element [data-behat-measure] for computed(), clearing any earlier mark.
     *
     * @param \Behat\Mink\Element\NodeElement $el
     */
    protected function mark_for_measuring(\Behat\Mink\Element\NodeElement $el): void {
        $this->getSession()->executeScript('document.querySelectorAll("[data-behat-measure]").forEach(function(e) {' .
            ' e.removeAttribute("data-behat-measure"); });' .
            ' document.evaluate(' . json_encode($el->getXpath()) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null)' .
            '.singleNodeValue.setAttribute("data-behat-measure", "1");');
    }

    /**
     * The search box's outer corners.
     *
     * @Then /^the search box should have the same radius on all four outer corners$/
     */
    public function the_search_box_outer_corners_match(): void {
        $c = $this->computed(
            ['icon' => '.ma-search .input-group-text', 'input' => '.ma-search .form-control'],
            ['border-top-left-radius', 'border-bottom-left-radius', 'border-top-right-radius', 'border-bottom-right-radius']
        );
        $outer = [$c['icon']['border-top-left-radius'], $c['icon']['border-bottom-left-radius'],
            $c['input']['border-top-right-radius'], $c['input']['border-bottom-right-radius']];
        if (count(array_unique($outer)) !== 1 || (float)$outer[0] <= 0) {
            throw new ExpectationException('The search box outer corners are ' . implode(', ', $outer), $this->getSession());
        }
    }

    /**
     * The join between the search icon and input.
     *
     * @Then /^the search box should have square corners where the icon meets the input$/
     */
    public function the_search_box_inner_corners_are_square(): void {
        $c = $this->computed(
            ['icon' => '.ma-search .input-group-text', 'input' => '.ma-search .form-control'],
            ['border-top-left-radius', 'border-bottom-left-radius', 'border-top-right-radius', 'border-bottom-right-radius']
        );
        $inner = [$c['icon']['border-top-right-radius'], $c['icon']['border-bottom-right-radius'],
            $c['input']['border-top-left-radius'], $c['input']['border-bottom-left-radius']];
        if (array_filter($inner, fn($r) => (float)$r > 0)) {
            throw new ExpectationException('The search box inner corners are ' . implode(', ', $inner), $this->getSession());
        }
    }

    /**
     * The search box's height against a control bar button.
     *
     * @Then /^the search box should be as tall as the "(?P<label>[^"]*)" button$/
     * @param string $label
     */
    public function the_search_box_is_as_tall_as(string $label): void {
        $button = $this->find('xpath', "//*[contains(@class, 'block_le_dashboard')]//button[normalize-space(.) = " .
            behat_context_helper::escape($label) . "]");
        $this->mark_for_measuring($button);
        $c = $this->computed(['search' => '.ma-search .input-group', 'button' => '[data-behat-measure]'], ['height']);
        if (abs((float)$c['search']['height'] - (float)$c['button']['height']) > 1) {
            throw new ExpectationException("The search box is {$c['search']['height']}px tall, the button " .
                "{$c['button']['height']}px", $this->getSession());
        }
    }

    /**
     * Focus is shown around the whole search box.
     *
     * @Then /^the search box should show its focus ring around the icon and the input$/
     */
    public function the_search_box_shows_its_focus_ring(): void {
        $c = $this->computed(['group' => '.ma-search .input-group', 'input' => '.ma-search .form-control'], ['box-shadow']);
        if (in_array($c['group']['box-shadow'], ['', 'none']) || !in_array($c['input']['box-shadow'], ['', 'none'])) {
            throw new ExpectationException("Focus ring: group '{$c['group']['box-shadow']}', input " .
                "'{$c['input']['box-shadow']}'", $this->getSession());
        }
    }

    /**
     * CSS selector of a section by its title.
     *
     * @param string $title
     * @return string
     */
    protected function section_selector(string $title): string {
        $section = $this->find('xpath', "//*[contains(@class, 'block_le_dashboard')]//section[@data-region='ma-section']" .
            "[.//h3[normalize-space(.) = " . behat_context_helper::escape($title) . "]]");
        return '[data-section="' . $section->getAttribute('data-section') . '"]';
    }

    /**
     * Whether a section has its Show more row.
     *
     * @Then /^the "(?P<title>[^"]*)" section should have (?P<which>a|no) Show more row$/
     * @param string $title
     * @param string $which
     */
    public function the_section_has_a_show_more_row(string $title, string $which): void {
        $footer = $this->find('css', self::BLOCK . ' ' . $this->section_selector($title) . ' [data-region="ma-more"]');
        if ($footer->isVisible() !== ($which === 'a')) {
            throw new ExpectationException("The $title section's Show more row is " .
                ($footer->isVisible() ? 'shown' : 'hidden'), $this->getSession());
        }
    }

    /**
     * Rounded bottom corners on a section's last visible row or its Show more row.
     *
     * @Then /^the (?P<which>last row|Show more row) of the "(?P<title>[^"]*)" section should have the card's bottom corners$/
     * @param string $which
     * @param string $title
     */
    public function the_section_bottom_has_corners(string $which, string $title): void {
        $section = $this->section_selector($title);
        if ($which === 'last row') {
            $rows = $this->getSession()->getPage()->findAll('css', self::BLOCK . " $section [data-region='ma-row']");
            $last = null;
            foreach ($rows as $row) {
                if ($row->isVisible()) {
                    $last = $row;
                }
            }
            if (!$last) {
                throw new ExpectationException("The $title section shows no rows", $this->getSession());
            }
            $this->mark_for_measuring($last);
            $selector = '[data-behat-measure]';
        } else {
            $selector = "$section [data-region='ma-more']";
        }
        $c = $this->computed(['el' => $selector], ['border-bottom-left-radius', 'border-bottom-right-radius']);
        if ((float)$c['el']['border-bottom-left-radius'] <= 0 || (float)$c['el']['border-bottom-right-radius'] <= 0) {
            throw new ExpectationException("The $which of the $title section has bottom corners " .
                implode(', ', $c['el']), $this->getSession());
        }
    }

    /**
     * A popover names each of several things.
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should list "(?P<first>[^"]*)" and "(?P<second>[^"]*)"$/
     * @param string $key
     * @param string $name
     * @param string $first
     * @param string $second
     */
    public function the_popover_should_list(string $key, string $name, string $first, string $second): void {
        $text = (string)$this->tip_text($this->flag($key, $name));
        foreach ([$first, $second] as $item) {
            if (!str_contains($text, $item)) {
                throw new ExpectationException("The $key popover of '$name' doesn't list '$item': '$text'", $this->getSession());
            }
        }
    }

    /**
     * A popover links to the activity's settings form.
     *
     * @Then /^the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" should link to the settings of "(?P<activity>[^"]*)"$/
     * @param string $key
     * @param string $name
     * @param string $activity
     */
    public function the_popover_should_link_to_settings(string $key, string $name, string $activity): void {
        $id = $this->flag($key, $name)->getAttribute('aria-controls');
        $url = (new moodle_url('/course/modedit.php', ['update' => $this->cm($activity)->id]))->out(false);
        if (!$this->find('css', '#' . $id)->find('xpath', ".//a[@href = " . behat_context_helper::escape($url) . "]")) {
            throw new ExpectationException("The $key popover of '$name' doesn't link to $url", $this->getSession());
        }
    }

    /**
     * Tags every activity with a grade item in some courses.
     *
     * @Given /^every compatible activity in (?P<courses>"[^"]*"(?:(?:, | and )"[^"]*")*) is tagged "(?P<tag>[^"]*)"$/
     * @param string $courses
     * @param string $tag
     */
    public function every_compatible_activity_is_tagged(string $courses, string $tag): void {
        global $DB;
        preg_match_all('/"([^"]*)"/', $courses, $m);
        foreach ($m[1] as $shortname) {
            $course = self::course($shortname);
            foreach (get_fast_modinfo($course->id)->get_cms() as $cm) {
                if (
                    $DB->record_exists('grade_items', ['courseid' => $course->id, 'itemtype' => 'mod',
                        'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance])
                ) {
                    core_tag_tag::add_item_tag('core', 'course_modules', $cm->id, $cm->context, $tag);
                }
            }
        }
        \cache::make('block_le_dashboard', 'untagged')->purge();
    }

    /**
     * Sets the tagging guidance link.
     *
     * @Given /^the My assessments tagging guidance link is "(?P<url>[^"]*)"$/
     * @param string $url
     */
    public function the_tagging_guidance_link_is(string $url): void {
        set_config('untaggedguidanceurl', $url, 'block_le_dashboard');
        // The old setting, carried into its guidance box as the upgrade does.
        \block_le_dashboard\local\guidance::migrate();
    }

    /**
     * A visible link to the site's front page.
     *
     * @Then /^I should see "(?P<text>[^"]*)" linking to the site home$/
     * @param string $text
     */
    public function i_should_see_linking_to_site_home(string $text): void {
        global $CFG;
        $this->i_should_see_linking_to($text, $CFG->wwwroot . '/');
    }

    /**
     * Creates and tags an assessment due at a clock time in a time zone.
     *
     * @Given /^an? (?P<tag>summative|formative) "(?P<mod>\w+)" "(?P<name>[^"]*)" in "(?P<course>[^"]*)" is due "(?P<due>[^"]*)" in "(?P<zone>[^"]*)"$/
     * @param string $tag
     * @param string $modname
     * @param string $name
     * @param string $course
     * @param string $due A date and time, such as "14 October 2026 13:30".
     * @param string $zone
     */
    public function an_assessment_is_due_in_zone(
        string $tag,
        string $modname,
        string $name,
        string $course,
        string $due,
        string $zone
    ): void {
        $time = (new \DateTime($due, new \DateTimeZone($zone)))->getTimestamp();
        $this->create_assessment($tag, $modname, $name, $course, '', $time);
    }

    /**
     * Sets the time the dashboard is drawn at (config::now()), a date and time in a time zone.
     *
     * @Given /^it is now "(?P<when>[^"]*)" in "(?P<zone>[^"]*)"$/
     * @param string $when Such as "Mon 12 October 2026 20:00".
     * @param string $zone
     */
    public function it_is_now(string $when, string $zone): void {
        set_config('behatnow', (new \DateTime($when, new \DateTimeZone($zone)))->getTimestamp(), 'block_le_dashboard');
    }

    /**
     * Sets a user's Moodle time zone.
     *
     * @Given /^the user "(?P<user>[^"]*)" has the time zone "(?P<zone>[^"]*)"$/
     * @param string $username
     * @param string $zone
     */
    public function the_user_has_the_time_zone(string $username, string $zone): void {
        global $DB;
        $DB->set_field('user', 'timezone', $zone, ['id' => self::userid($username)]);
    }

    /**
     * Makes the browser's time zone another, for the rest of the scenario (Chrome DevTools).
     *
     * @Given /^my browser's time zone is "(?P<zone>[^"]*)"$/
     * @param string $zone
     */
    public function my_browsers_time_zone_is(string $zone): void {
        $session = $this->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $devtools = new \Facebook\WebDriver\Chrome\ChromeDevToolsDriver($session->getDriver()->getWebDriver());
        $devtools->execute('Emulation.setTimezoneOverride', ['timezoneId' => $zone]);
    }

    /**
     * The time a row's labelled date shows.
     *
     * @Then /^the "(?P<label>[^"]*)" date of "(?P<name>[^"]*)" should read "(?P<text>[^"]*)"$/
     * @param string $label
     * @param string $name
     * @param string $text
     */
    public function the_date_should_read(string $label, string $name, string $text): void {
        $time = $this->dated($label, $name)->find('css', 'time');
        if (!self::within(fn() => trim($time->getText()) === $text)) {
            throw new ExpectationException("'$name' $label reads '{$time->getText()}'", $this->getSession());
        }
    }

    /**
     * A row with no time zone globe.
     *
     * @Then /^"(?P<name>[^"]*)" should not have a time zone icon$/
     * @param string $name
     */
    public function should_not_have_a_time_zone_icon(string $name): void {
        if ($this->row($name)->find('css', '[data-hint="timezone"]')) {
            throw new ExpectationException("'$name' has a time zone icon", $this->getSession());
        }
    }

    /**
     * Which globe a row's first time zone hint shows.
     *
     * @Then /^the time zone icon of "(?P<name>[^"]*)" should be "(?P<icon>[a-z-]+)"$/
     * @param string $name
     * @param string $icon Font Awesome name without "fa-".
     */
    public function the_time_zone_icon_should_be(string $name, string $icon): void {
        $glyph = $this->time_zone_icon($name)->find('css', 'i');
        if (!$glyph || !$glyph->hasClass('fa-' . $icon)) {
            throw new ExpectationException(
                "'$name' shows " . ($glyph ? $glyph->getAttribute('class') : 'no glyph'),
                $this->getSession()
            );
        }
    }

    /**
     * Opens a row's time zone hint.
     *
     * @When /^I click on the time zone icon of (?:the "(?P<label>[^"]*)" date of )?"(?P<name>[^"]*)"$/
     * @param string $label Optional: the date the hint sits beside.
     * @param string $name
     */
    public function i_click_on_the_time_zone_icon(string $label, string $name): void {
        $this->time_zone_icon($name, $label)->click();
    }

    /**
     * What a row's open time zone hint says.
     *
     * @Then /^the time zone note of "(?P<name>[^"]*)" should say "(?P<text>[^"]*)"$/
     * @param string $name
     * @param string $text
     */
    public function the_time_zone_note_should_say(string $name, string $text): void {
        foreach ($this->row($name)->findAll('css', '[data-hint="timezone"]') as $icon) {
            $shown = $this->tip_text($icon);
            if ($shown !== null) {
                if (!str_contains($shown, $text)) {
                    throw new ExpectationException("The time zone note of '$name' says '$shown'", $this->getSession());
                }
                return;
            }
        }
        throw new ExpectationException("No time zone note of '$name' is open", $this->getSession());
    }

    /**
     * A row's labelled date line.
     *
     * @param string $label
     * @param string $name
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function dated(string $label, string $name): \Behat\Mink\Element\NodeElement {
        $xpath = ".//div[@data-date][span[contains(@class, 'ma-row-date-label')][normalize-space(.) = " .
            behat_context_helper::escape($label) . "]]";
        return $this->row($name)->find('xpath', $xpath)
            ?? throw new ExpectationException("'$name' has no '$label' date", $this->getSession());
    }

    /**
     * A row's time zone globe: beside a labelled date, or the first.
     *
     * @param string $name
     * @param string $label
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function time_zone_icon(string $name, string $label = ''): \Behat\Mink\Element\NodeElement {
        $where = $label !== '' ? $this->dated($label, $name) : $this->row($name);
        $icon = null;
        self::within(function () use ($where, &$icon) {
            $icon = $where->find('css', '[data-hint="timezone"]');
            return $icon !== null;
        });
        return $icon ?? throw new ExpectationException("'$name' has no time zone icon", $this->getSession());
    }

    /**
     * The self-certification limit block_rhb sets.
     *
     * @Given /^block_rhb allows (?P<n>\d+) self-certified extensions$/
     * @param int $n
     */
    public function block_rhb_allows(int $n): void {
        set_config('selfcertmax', $n, 'block_rhb');
    }

    /**
     * Self-certified extensions a student has used, on other courseworks (mod_coursework's table).
     *
     * @Given /^"(?P<user>[^"]*)" has used (?P<n>\d+) self-certified extensions?$/
     * @param string $username
     * @param int $n
     */
    public function has_used_self_certified_extensions(string $username, int $n): void {
        global $DB;
        $columns = $DB->get_columns('coursework_mitigations');
        for ($i = 0; $i < $n; $i++) {
            $record = ['courseworkid' => 0, 'allocatableid' => self::userid($username), 'allocatabletype' => 'user',
                'extended_deadline' => time(), 'type' => 'extension', 'selfcert' => 1,
                'timecreated' => time(), 'timemodified' => time()];
            $DB->insert_record('coursework_mitigations', array_intersect_key($record, $columns));
        }
    }

    /**
     * The items of a row's Mitigate menu, in order: live (a link), greyed (with its reason starting with the
     * note) or html (block_rhb's own), with a live item's count as its note.
     *
     * @Then /^the Mitigate menu of "(?P<name>[^"]*)" should list:$/
     * @param string $name
     * @param TableNode $table item, state, note
     */
    public function the_mitigate_menu_should_list(string $name, TableNode $table): void {
        $menu = $this->mitigate_menu($name);
        $items = $menu->findAll('css', '.ma-mitigate-item');
        $rows = $table->getHash();
        if (count($items) !== count($rows)) {
            throw new ExpectationException("The Mitigate menu of '$name' has " . count($items) . ' items: ' .
                $menu->getText(), $this->getSession());
        }
        foreach ($rows as $i => $row) {
            $item = $items[$i];
            $note = trim($row['note']);
            $left = $item->find('css', '.ma-mitigate-left');
            $label = trim(preg_replace('/\s+/', ' ', $item->getText()));
            if ($left) {
                $label = trim(substr($label, 0, -strlen(trim($left->getText()))));
            }
            if ($row['state'] === 'greyed') {
                $reason = $menu->find('css', '#' . $item->getAttribute('aria-describedby'));
                $ok = $item->getAttribute('aria-disabled') === 'true' && $label === $row['item']
                    && $reason && str_starts_with(trim($reason->getText()), $note);
            } else {
                $ok = $item->getAttribute('aria-disabled') !== 'true' && $label === $row['item']
                    && ($note === '' ? !$left : $left && trim($left->getText()) === $note);
            }
            if (!$ok) {
                throw new ExpectationException("Item " . ($i + 1) . " of the Mitigate menu of '$name' is '" .
                    $item->getText() . "': " . $menu->getText(), $this->getSession());
            }
        }
    }

    /**
     * Where the live self-certification item goes: block_rhb's address (the stand-in's has selfcert=1).
     *
     * @Then /^"Self-certify an extension" in the Mitigate menu of "(?P<name>[^"]*)" should go to block_rhb's self-certification address$/
     * @param string $name
     */
    public function self_certify_should_go_to_rhb(string $name): void {
        $link = $this->mitigate_menu($name)->find('css', 'a.ma-mitigate-item');
        if (!$link || !str_contains((string)$link->getAttribute('href'), 'selfcert=1')) {
            throw new ExpectationException(
                "Self-certify in '$name' goes to " . ($link ? $link->getAttribute('href') : 'nothing'),
                $this->getSession()
            );
        }
    }

    /**
     * block_rhb's own HTML in the menu, for an address it can't share.
     *
     * @Then /^the Mitigate menu of "(?P<name>[^"]*)" should show block_rhb's self-certification HTML$/
     * @param string $name
     */
    public function the_mitigate_menu_should_show_rhb_html(string $name): void {
        $item = $this->mitigate_menu($name)->find('css', 'div.ma-mitigate-item');
        if (!$item || trim($item->getText()) !== 'Self-certify') {
            throw new ExpectationException("The Mitigate menu of '$name' doesn't show block_rhb's HTML", $this->getSession());
        }
    }

    /**
     * A row's Mitigate menu.
     *
     * @param string $name
     * @param bool $open Whether it must be open.
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function mitigate_menu(string $name, bool $open = true): \Behat\Mink\Element\NodeElement {
        $menu = $this->row($name)->find('css', '[data-region="ma-mitigate"] .dropdown-menu')
            ?? throw new ExpectationException("'$name' has no Mitigate menu", $this->getSession());
        if ($open && !self::within(fn() => $menu->isVisible())) {
            throw new ExpectationException("The Mitigate menu of '$name' is not open", $this->getSession());
        }
        return $menu;
    }

    /**
     * Delete mitigation's look: its own item, the destructive colour and a bin.
     *
     * @Then /^"Delete mitigation" in the Mitigate menu of "(?P<name>[^"]*)" should be a destructive item with a bin icon$/
     * @param string $name
     */
    public function delete_mitigation_should_be_destructive(string $name): void {
        $item = $this->mitigate_menu($name)->find('css', '[data-action="mitigate-delete"]');
        $red = $item ? $this->evaluate_script('return getComputedStyle(document.evaluate(' . json_encode($item->getXpath()) .
            ', document, null, 9, null).singleNodeValue).color') : '';
        if (
            !$item || trim($item->getText()) !== 'Delete mitigation' || !$item->find('css', 'i.fa-trash-can')
                || !preg_match('/^rgba?\((\d+), (\d+), (\d+)/', (string)$red, $m) || !($m[1] > 150 && $m[2] < 100 && $m[3] < 100)
        ) {
            throw new ExpectationException("Delete mitigation in '$name' is not a red item with a bin ($red)", $this->getSession());
        }
    }

    /**
     * Clicks an item of a row's Mitigate menu.
     *
     * @When /^I click on "(?P<text>[^"]*)" in the Mitigate menu of "(?P<name>[^"]*)"$/
     * @param string $text
     * @param string $name
     */
    public function i_click_in_the_mitigate_menu(string $text, string $name): void {
        $this->mitigate_item($name, $text)->click();
    }

    /**
     * An item of a row's open Mitigate menu.
     *
     * @Then /^"(?P<text>[^"]*)" should be in the Mitigate menu of "(?P<name>[^"]*)"$/
     * @param string $text
     * @param string $name
     */
    public function should_be_in_the_mitigate_menu(string $text, string $name): void {
        $this->mitigate_item($name, $text);
    }

    /**
     * The menu stays open.
     *
     * @Then /^the Mitigate menu of "(?P<name>[^"]*)" should still be open$/
     * @param string $name
     */
    public function the_mitigate_menu_should_still_be_open(string $name): void {
        usleep(300000);
        $this->mitigate_menu($name);
    }

    /**
     * Whether the block_rhb stand-in's delete link was followed (tests/fixtures/rhb_delete.php).
     *
     * @Then /^block_rhb's delete link should (?P<not>not )?have been followed$/
     * @param string $not
     */
    public function rhb_delete_followed(string $not = ''): void {
        global $DB;
        $followed = fn() => (bool)$DB->get_field(
            'config_plugins',
            'value',
            ['plugin' => 'block_le_dashboard', 'name' => 'behatrhbdeleted']
        );
        if ($not) {
            usleep(500000);
            $ok = !$followed();
        } else {
            $ok = self::within($followed);
        }
        if (!$ok) {
            throw new ExpectationException("block_rhb's delete link was " . ($not ? '' : 'not ') . 'followed', $this->getSession());
        }
    }

    /**
     * An item of a row's open Mitigate menu, by its text.
     *
     * @param string $name
     * @param string $text
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function mitigate_item(string $name, string $text): \Behat\Mink\Element\NodeElement {
        $menu = $this->mitigate_menu($name);
        $found = null;
        self::within(function () use ($menu, $text, &$found) {
            foreach ($menu->findAll('css', '.ma-mitigate-item') as $item) {
                if (trim(preg_replace('/\s+/', ' ', $item->getText())) === $text) {
                    $found = $item;
                    return true;
                }
            }
            return false;
        });
        return $found ?? throw new ExpectationException(
            "No '$text' in the Mitigate menu of '$name': " . $menu->getText(),
            $this->getSession()
        );
    }

    /**
     * Has the block_rhb stand-in treat an activity as a coursework, so self-certification applies to it on sites
     * without mod_coursework (the row stays what it is otherwise).
     *
     * @Given /^the block_rhb stand-in treats "(?P<name>[^"]*)" as a coursework$/
     * @param string $name
     */
    public function the_rhb_stand_in_treats_as_coursework(string $name): void {
        $cm = $this->cm($name);
        $list = array_filter(explode(',', (string)get_config('block_le_dashboard', 'behatcoursework')));
        $list[] = $cm->modname . ':' . $cm->instance;
        set_config('behatcoursework', implode(',', $list), 'block_le_dashboard');
    }

    /**
     * No admin notice above the tabs.
     *
     * @Then /^the My assessments block should show no notice$/
     */
    public function the_block_should_show_no_notice(): void {
        if ($this->getSession()->getPage()->findAll('css', self::BLOCK . ' [data-region="ma-notice"]')) {
            throw new ExpectationException('The block shows a notice', $this->getSession());
        }
    }

    /**
     * The admin notice, above the tabs, holds the text.
     *
     * @Then /^the My assessments block should show the notice "(?P<text>[^"]*)"$/
     * @param string $text
     */
    public function the_block_should_show_the_notice(string $text): void {
        $notice = $this->notice();
        if (!str_contains($notice->getText(), $text)) {
            throw new ExpectationException("The notice says '{$notice->getText()}', not '$text'", $this->getSession());
        }
        // Above the tabs and the view, whichever the viewer has.
        $after = $this->getSession()->getPage()->findAll('css', self::BLOCK . ' [data-region="ma-notice"] ~ .ma-tabs, '
            . self::BLOCK . ' [data-region="ma-notice"] ~ [data-region="ma-view"]');
        if (!$after) {
            throw new ExpectationException('The notice is not above the dashboard', $this->getSession());
        }
    }

    /**
     * A link in the admin notice.
     *
     * @Then /^"(?P<text>[^"]*)" in the notice should link to "(?P<url>[^"]*)"$/
     * @param string $text
     * @param string $url
     */
    public function in_the_notice_should_link_to(string $text, string $url): void {
        foreach ($this->notice()->findAll('css', 'a') as $link) {
            if (trim($link->getText()) === $text && $link->getAttribute('href') === $url) {
                return;
            }
        }
        throw new ExpectationException("No '$text' link to '$url' in the notice", $this->getSession());
    }

    /**
     * Moodle's cleaning took any script out of the admin notice.
     *
     * @Then /^the notice should hold no script$/
     */
    public function the_notice_should_hold_no_script(): void {
        if ($this->notice()->findAll('css', 'script')) {
            throw new ExpectationException('The notice holds a script', $this->getSession());
        }
    }

    /**
     * The admin notice above the tabs.
     *
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function notice(): \Behat\Mink\Element\NodeElement {
        return $this->find('css', self::BLOCK . ' [data-region="ma-notice"]');
    }

    // Beta27: index passes with a time limit (index-passes.feature).

    /**
     * Each index run stops as at its time limit after this many courses.
     *
     * @Given /^every My assessments index run has a time limit of (?P<n>\d+) courses?$/
     * @param int $n
     */
    public function every_index_run_has_a_limit(int $n): void {
        set_config('behatindexcourses', $n, 'block_le_dashboard');
    }

    /**
     * Ad hoc tasks never run: the steps that catch the index up leave them queued.
     *
     * @Given /^ad hoc tasks don't run$/
     */
    public function ad_hoc_tasks_dont_run(): void {
        $this->noadhoc = true;
    }

    /**
     * Rebuilds the index as the Index page's button does.
     *
     * @Given /^the My assessments index is rebuilt$/
     */
    public function the_index_is_rebuilt(): void {
        \block_le_dashboard\task\backfill::rebuild();
    }

    /**
     * Collects the courses a run's progress lines name.
     *
     * @param callable $run Receives the trace.
     * @return mixed What the run returned.
     */
    protected function traced(callable $run) {
        $this->lastrun = [];
        $trace = function (string $line) {
            if (preg_match('/^Indexing course (\d+)$/', $line, $m)) {
                $this->lastrun[] = (int)$m[1];
            }
        };
        ob_start();
        try {
            return $run($trace);
        } finally {
            foreach (explode("\n", (string)ob_get_clean()) as $line) {
                $trace(trim($line));
            }
        }
    }

    /**
     * One nightly check run, whatever the hour.
     *
     * @When /^the nightly check runs$/
     */
    public function the_nightly_check_runs(): void {
        $this->traced(fn($trace) => \block_le_dashboard\local\index\pass::run(
            \block_le_dashboard\local\index\pass::NIGHTLY,
            $trace
        ));
        \block_le_dashboard\local\adapter\registry::flush_errors();
    }

    /**
     * The hourly task runs at a time of day, server time, on the same day as its last run in this scenario
     * when that is later, or else the next day.
     *
     * @When /^the hourly index task runs at "(?P<time>\d\d:\d\d)"$/
     * @param string $time
     */
    public function the_hourly_task_runs_at(string $time): void {
        $zone = \core_date::get_server_timezone_object();
        $at = new \DateTime('@' . ($this->lasthourly ?: time()));
        $at->setTimezone($zone);
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $at->setTime($hour, $minute);
        if ($this->lasthourly && $at->getTimestamp() <= $this->lasthourly) {
            $at->modify('+1 day');
        }
        $this->lasthourly = $at->getTimestamp();
        $filling = (bool)get_config('block_le_dashboard', 'backfillpending');
        $this->traced(fn($trace) => (new \block_le_dashboard\task\reconcile())->run($this->lasthourly));
        if (!$filling) {
            $this->nightlydone = array_merge($this->nightlydone, $this->lastrun);
        }
    }

    /**
     * The nightly check died part-way: it saved its progress up to a course, then stopped without ending.
     *
     * @Given /^the nightly check died after "(?P<course>[^"]*)"$/
     * @param string $shortname
     */
    public function the_nightly_check_died_after(string $shortname): void {
        $this->traced(fn($trace) => \block_le_dashboard\local\index\pass::run(
            \block_le_dashboard\local\index\pass::NIGHTLY,
            $trace,
            0,
            0,
            0,
            1
        ));
        $state = \block_le_dashboard\local\index\pass::state(\block_le_dashboard\local\index\pass::NIGHTLY);
        if ($state['after'] !== $this->course_id($shortname)) {
            throw new ExpectationException("The nightly check didn't start with '$shortname'", $this->getSession());
        }
        // Not stopped at the limit: it died, so how the run ended was never saved.
        $state['reason'] = '';
        set_config('pass_nightly', json_encode($state), 'block_le_dashboard');
    }

    /**
     * The courses the last index run did.
     *
     * @Then /^the (?:last index run|first fill) should have done (?P<courses>"[^"]*"(?:(?:, | and )"[^"]*")*) only$/
     * @param string $courses
     */
    public function the_last_run_should_have_done(string $courses): void {
        $this->assert_courses($this->lastrun, $courses, 'The last index run');
    }

    /**
     * The courses the nightly check has done in this scenario so far.
     *
     * @Then /^the nightly check should have done (?P<courses>no courses|"[^"]*"(?:(?:, | and )"[^"]*")*) so far$/
     * @param string $courses
     */
    public function the_nightly_check_should_have_done(string $courses): void {
        $this->assert_courses($this->nightlydone, $courses === 'no courses' ? '' : $courses, 'The nightly check');
    }

    /**
     * Compares course ids with a list of quoted short names.
     *
     * @param int[] $ids
     * @param string $courses Such as '"M1", "M2" and "M3"', or empty for none.
     * @param string $what
     */
    protected function assert_courses(array $ids, string $courses, string $what): void {
        preg_match_all('/"([^"]*)"/', $courses, $m);
        $expected = array_map(fn($name) => $this->course_id($name), $m[1]);
        if ($ids !== $expected) {
            global $DB;
            $names = array_map(fn($id) => $DB->get_field('course', 'shortname', ['id' => $id]), $ids);
            throw new ExpectationException("$what did " . (implode(', ', $names) ?: 'nothing'), $this->getSession());
        }
    }

    /**
     * A course's id by its short name.
     *
     * @param string $shortname
     * @return int
     */
    protected function course_id(string $shortname): int {
        global $DB;
        return (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * The Index admin page's text, as an admin sees it.
     *
     * @Then /^the Index page should say "(?P<text>[^"]*)"$/
     * @param string $text
     */
    public function the_index_page_should_say(string $text): void {
        $this->execute('behat_auth::i_log_in_as', ['admin']);
        $this->getSession()->visit($this->locate_path('/blocks/le_dashboard/admin/index.php'));
        $this->execute('behat_general::assert_page_contains_text', [$text]);
    }

    /**
     * Runs the command-line rebuild's work, as cli/rebuild.php does.
     *
     * @When /^I run the command-line rebuild$/
     */
    public function i_run_the_command_line_rebuild(): void {
        $done = $this->traced(fn($trace) => \block_le_dashboard\task\backfill::rebuild_now($trace));
        if (!$done) {
            throw new ExpectationException('The command-line rebuild did not finish', $this->getSession());
        }
    }

    /**
     * Every course with a tagged assessment is in the index.
     *
     * @Then /^it should finish with every course done$/
     */
    public function it_should_finish_with_every_course_done(): void {
        global $DB;
        $tagged = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT cm.course)
                                                 FROM {tag_instance} ti
                                                 JOIN {course_modules} cm ON cm.id = ti.itemid
                                                WHERE ti.component = 'core' AND ti.itemtype = 'course_modules'");
        $indexed = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT courseid) FROM {block_le_dashboard_cm}");
        if ($indexed !== $tagged || !\block_le_dashboard\local\index\pass::complete(\block_le_dashboard\local\index\pass::FILL)) {
            throw new ExpectationException("$indexed of $tagged courses are indexed", $this->getSession());
        }
    }

    /**
     * Whether a first fill (backfill ad hoc task) is queued, and "Preparing" pending.
     *
     * @Then /^no first fill should be queued$/
     */
    public function no_first_fill_should_be_queued(): void {
        global $DB;
        if (
            $DB->record_exists('task_adhoc', ['classname' => '\\' . \block_le_dashboard\task\backfill::class])
                || get_config('block_le_dashboard', 'backfillpending')
        ) {
            throw new ExpectationException('A first fill is still queued or pending', $this->getSession());
        }
    }

    // Beta27: a greyed Self-certify never looks available (Pierre's UAT, 2026-10-19).

    /**
     * Keeps the pointer over a Mitigate menu item for a while.
     *
     * @When /^I pass the pointer over "(?P<text>[^"]*)" in the Mitigate menu of "(?P<name>[^"]*)" for "(?P<ms>\d+)" milliseconds$/
     * @param string $text
     * @param string $name
     * @param int $ms
     */
    public function i_pass_over_the_mitigate_item(string $text, string $name, int $ms): void {
        $this->mitigate_item($name, $text)->mouseOver();
        usleep($ms * 1000);
    }

    /**
     * A greyed Mitigate item looks unavailable: muted text on no fill, whatever the pointer or focus.
     *
     * @Then /^"(?P<text>[^"]*)" in the Mitigate menu of "(?P<name>[^"]*)" should look greyed$/
     * @param string $text
     * @param string $name
     */
    public function the_mitigate_item_should_look_greyed(string $text, string $name): void {
        $xpath = json_encode($this->mitigate_item($name, $text)->getXpath());
        $result = $this->evaluate_script("return (function() {
            const item = document.evaluate($xpath, document, null, 9, null).singleNodeValue;
            const probe = document.createElement('span');
            probe.style.color = 'var(--ma-text-muted)';
            item.parentNode.appendChild(probe);
            const muted = getComputedStyle(probe).color;
            probe.remove();
            const style = getComputedStyle(item);
            const clear = style.backgroundColor === 'transparent' || /, 0\)$/.test(style.backgroundColor);
            return style.color === muted && clear ? '' : style.color + ' on ' + style.backgroundColor + ', muted is ' + muted;
        })();");
        if ($result !== '') {
            throw new ExpectationException(
                "'$text' in the Mitigate menu of '$name' doesn't look greyed: $result",
                $this->getSession()
            );
        }
    }

    /**
     * A Mitigate menu item has the keyboard focus and shows it with an outline.
     *
     * @Then /^"(?P<text>[^"]*)" in the Mitigate menu of "(?P<name>[^"]*)" should have a visible focus outline$/
     * @param string $text
     * @param string $name
     */
    public function the_mitigate_item_should_have_focus_outline(string $text, string $name): void {
        $xpath = json_encode($this->mitigate_item($name, $text)->getXpath());
        $result = $this->evaluate_script("return (function() {
            const item = document.evaluate($xpath, document, null, 9, null).singleNodeValue;
            if (document.activeElement !== item) {
                return 'it is not focused';
            }
            const style = getComputedStyle(item);
            const outline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0;
            return outline || style.boxShadow !== 'none' ? '' : 'no outline';
        })();");
        if ($result !== '') {
            throw new ExpectationException(
                "'$text' in the Mitigate menu of '$name' has no visible focus: $result",
                $this->getSession()
            );
        }
    }
}
