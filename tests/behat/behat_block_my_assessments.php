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

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use block_my_assessments\local\index\indexer;
use block_my_assessments\local\years;

/**
 * Steps for the My assessments scope (logic-overhaul/scope/*.feature).
 *
 * Test data is created through each plugin's own API where it has one. Where a step sets data
 * directly, it queues the re-index the plugin's own event would have queued, so "view my
 * dashboard" (which runs pending index tasks first) sees it.
 *
 * @package    block_my_assessments
 * @category   test
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_my_assessments extends behat_base {
    /** Block selector. */
    const BLOCK = '.block_my_assessments';

    /** Module tables searched when looking up an activity by name. */
    const MODULES = ['assign', 'quiz', 'peerwork', 'coursework', 'workshop', 'forum', 'page', 'lti'];

    /**
     * The module convenor role is needed before Background enrolments refer to it.
     *
     * @BeforeScenario @block_my_assessments
     */
    public function create_convenor_role(): void {
        global $DB;
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
            set_config('behatrhbstub', 1, 'block_my_assessments');
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
        $xpath = "//*[contains(@class, 'block_my_assessments')]" .
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
        $xpath = "./div[contains(@class, 'ma-row-status')]/div[@data-bar][span[contains(@class, 'ma-bar-label')]" .
            "[normalize-space(.) = " . behat_context_helper::escape($label) . "]]";
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
        testing_util::get_data_generator()->get_plugin_generator('block_my_assessments')->configure($types);
        set_config('certaintags', $summative, 'block_my_assessments');
        set_config('formativetags', $formative, 'block_my_assessments');
        unset_config('backfillpending', 'block_my_assessments');
        $page = my_get_page(null, MY_PAGE_PRIVATE);
        if (!$DB->record_exists('block_instances', ['blockname' => 'my_assessments', 'subpagepattern' => $page->id])) {
            $DB->insert_record('block_instances', ['blockname' => 'my_assessments',
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
        $start = strtotime($date . ' 00:00');
        $DB->delete_records(years::TABLE, ['label' => $label]);
        $id = $DB->insert_record(years::TABLE, ['label' => $label, 'timestart' => $start,
            'timeend' => strtotime('+1 year', $start), 'timecreated' => time(), 'timemodified' => time()]);
        set_config('defaultacademicyear', $id, 'block_my_assessments');
    }

    /**
     * Creates and tags an assessment.
     *
     * Options after "with": marker allocation, a marking deadline "...", an essay question,
     * grading method "First attempt|Last attempt|Highest grade", 2 markers, moderation,
     * marking workflow, reopened attempts, a cut-off "...", manual agreement.
     *
     * @Given /^an? (?P<tag>summative|formative) "(?P<mod>\w+)" "(?P<name>[^"]*)" in "(?P<course>[^"]*)"(?P<opts>.*?) (?:is|was) due "(?P<due>[^"]*)"$/
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
        $this->create_assessment($tag, $modname, $name, $course, $opts, self::time($due));
    }

    /**
     * Creates and tags an assessment with no due date.
     *
     * @Given /^an? (?P<tag>summative|formative) "(?P<mod>\w+)" "(?P<name>[^"]*)" in "(?P<course>[^"]*)" with no due date exists$/
     * @param string $tag
     * @param string $modname
     * @param string $name
     * @param string $course
     */
    public function an_assessment_with_no_due_date(string $tag, string $modname, string $name, string $course): void {
        $this->create_assessment($tag, $modname, $name, $course, '', null);
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
                $record += ['duedate' => (int)$due, 'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1,
                    'gradingduedate' => $markingdue,
                    'markingworkflow' => $allocation || str_contains($opts, 'marking workflow') ? 1 : 0,
                    'markingallocation' => $allocation ? 1 : 0];
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
                break;
            case 'peerwork':
                $record += ['duedate' => (int)$due];
                break;
            case 'coursework':
                $markers = preg_match('/(\d+) markers/', $opts, $m) ? (int)$m[1] : 1;
                $record += ['deadline' => (int)$due, 'numberofmarkers' => $markers, 'allocationenabled' => $allocation ? 1 : 0,
                    'moderationenabled' => str_contains($opts, 'moderation') ? 1 : 0,
                    'markingdeadlineenabled' => $markingdue ? 1 : 0, 'initialmarkingdeadline' => $markingdue];
                break;
            case 'workshop':
                $record += ['submissionend' => (int)$due];
                break;
            default:
                $record += ['duedate' => (int)$due];
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
        }
        core_tag_tag::set_item_tags('core', 'course_modules', $instance->cmid, context_module::instance($instance->cmid), [$tag]);
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
        \block_my_assessments\local\adapter\registry::set_enabled($modname, $not === '');
    }

    /**
     * Makes indexing of a type fail.
     *
     * @Given /^indexing of "(?P<mod>\w+)" activities fails with an error$/
     * @param string $modname
     */
    public function indexing_fails(string $modname): void {
        set_config('testfailtypes', $modname, 'block_my_assessments');
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
        $current = array_filter(explode(',', (string)get_config('block_my_assessments', $setting)));
        set_config($setting, implode(',', array_unique(array_merge($current, [$shortname]))), 'block_my_assessments');
    }

    /**
     * Saves the view a user last opened.
     *
     * @Given /^"(?P<user>[^"]*)" last opened the "(?P<view>[^"]*)"$/
     * @param string $username
     * @param string $view
     */
    public function last_opened(string $username, string $view): void {
        set_user_preference('block_my_assessments_view', self::view_key($view), self::userid($username));
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
        $strings = get_string_manager()->load_component_strings('block_my_assessments', 'en');
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
        file_put_contents($dir . '/block_my_assessments.php', $php);
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
        $gen = testing_util::get_data_generator();
        switch ($cm->modname) {
            case 'assign':
                $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $userid, 'cmid' => $cm->id,
                    'onlinetext' => 'My work']);
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
        } else {
            $this->assert_ungraded($attempt);
        }
        self::queue($cm);
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
            testing_util::get_data_generator()->get_plugin_generator('block_my_assessments')
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
    protected function feedback(cm_info $cm, int $userid, int $markerid, string $stage, bool $final): void {
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
            $grade->grade = 60;
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
                'stage_identifier' => $stage, 'grade' => 60, 'isfinalgrade' => $final ? 1 : 0]);
        }
        self::queue($cm);
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
        (new \block_my_assessments\task\reconcile())->execute();
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
            'block_my_assessments_cm' => ['due', 'cutoff', 'markingdue', 'timeindexed'],
            'block_my_assessments_stu' => ['due', 'cutoff', 'timesubmitted', 'timereleased'],
            'block_my_assessments_mark' => ['timeready', 'unitdue', 'timemarked'],
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
            $this->execute('behat_forms::i_set_the_field_to', [get_string('academicyear', 'block_my_assessments'), $year]);
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
        return $this->getSession()->getPage()->find('xpath', "//div[contains(@class, 'block_my_assessments')]" .
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
            if (get_string('view_' . $key, 'block_my_assessments') === $label) {
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
     * Sets the brief guidance link.
     *
     * @Given /^the My assessments block guidance link is "(?P<url>[^"]*)"$/
     * @param string $url
     */
    public function the_guidance_link_is(string $url): void {
        set_config('briefguidanceurl', $url, 'block_my_assessments');
    }

    /**
     * Sets the Mitigate window.
     *
     * @Given /^the My assessments Mitigate window opens "(?P<days>\d+)" days before and closes "(?P<hours>\d+)" hours after the deadline$/
     * @param int $days
     * @param int $hours
     */
    public function the_mitigate_window_is(int $days, int $hours): void {
        set_config('mitigationopens', $days, 'block_my_assessments');
        set_config('mitigationcloses', $hours, 'block_my_assessments');
    }

    /**
     * Sets the feedback contact.
     *
     * @Given /^the My assessments feedback contact is "(?P<text>[^"]*)" linking to "(?P<url>[^"]*)"$/
     * @param string $text
     * @param string $url
     */
    public function the_feedback_contact_is(string $text, string $url): void {
        set_config('feedbackcontact', $text, 'block_my_assessments');
        set_config('feedbackcontacturl', $url, 'block_my_assessments');
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

    /** Hint names in steps, to the hint keys rows carry. */
    const HINTS = ['brief' => 'brief', 'unallocated' => 'unallocated', 'returned work' => 'returned',
        'feedback contact' => 'feedbackcontact', 'extension' => 'extensions'];

    /**
     * Clicks a row's hint.
     *
     * @When /^I click on the (?P<hint>brief|unallocated|returned work|feedback contact|extension) hint of "(?P<name>[^"]*)"$/
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
     * @Then /^"(?P<name>[^"]*)" should (?P<not>not )?have an? (?P<hint>brief|unallocated|returned work|feedback contact|extension) hint$/
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
        $xpath = "//div[contains(@class, 'block_my_assessments')]//section[@data-region='ma-section']" .
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
            $has = str_contains($row->getText(), $text);
            if ($has === (bool)$not) {
                throw new ExpectationException("'$name' " . ($not ? 'shows' : 'does not show') . " '$text'", $this->getSession());
            }
            return;
        }
        $this->row_date($row, $name, $text, $when);
    }

    /**
     * A row's due date.
     *
     * @Then /^"(?P<name>[^"]*)" should show the due date "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function should_show_the_due_date(string $name, string $when): void {
        $this->row_date($this->row($name), $name, get_string('date_due', 'block_my_assessments'), $when);
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
            '.block_my_assessments [data-region=\"ma-view-root\"] [data-region=\"ma-section\"] .ma-section-title'
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
     * Markers listed.
     *
     * @Then /^"(?P<name>[^"]*)" should list markers "(?P<m1>[^"]*)" and "(?P<m2>[^"]*)"$/
     * @param string $name
     * @param string $m1
     * @param string $m2
     */
    public function should_list_markers(string $name, string $m1, string $m2): void {
        $names = array_map(fn($n) => $n->getHtml(), $this->row($name)->findAll('css', '.ma-marker-name'));
        $names = array_map('html_entity_decode', $names);
        foreach ([$m1, $m2] as $marker) {
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
        $xpath = "//div[contains(@class, 'block_my_assessments')]//div[@data-region='ma-box']" .
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
}
