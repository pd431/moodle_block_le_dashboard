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

namespace block_le_dashboard\fixture;

use block_le_dashboard\local\index\indexer;
use block_le_dashboard\local\years;
use block_le_dashboard_generator;
use cm_info;
use coding_exception;
use context_course;
use context_coursecat;
use context_system;
use core_component;
use core_tag_tag;
use grade_item;
use stdClass;
use testing_util;

/**
 * What each sentence of a test does to the site, or checks in its tables: the steps that need no browser.
 *
 * The Behat context (tests/behat/behat_block_le_dashboard.php) owns the sentences and delegates each to the
 * method of the same name here; view_testcase runs the same sentences from PHPUnit (beta30, B2). Anything a
 * step writes to an activity goes through the type's fixture (B1). A check that does not hold throws failed;
 * something this site cannot do throws unavailable, which skips the scenario or test.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class steps {
    /** @var steps|null The one instance of the running scenario or test. */
    private static ?steps $instance = null;

    /**
     * The steps of the running scenario or test.
     *
     * @return steps
     */
    public static function instance(): steps {
        return self::$instance ??= new steps();
    }

    /**
     * Forgets the last scenario's state.
     */
    public static function reset(): void {
        self::$instance = null;
        phrases::$start = time();
    }

    /** Module tables searched when looking up an activity by name. */
    const MODULES = ['assign', 'quiz', 'peerwork', 'coursework', 'workshop', 'forum', 'page', 'lti', 'attendance'];
    /** Setting names in steps, to their keys. */
    const SETTINGS = ['Live window for the nightly reconcile' => 'reconcilemonths'];
    /** Hint names in steps, to the hint keys rows carry. */
    const HINTS = ['brief' => 'brief', 'unallocated' => 'unallocated', 'returned work' => 'returned',
        'returned' => 'returned', 'feedback contact' => 'feedbackcontact', 'feedback' => 'feedbackcontact',
        'feedbackcontact' => 'feedbackcontact', 'extension' => 'extensions', 'extensions' => 'extensions',
        'setup' => 'setup', 'check settings' => 'setup', 'nodeadline' => 'nodeadline', 'received' => 'received', 'late' => 'late',
        'late penalty' => 'late', 'plagiarism' => 'plagiarism', 'hidden' => 'hidden', 'nosubmission' => 'nosubmission',
        'load error' => 'loaderror', 'generic name' => 'genericname', 'defaultmarkingdue' => 'defaultmarkingdue',
        'default marking deadline' => 'defaultmarkingdue'];

    /** @var bool Ad hoc tasks don't run on this site (index-passes.feature). */
    public $noadhoc = false;
    /** @var int[] The courses the last index run did. */
    public $lastrun = [];
    /** @var int[] The courses the nightly check has done in this scenario. */
    public $nightlydone = [];
    /** @var int When the hourly index task last ran in this scenario. */
    public $lasthourly = 0;
    /** @var array|null A student index row saved by "without the index being told". */
    public ?array $savedrow = null;
    /** @var string[] Output of the parallel re-indexes. */
    public array $parallel = [];
    /** @var string|null The radius the theme was given, in rem. */
    public static ?string $themeradius = null;

    /**
     * Skips the scenario when a module it needs is not installed.
     *
     * @param string $modname
     */
    public static function require_module(string $modname): void {
        if (!core_component::get_component_directory('mod_' . $modname)) {
            throw new unavailable("mod_$modname is not installed");
        }
    }

    /**
     * An activity by name.
     *
     * @param string $name
     * @return cm_info
     */
    public function cm(string $name): cm_info {
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
    public static function userid(string $username): int {
        global $DB;
        return (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
    }

    /**
     * A course by short name.
     *
     * @param string $shortname
     * @return stdClass
     */
    public static function course(string $shortname): stdClass {
        global $DB;
        return $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
    }

    /**
     * Queues the re-index that the plugin's own event would have queued.
     *
     * @param cm_info|int $cm
     */
    public static function queue($cm): void {
        indexer::queue_cm(is_object($cm) ? (int)$cm->id : (int)$cm);
    }

    /**
     * The fixture that writes an activity's type.
     *
     * @param cm_info $cm
     * @return fixture
     */
    public static function fixture(cm_info $cm): fixture {
        return self::generator()->fixture($cm);
    }

    /**
     * The block's generator, which also loads the fixtures.
     *
     * @return block_le_dashboard_generator
     */
    public static function generator(): block_le_dashboard_generator {
        return testing_util::get_data_generator()->get_plugin_generator('block_le_dashboard');
    }

    /**
     * Runs pending index tasks, including those held back to coalesce a rush of submissions: the scenario
     * means the time has passed.
     */
    public function catch_up(): void {
        global $DB, $USER;
        if ($this->noadhoc) {
            return;
        }
        $due = time() - 1;
        $DB->set_field_select('task_adhoc', 'nextruntime', $due, "component = 'block_le_dashboard' AND nextruntime > $due");
        // As core's "I run all adhoc tasks" does, then back to whoever was logged in.
        $user = $USER;
        \core\cron::setup_user();
        ob_start();
        try {
            $now = time();
            while (
                !\core\task\manager::static_caches_cleared_since($now)
                && $task = \core\task\manager::get_next_adhoc_task($now)
            ) {
                ob_clean();
                \core\cron::run_inner_adhoc_task($task);
                if ($DB->record_exists('task_adhoc', ['id' => $task->get_id()])) {
                    throw new \coding_exception("An adhoc task failed. mtrace() output:\n\n" . ob_get_clean());
                }
            }
        } finally {
            ob_end_clean();
            \core\session\manager::set_user($user);
        }
    }

    /**
     * Configures the block's tags and roles, and puts it on the default dashboard.
     *
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
        $this->create_assessment(trim($tag, '"'), $modname, $name, $course, $opts, phrases::time($due));
    }

    /**
     * Creates and tags an assessment with no due date.
     *
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
    public function create_assessment(
        string $tag,
        string $modname,
        string $name,
        string $shortname,
        string $opts,
        ?int $due
    ): void {
        self::require_module($modname);
        self::generator()->fixture($modname)->create(self::course($shortname), [$tag], $name, $due, $opts);
    }

    /**
     * An activity of a type that has grade items, without tags.
     *
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
     * @param string $tag
     */
    public function the_formative_tag_is_changed(string $tag): void {
        set_config('formativetags', $tag, 'block_le_dashboard');
        \block_le_dashboard\task\backfill::setting_changed('block_le_dashboard/formativetags');
    }

    /**
     * Unticks an activity type and saves, as the Activity types page does.
     *
     * @param string $type
     */
    public function the_activity_type_is_unticked(string $type): void {
        $this->the_activity_type_is_enabled($type, 'not ');
        \block_le_dashboard\task\backfill::queue();
    }

    /**
     * Makes indexing of a type fail.
     *
     * @param string $modname
     */
    public function indexing_fails(string $modname): void {
        set_config('testfailtypes', $modname, 'block_le_dashboard');
    }

    /**
     * Makes reading every student's data fail, for every activity.
     */
    public function reading_student_data_fails(): void {
        set_config('testfailstudent', -1, 'block_le_dashboard');
    }

    /**
     * Undoes the indexing failures.
     */
    public function reading_student_data_works(): void {
        unset_config('testfailstudent', 'block_le_dashboard');
        unset_config('testfailtypes', 'block_le_dashboard');
    }

    /**
     * Asserts a module is not installed, skipping the scenario on a site that has it.
     *
     * @param string $modname
     */
    public function module_is_not_installed(string $modname): void {
        if (core_component::get_component_directory('mod_' . $modname)) {
            throw new unavailable("mod_$modname is installed on this site");
        }
    }

    /**
     * Hides an activity from students.
     *
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
    public static function restrict(cm_info $cm, array $conditions): void {
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
     * @param string $u1
     * @param string $u2
     * @param string $group
     * @param string $name
     */
    public function are_team_of(string $u1, string $u2, string $group, string $name): void {
        $cm = $this->cm($name);
        $groupingid = self::fixture($cm)->team_grouping($cm);
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
     * @param string $name
     * @param string $when
     */
    public function is_only_available_from(string $name, string $when): void {
        self::restrict($this->cm($name), [\availability_date\condition::get_json(
            \availability_date\condition::DIRECTION_FROM,
            phrases::time($when)
        )]);
    }

    /**
     * The user's enrolment.
     *
     * @param string $username
     * @param string $shortname
     * @return array [plugin, instance, userid]
     */
    public static function enrolment(string $username, string $shortname): array {
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
     * @param string $username
     * @param string $shortname
     * @param string $when
     */
    public function the_enrolment_ended(string $username, string $shortname, string $when): void {
        [$plugin, $instance, $userid] = self::enrolment($username, $shortname);
        $plugin->update_user_enrol($instance, $userid, null, null, phrases::time($when));
    }

    /**
     * Suspends an enrolment.
     *
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
     * @param string $username
     * @param string $view
     */
    public function last_opened(string $username, string $view): void {
        set_user_preference('block_le_dashboard_view', self::view_key($view), self::userid($username));
    }

    /**
     * Enrols a user with one role, removing any others in that course.
     *
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

    /**
     * Submits an assessment for one or two students.
     *
     * @param string $u1
     * @param string $u2
     * @param string $name
     */
    public function have_submitted(string $u1, string $u2, string $name): void {
        $cm = $this->cm($name);
        foreach (array_filter([$u1, $u2]) as $username) {
            self::fixture($cm)->submit($cm, self::userid($username));
        }
    }

    /**
     * Submits an assessment at a given time.
     *
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function submitted_at(string $username, string $name, string $when): void {
        $cm = $this->cm($name);
        self::fixture($cm)->submit($cm, self::userid($username), phrases::time($when));
    }

    /**
     * Grants an extension.
     *
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function has_an_extension(string $username, string $name, string $when): void {
        $cm = $this->cm($name);
        self::fixture($cm)->extend($cm, self::userid($username), phrases::time($when));
    }

    /**
     * A self-certified extension on a coursework.
     *
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function self_certified(string $username, string $name, string $when): void {
        $cm = $this->cm($name);
        self::fixture($cm)->extend($cm, self::userid($username), phrases::time($when), true);
    }

    /**
     * Does nothing, for scenario outlines where one example needs no event.
     */
    public function nothing_happens(): void {
    }

    /**
     * Submits a peerwork or a team assignment for a group.
     *
     * @param string $username
     * @param string $name
     * @param string $group
     */
    public function has_submitted_for_group(string $username, string $name, string $group): void {
        global $DB;
        $cm = $this->cm($name);
        $groupid = $DB->get_field('groups', 'id', ['courseid' => $cm->course, 'name' => $group], MUST_EXIST);
        self::fixture($cm)->submit_for_group($cm, self::userid($username), (int)$groupid);
    }

    /**
     * Quiz attempts for one or two students.
     *
     * @param string $u1
     * @param string $u2
     * @param string $name
     */
    public function have_finished(string $u1, string $u2, string $name): void {
        $cm = $this->cm($name);
        foreach (array_filter([$u1, $u2]) as $username) {
            self::fixture($cm)->attempts($cm, self::userid($username), 1);
        }
    }

    /**
     * Several quiz attempts.
     *
     * @param string $username
     * @param int $count
     * @param string $name
     */
    public function has_finished_attempts(string $username, int $count, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->attempts($cm, self::userid($username), $count);
    }

    /**
     * A quiz attempt the student never submitted, left to expire: it uses up an attempt but hands nothing in.
     *
     * @param string $username
     * @param string $name
     */
    public function has_abandoned_attempt(string $username, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->abandon_attempt($cm, self::userid($username));
    }

    /**
     * Grades or leaves ungraded one attempt.
     *
     * @param string $which
     * @param string $username
     * @param string $name
     * @param string $state
     */
    public function the_attempt_is(string $which, string $username, string $name, string $state): void {
        $cm = $this->cm($name);
        $userid = self::userid($username);
        if ($state === 'has been graded') {
            self::fixture($cm)->grade_attempt($cm, $userid, $which);
        } else if (!self::fixture($cm)->attempt_ungraded($cm, $userid, $which)) {
            throw new failed('The attempt is already graded');
        }
    }

    /**
     * Allocates a submission's marking.
     *
     * @param string $student
     * @param string $name
     * @param string $marker
     * @param string $marker2 A second marker (Moodle 5.0+ assign with several markers).
     */
    public function the_submission_is_allocated(string $student, string $name, string $marker = '', string $marker2 = ''): void {
        $cm = $this->cm($name);
        $markers = array_map([self::class, 'userid'], array_filter([$marker, $marker2]));
        self::fixture($cm)->allocate($cm, self::userid($student), array_values($markers));
    }

    /**
     * Allocates every submission.
     *
     * @param string $name
     * @param string $marker
     */
    public function both_submissions_are_allocated(string $name, string $marker): void {
        $cm = $this->cm($name);
        foreach (self::fixture($cm)->submitters($cm) as $userid) {
            self::fixture($cm)->allocate($cm, $userid, [self::userid($marker)]);
        }
    }

    /**
     * Makes someone the moderator of every submission of a coursework.
     *
     * @param string $marker
     * @param string $name
     */
    public function moderates(string $marker, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->moderate($cm, self::userid($marker));
    }

    /**
     * A marker marks a submission.
     *
     * @param string $marker
     * @param string $student
     * @param string $name
     */
    public function marks_the_submission(string $marker, string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->mark($cm, self::userid($student), self::userid($marker));
    }

    /**
     * Releases an assign grade to the student, then moves what happened so far a minute or
     * two into the past, so that anything later in the scenario is visibly newer.
     *
     * @param string $student
     * @param string $name
     */
    public function the_grade_is_released(string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->release($cm, self::userid($student));
    }

    /**
     * Reopens a student's assign submission as a new attempt, as a teacher would.
     *
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_reopened(string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->reopen($cm, self::userid($student));
    }

    /**
     * Both markers give feedback.
     *
     * @param string $student
     * @param string $name
     */
    public function both_markers_have_given_feedback(string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->all_markers_mark($cm, self::userid($student));
    }

    /**
     * Publishes the agreed grade.
     *
     * @param string $student
     * @param string $name
     */
    public function the_agreed_grade_is_published(string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->mark($cm, self::userid($student), get_admin()->id, 60, true, 'final_agreed_1');
        self::fixture($cm)->release($cm, self::userid($student));
    }

    /**
     * A re-index of an assessment is running: its task has started and read the old data.
     *
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
     * @param string $username
     * @param string $name
     */
    public function submits(string $username, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->submit($cm, self::userid($username));
    }

    /**
     * The running re-index finishes, having read the data before the change.
     *
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

    /**
     * Re-indexes an assessment in two processes at once.
     *
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
        $this->parallel = [];
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
            $code = proc_close($proc);
            $this->parallel[] = "exit $code: " . trim($out);
        }
    }

    /**
     * Both parallel re-indexes succeeded.
     */
    public function neither_reindex_should_fail(): void {
        foreach ($this->parallel as $result) {
            if (!str_starts_with($result, 'exit 0: ok')) {
                throw new failed("A re-index failed: $result");
            }
        }
    }

    /**
     * The index has the assessment and a row for every student.
     *
     * @param string $name
     */
    public function the_index_should_match(string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $expected = count(indexer::students((int)$cm->course));
        $rows = $DB->count_records('block_le_dashboard_stu', ['cmid' => $cm->id]);
        if (!$DB->record_exists('block_le_dashboard_cm', ['cmid' => $cm->id]) || $rows !== $expected) {
            throw new failed("'$name' has $rows student rows, not $expected");
        }
    }

    /**
     * The next write of an assessment's index rows fails.
     *
     * @param string $name
     */
    public function writing_fails_once(string $name): void {
        set_config('testfailwrite', $this->cm($name)->id, 'block_le_dashboard');
    }

    /**
     * An assessment is in the index.
     *
     * @param string $name
     * @param string $not
     */
    public function should_be_in_the_index(string $name, string $not = ''): void {
        global $DB;
        $in = $DB->record_exists('block_le_dashboard_cm', ['cmid' => $this->cm($name)->id]);
        if ($in === (bool)$not) {
            throw new failed("'$name' is " . ($in ? '' : 'not ') . 'in the index');
        }
    }

    /**
     * Moves an existing assessment's deadline, through the activity, and re-indexes it.
     *
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
     * @param string $marker
     * @param string $student
     * @param string $name
     * @param string $grade
     */
    public function grades_with(string $marker, string $student, string $name, string $grade): void {
        $cm = $this->cm($name);
        self::fixture($cm)->mark($cm, self::userid($student), self::userid($marker), (float)$grade, true);
    }

    /**
     * A student submits but the index hears nothing: their task is dropped. Saves their row.
     *
     * @param string $username
     * @param string $name
     */
    public function submitted_untold(string $username, string $name): void {
        global $DB;
        $cm = $this->cm($name);
        $userid = self::userid($username);
        self::fixture($cm)->submit($cm, $userid);
        $DB->delete_records('task_adhoc', ['classname' => '\\block_le_dashboard\\task\\reindex']);
        $this->savedrow = (array)$DB->get_record('block_le_dashboard_stu', ['cmid' => $cm->id, 'userid' => $userid]);
    }

    /**
     * A student's index row is as it was saved.
     *
     * @param string $username
     * @param string $name
     */
    public function the_index_row_should_be_unchanged(string $username, string $name): void {
        global $DB;
        $row = (array)$DB->get_record('block_le_dashboard_stu', ['cmid' => $this->cm($name)->id,
            'userid' => self::userid($username)]);
        unset($row['id'], $this->savedrow['id']);
        if ($row != $this->savedrow) {
            throw new failed("The index row of '$username' changed");
        }
    }

    /**
     * Puts two users in a new group of the course they share.
     *
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

    /**
     * Sets a number setting.
     *
     * @param string $setting
     * @param int $value
     */
    public function the_setting_is(string $setting, int $value): void {
        set_config(self::SETTINGS[$setting], $value, 'block_le_dashboard');
    }

    /**
     * A new course holding one tagged assessment.
     *
     * @param string $course
     * @param string $tag
     * @param string $modname
     * @param string $name
     * @param string $due
     */
    public function a_course_with(string $course, string $tag, string $modname, string $name, string $due): void {
        testing_util::get_data_generator()->create_course(['shortname' => $course, 'fullname' => $course]);
        $this->create_assessment($tag, $modname, $name, $course, '', phrases::time($due));
    }

    /**
     * What the index holds for an assessment.
     *
     * @param string $name
     * @param string $when
     */
    public function the_index_should_show(string $name, string $when = ''): void {
        global $DB;
        $row = $DB->get_record('block_le_dashboard_cm', ['cmid' => $this->cm($name)->id]);
        if (!$row || $row->name !== $name) {
            throw new failed("The index doesn't show '$name'");
        }
        if ($when !== '' && date('Y-m-d', (int)$row->due) !== date('Y-m-d', phrases::time($when))) {
            throw new failed("The index shows '$name' due " . date('Y-m-d', (int)$row->due));
        }
    }

    /**
     * Renames an activity the usual way (course_module_updated fires).
     *
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
     * @param string $name
     */
    public function reindexed_once(string $name): void {
        global $DB;
        $time = (int)$DB->get_field('block_le_dashboard_cm', 'timeindexed', ['cmid' => $this->cm($name)->id]);
        if ($time > time() - MINSECS) {
            throw new failed("'$name' was re-indexed again");
        }
    }

    /**
     * Runs pending index tasks.
     */
    public function the_index_catches_up(): void {
        $this->catch_up();
    }

    /**
     * Runs the nightly reconcile.
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
     * @param string $name
     * @param string $when
     */
    public function the_deadline_is_changed_directly(string $name, string $when): void {
        $cm = $this->cm($name);
        self::fixture($cm)->set_due($cm, phrases::time($when));
    }

    /**
     * Time passes: stored dates move back, so nothing is re-indexed.
     *
     * @param int $minutes
     */
    public function minutes_pass(int $minutes): void {
        $this->seconds_pass($minutes * MINSECS);
    }

    /**
     * Time passes until an assessment's deadline is one second behind, as minutes_pass().
     *
     * @param string $name
     */
    public function the_deadline_has_just_passed(string $name): void {
        global $DB;
        $due = (int)$DB->get_field('block_le_dashboard_cm', 'due', ['cmid' => $this->cm($name)->id], MUST_EXIST);
        $this->seconds_pass(max(0, $due - time() + 1));
    }

    /**
     * Time passes: stored dates move back by some seconds.
     *
     * @param int $shift
     */
    protected function seconds_pass(int $shift): void {
        global $DB;
        // The index and the activities' own times move together, so a re-index agrees.
        $fields = [
            'block_le_dashboard_cm' => ['due', 'cutoff', 'markingdue', 'timeindexed'],
            'block_le_dashboard_stu' => ['due', 'cutoff', 'timesubmitted', 'timereleased'],
            'block_le_dashboard_mark' => ['timeready', 'unitdue', 'timemarked'],
            'grade_grades' => ['timecreated', 'timemodified'],
        ] + self::generator()->time_fields();
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
            self::generator()->fixture('assign')->create($course, ['summative'], "Bulk assessment $i", time() + 7 * DAYSECS);
        }
        $this->catch_up();
    }

    /**
     * The key of a view from its tab label.
     *
     * @param string $label
     * @return string
     */
    public static function view_key(string $label): string {
        foreach (['student', 'staff', 'convenor'] as $key) {
            if (get_string('view_' . $key, 'block_le_dashboard') === $label) {
                return $key;
            }
        }
        throw new coding_exception("Unknown view '$label'");
    }

    /**
     * Switches off an activity's due date, as unticking Enable in its settings does.
     *
     * @param string $name
     */
    public function the_due_date_is_switched_off(string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->switch_due_off($cm);
    }

    /**
     * Sets the brief guidance link.
     *
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
     * @param int $hours
     */
    public function the_mitigate_window_is(int $hours): void {
        set_config('mitigationcloses', $hours, 'block_le_dashboard');
    }

    /**
     * What the block_rhb stand-in offers (tests/fixtures/rhb_stub.php).
     *
     * @param string $what
     * @param string $which
     */
    public function block_rhb_offers(string $what, string $which): void {
        set_config($which === 'the request form' ? 'behatrhbsrm' : 'behatrhbselfcert', $what, 'block_le_dashboard');
    }

    /**
     * Sets the feedback contact.
     *
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
     * Releases a grade as of a time, so its feedback date is that time.
     *
     * @param string $student
     * @param string $name
     * @param string $when
     */
    public function the_grade_was_released(string $student, string $name, string $when): void {
        global $DB;
        $this->the_grade_is_released($student, $name);
        $cm = $this->cm($name);
        $time = phrases::time($when);
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        $DB->set_field('grade_grades', 'timemodified', $time, ['itemid' => $item->id, 'userid' => self::userid($student)]);
        self::queue($cm);
    }

    /**
     * Sets the late penalties link.
     *
     * @param string $url
     */
    public function the_late_penalty_link_is(string $url): void {
        set_config('latenoteurl', $url, 'block_le_dashboard');
        // The old setting, carried into its guidance box as the upgrade does.
        \block_le_dashboard\local\guidance::migrate();
    }

    /**
     * Flags a coursework submission for an academic conduct (plagiarism) check, in mod_coursework's
     * own table. Columns the block doesn't read get their defaults, or 0 / '' when required.
     *
     * @param string $student
     * @param string $name
     * @param string $status
     */
    public function the_submission_is_flagged(string $student, string $name, string $status = '0'): void {
        $cm = $this->cm($name);
        self::fixture($cm)->flag_plagiarism($cm, self::userid($student), $status);
    }

    /**
     * A site upgraded from before beta27 whose admin never saved the hint and guidance settings.
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
     */
    public function keeps_hints_from_before_beta27(): void {
        $this->guidance_never_saved();
        \block_le_dashboard\local\config::keep_today();
    }

    /**
     * Runs the upgrade step that keeps an older site's hints and guidance as they were (config::keep_today()).
     */
    public function upgraded_from_before_the_hint_switches(): void {
        \block_le_dashboard\local\config::keep_today();
    }

    /**
     * A plugin setting's saved value.
     *
     * @param string $name
     * @param string $how
     * @param string $value
     */
    public function the_setting_should_be(string $name, string $how, string $value): void {
        $saved = get_config('block_le_dashboard', $name);
        $ok = $saved !== false && ($how === 'be' ? (string)$saved === $value : str_contains((string)$saved, $value));
        if (!$ok) {
            throw new failed("'$name' is " . var_export($saved, true));
        }
    }

    /**
     * A theme with rounder corners than Bootstrap's default, as Boost Union can set (raw SCSS on Boost).
     *
     * @param string $radius
     */
    public function the_theme_sets_its_border_radius(string $radius): void {
        set_config('scss', ":root { --bs-border-radius: $radius; }", 'theme_boost');
        theme_reset_all_caches();
        self::$themeradius = $radius;
    }

    /**
     * Ages every assessment's last full index, as if the nightly reconcile last ran then.
     *
     * @param string $when
     */
    public function the_index_was_last_updated(string $when): void {
        global $DB;
        $DB->set_field('block_le_dashboard_cm', 'timeindexed', phrases::time($when));
    }

    /**
     * Deletes a group, which removes its members without a member-removed event.
     *
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
     * @param string $method
     * @param string $shortname
     * @param string $state
     */
    public function the_enrolment_method_is(string $method, string $shortname, string $state): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => self::course($shortname)->id, 'enrol' => $method], '*', MUST_EXIST);
        $status = $state === 'enabled' ? ENROL_INSTANCE_ENABLED : ENROL_INSTANCE_DISABLED;
        enrol_get_plugin($method)->update_status($instance, $status);
    }

    /**
     * Deletes an activity from the course page: it is only flagged, and core's deletion task
     * is held back, as when the recycle bin's backup fails (EC7).
     *
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
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_reverted_to_draft(string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->revert_to_draft($cm, self::userid($student));
    }

    /**
     * A teacher locks a student's assignment submission (Prevent submission changes).
     *
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_locked(string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->lock($cm, self::userid($student));
    }

    /**
     * A teacher locks or unlocks a student's submissions through the assignment, firing Moodle's events. Nothing
     * is queued or run here, so the dashboard shows only what the block's observer did (B1, B4).
     *
     * @param string $teacher
     * @param string $action
     * @param string $student
     * @param string $name
     */
    public function the_teacher_locks_submissions(string $teacher, string $action, string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->lock($cm, self::userid($student), $action === 'locks', self::userid($teacher));
    }

    /**
     * Sets a guidance box. Quotes inside the HTML are written \" in the step.
     *
     * @param string $name
     * @param string $html
     */
    public function the_guidance_setting_is(string $name, string $html): void {
        set_config($name, str_replace('\\"', '"', $html), 'block_le_dashboard');
    }

    /**
     * Empties a guidance box.
     *
     * @param string $name
     */
    public function the_guidance_setting_is_empty(string $name): void {
        set_config($name, '', 'block_le_dashboard');
    }

    /**
     * Removes a course's start date, which core's API refuses while it has an end date.
     *
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
     */
    public function the_rebuild_task_runs(): void {
        $this->catch_up();
    }

    /**
     * Adds an index row for a course module that doesn't exist, as a lost delete event would leave.
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
     */
    public function the_index_was_rebuilt_but_not_run(): void {
        \block_le_dashboard\local\index\indexer::purge();
        set_config('backfillpending', time(), 'block_le_dashboard');
    }

    /**
     * Skips the scenario unless the site runs that Moodle version.
     *
     * @param string $version
     */
    public function the_site_runs_moodle(string $version): void {
        global $CFG;
        if (((int)$CFG->branch >= 500) !== ($version !== '4.5')) {
            throw new unavailable("Needs Moodle $version");
        }
    }

    /**
     * A grade entered straight into the gradebook.
     *
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
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function has_a_quiz_override(string $username, string $name, string $when): void {
        $cm = $this->cm($name);
        self::fixture($cm)->override($cm, self::userid($username), phrases::time($when));
    }

    /**
     * Turns off marks in every review option of a quiz.
     *
     * @param string $name
     */
    public function the_review_options_never_show_marks(string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->hide_marks($cm);
    }

    /**
     * Grades a quiz essay by hand as a marker, so the question engine records who graded it.
     *
     * @param string $marker
     * @param string $student
     * @param string $name
     */
    public function grades_the_essay(string $marker, string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->grade_attempt($cm, self::userid($student), 'last', self::userid($marker));
    }

    /**
     * A backfill that is running: it has started and has read its data.
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
            throw new failed("$waiting backfills are waiting");
        }
    }

    /**
     * Assigns a role on a category, as the category's Assign roles page does.
     *
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
            throw new failed("A re-index of $shortname is " . ($queued ? '' : 'not ') . 'queued');
        }
    }

    /**
     * A draft: an assignment saved but not submitted under required Submit, or coursework not finalised.
     *
     * @param string $username
     * @param string $name
     */
    public function has_saved_a_draft_of(string $username, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->save_draft($cm, self::userid($username));
    }

    /**
     * Coursework feedback saved with no grade.
     *
     * @param string $marker
     * @param string $student
     * @param string $name
     */
    public function has_saved_blank_feedback(string $marker, string $student, string $name): void {
        $cm = $this->cm($name);
        self::fixture($cm)->blank_feedback($cm, self::userid($student), self::userid($marker));
    }

    /**
     * Several tagged assessments with the same deadline, named "Assessment 1", "Assessment 2"...
     *
     * @param int $n
     * @param string $tag
     * @param string $modname
     * @param string $course
     * @param string $due
     */
    public function several_assessments_are_due(int $n, string $tag, string $modname, string $course, string $due): void {
        for ($i = 1; $i <= $n; $i++) {
            $this->create_assessment($tag, $modname, "Assessment $i", $course, '', phrases::time($due));
        }
    }

    /**
     * Tags every activity with a grade item in some courses.
     *
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
     * @param string $url
     */
    public function the_tagging_guidance_link_is(string $url): void {
        set_config('untaggedguidanceurl', $url, 'block_le_dashboard');
        // The old setting, carried into its guidance box as the upgrade does.
        \block_le_dashboard\local\guidance::migrate();
    }

    /**
     * Creates and tags an assessment due at a clock time in a time zone.
     *
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
     * @param string $when Such as "Mon 12 October 2026 20:00".
     * @param string $zone
     */
    public function it_is_now(string $when, string $zone): void {
        set_config('behatnow', (new \DateTime($when, new \DateTimeZone($zone)))->getTimestamp(), 'block_le_dashboard');
    }

    /**
     * Sets a user's Moodle time zone.
     *
     * @param string $username
     * @param string $zone
     */
    public function the_user_has_the_time_zone(string $username, string $zone): void {
        global $DB;
        $DB->set_field('user', 'timezone', $zone, ['id' => self::userid($username)]);
    }

    /**
     * The self-certification limit block_rhb sets.
     *
     * @param int $n
     */
    public function block_rhb_allows(int $n): void {
        set_config('selfcertmax', $n, 'block_rhb');
    }

    /**
     * Self-certified extensions a student has used, on other courseworks (mod_coursework's table).
     *
     * @param string $username
     * @param int $n
     */
    public function has_used_self_certified_extensions(string $username, int $n): void {
        self::generator()->fixture('coursework')->used_self_certs(self::userid($username), $n);
    }

    /**
     * Has the block_rhb stand-in treat an activity as a coursework, so self-certification applies to it on sites
     * without mod_coursework (the row stays what it is otherwise).
     *
     * @param string $name
     */
    public function the_rhb_stand_in_treats_as_coursework(string $name): void {
        $cm = $this->cm($name);
        $list = array_filter(explode(',', (string)get_config('block_le_dashboard', 'behatcoursework')));
        $list[] = $cm->modname . ':' . $cm->instance;
        set_config('behatcoursework', implode(',', $list), 'block_le_dashboard');
    }

    /**
     * Each index run stops as at its time limit after this many courses.
     *
     * @param int $n
     */
    public function every_index_run_has_a_limit(int $n): void {
        set_config('behatindexcourses', $n, 'block_le_dashboard');
    }

    /**
     * Ad hoc tasks never run: the steps that catch the index up leave them queued.
     */
    public function ad_hoc_tasks_dont_run(): void {
        $this->noadhoc = true;
    }

    /**
     * Rebuilds the index as the Index page's button does.
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
    public function traced(callable $run) {
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
            throw new failed("The nightly check didn't start with '$shortname'");
        }
        // Not stopped at the limit: it died, so how the run ended was never saved.
        $state['reason'] = '';
        set_config('pass_nightly', json_encode($state), 'block_le_dashboard');
    }

    /**
     * The courses the last index run did.
     *
     * @param string $courses
     */
    public function the_last_run_should_have_done(string $courses): void {
        $this->assert_courses($this->lastrun, $courses, 'The last index run');
    }

    /**
     * The courses the nightly check has done in this scenario so far.
     *
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
    public function assert_courses(array $ids, string $courses, string $what): void {
        preg_match_all('/"([^"]*)"/', $courses, $m);
        $expected = array_map(fn($name) => $this->course_id($name), $m[1]);
        if ($ids !== $expected) {
            global $DB;
            $names = array_map(fn($id) => $DB->get_field('course', 'shortname', ['id' => $id]), $ids);
            throw new failed("$what did " . (implode(', ', $names) ?: 'nothing'));
        }
    }

    /**
     * A course's id by its short name.
     *
     * @param string $shortname
     * @return int
     */
    public function course_id(string $shortname): int {
        global $DB;
        return (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Runs the command-line rebuild's work, as cli/rebuild.php does.
     */
    public function i_run_the_command_line_rebuild(): void {
        $done = $this->traced(fn($trace) => \block_le_dashboard\task\backfill::rebuild_now($trace));
        if (!$done) {
            throw new failed('The command-line rebuild did not finish');
        }
    }

    /**
     * Every course with a tagged assessment is in the index.
     */
    public function it_should_finish_with_every_course_done(): void {
        global $DB;
        $tagged = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT cm.course)
                                                 FROM {tag_instance} ti
                                                 JOIN {course_modules} cm ON cm.id = ti.itemid
                                                WHERE ti.component = 'core' AND ti.itemtype = 'course_modules'");
        $indexed = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT courseid) FROM {block_le_dashboard_cm}");
        if ($indexed !== $tagged || !\block_le_dashboard\local\index\pass::complete(\block_le_dashboard\local\index\pass::FILL)) {
            throw new failed("$indexed of $tagged courses are indexed");
        }
    }

    /**
     * Whether a first fill (backfill ad hoc task) is queued, and "Preparing" pending.
     */
    public function no_first_fill_should_be_queued(): void {
        global $DB;
        if (
            $DB->record_exists('task_adhoc', ['classname' => '\\' . \block_le_dashboard\task\backfill::class])
                || get_config('block_le_dashboard', 'backfillpending')
        ) {
            throw new failed('A first fill is still queued or pending');
        }
    }
}
