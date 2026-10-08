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
        $text = trim(strtolower($text));
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
        $time = strtotime($text);
        if ($time === false) {
            throw new coding_exception("Unknown time '$text'");
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
     * Runs pending index tasks.
     */
    protected function catch_up(): void {
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
     * (assign: students must click Submit, which makes the submission final).
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
     * Submits a peerwork for a group.
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
        (new \block_le_dashboard\task\reconcile())->execute();
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
     * Which Font Awesome icon a row's activity icon is.
     *
     * @Then /^the activity icon of "(?P<name>[^"]*)" should be the "(?P<icon>[^"]*)" icon$/
     * @param string $name
     * @param string $icon
     */
    public function the_activity_icon_should_be(string $name, string $icon): void {
        if (!$this->activity_icon($name)->find('css', '.ma-row-faicon.' . $icon)) {
            throw new ExpectationException("The icon of '$name' is not '$icon'", $this->getSession());
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
     * @Given /^block_rhb offers "(?P<what>a link|a delete link|a message only|nothing|no link)" for (?P<which>self-certification|the request form)$/
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
        'load error' => 'loaderror'];

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
            $has = str_contains($this->row_text($row), $text);
            if ($has === (bool)$not) {
                throw new ExpectationException("'$name' " . ($not ? 'shows' : 'does not show') . " '$text'", $this->getSession());
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
     * The freshness line.
     *
     * @Then /^I should see "Updated" with the time (?:the index was last updated|"now")$/
     */
    public function i_should_see_updated(): void {
        $node = $this->find('css', self::BLOCK . ' .ma-freshness-text');
        if (!preg_match('/^Updated (just now|\d+ min ago)$/', trim($node->getText()))) {
            throw new ExpectationException("Freshness says '{$node->getText()}'", $this->getSession());
        }
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
            $where = $row->find('xpath', ".//span[contains(@class, 'ma-status-wrap')][span[contains(@class, 'ma-pill')]" .
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
     * @Then /^the late policy link in the "(?P<key>[a-z]+)" popover of "(?P<name>[^"]*)" can be reached with the tab key$/
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
}
