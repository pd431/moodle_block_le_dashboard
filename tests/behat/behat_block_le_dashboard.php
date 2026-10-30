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
use block_le_dashboard\fixture\failed;
use block_le_dashboard\fixture\fixture;
use block_le_dashboard\fixture\phrases;
use block_le_dashboard\fixture\steps;
use block_le_dashboard\fixture\unavailable;
use block_le_dashboard\local\index\indexer;
use block_le_dashboard\local\years;

/**
 * Steps for the My assessments scope (logic-overhaul/scope/*.feature).
 *
 * Test data is written through the fixture of each activity type (tests/generator/fixture/), the write-side twin of
 * its adapter, so no step here names a plugin's tables or classes. A fixture queues the re-index the plugin's own
 * event would have queued, so "view my dashboard" (which runs pending index tasks first) sees it.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_le_dashboard extends behat_base {
    /** Block selector. */
    const BLOCK = '.block_le_dashboard';

    /**
     * The module convenor role is needed before Background enrolments refer to it.
     *
     * @BeforeScenario @block_le_dashboard
     */
    public function create_convenor_role(): void {
        global $DB;
        // Ids restart with each scenario, so nothing memoised in this process still holds.
        \block_le_dashboard\local\memo::reset();
        // The generator loads the fixtures and the steps; a scenario starts with no step state.
        testing_util::get_data_generator()->get_plugin_generator('block_le_dashboard');
        steps::reset();
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
     * Runs a step, skipping the scenario when this site cannot do what it asks.
     *
     * @param callable $step
     */
    protected static function skipping(callable $step): void {
        try {
            $step();
        } catch (unavailable $e) {
            throw new \Moodle\BehatExtension\Exception\SkippedException($e->getMessage());
        }
    }

    /**
     * An activity by name.
     *
     * @param string $name
     * @return cm_info
     */
    protected function cm(string $name): cm_info {
        return steps::instance()->cm($name);
    }

    /**
     * A course by short name.
     *
     * @param string $shortname
     * @return stdClass
     */
    protected static function course(string $shortname): stdClass {
        return steps::course($shortname);
    }

    /**
     * Runs pending index tasks, including those held back to coalesce a rush of submissions: the scenario
     * means the time has passed.
     */
    protected function catch_up(): void {
        steps::instance()->catch_up();
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
     * The labels of a row's dates, in the order the row shows them.
     *
     * @Then /^the dates of "(?P<name>[^"]*)" should be "(?P<labels>[^"]*)" in that order$/
     * @param string $name
     * @param string $labels Comma-separated date labels.
     */
    public function the_dates_should_be(string $name, string $labels): void {
        $actual = array_map(
            fn($e) => trim($e->getText()),
            $this->row($name)->findAll('css', '.ma-row-dates [data-date] .ma-row-date-label')
        );
        $expected = array_map('trim', explode(',', $labels));
        if ($actual !== $expected) {
            throw new ExpectationException("'$name' shows its dates as \"" . implode(', ', $actual) . '"', $this->getSession());
        }
    }

    /**
     * Whether every date label of a row sits on its own line, above its date.
     *
     * @Then /^the date labels of "(?P<name>[^"]*)" should (?P<not>not )?be on their own lines$/
     * @param string $name
     * @param string $not
     */
    public function the_date_labels_should_be_on_their_own_lines(string $name, string $not = ''): void {
        $this->row($name);
        $displays = $this->evaluate_script("return Array.from(document.querySelectorAll(
            '.block_le_dashboard li[data-region=\"ma-row\"]')).filter((li) => {
                const a = li.querySelector('.ma-row-name');
                return a && a.textContent.trim() === " . json_encode($name) . ";
            }).flatMap((li) => Array.from(li.querySelectorAll('.ma-row-dates [data-date] .ma-row-date-label')))
            .map((e) => getComputedStyle(e).display);");
        if (!$displays) {
            throw new ExpectationException("'$name' has no date labels", $this->getSession());
        }
        $own = array_unique(array_map(fn($d) => $d === 'block', $displays));
        if ($own !== [!$not]) {
            throw new ExpectationException("'$name' date labels display as " . implode(', ', $displays), $this->getSession());
        }
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

    // Set-up steps.

    /**
     * Configures the block's tags and roles, and puts it on the default dashboard.
     *
     * @Given /^the My assessments block is configured with summative tag "(?P<s>[^"]*)" and formative tag "(?P<f>[^"]*)"$/
     * @param string $summative
     * @param string $formative
     */
    public function the_block_is_configured(string $summative, string $formative): void {
        steps::instance()->the_block_is_configured($summative, $formative);
    }

    /**
     * Creates an academic year and makes it the default.
     *
     * @Given /^the academic year "(?P<label>[^"]*)" starts on "(?P<date>[^"]*)"$/
     * @param string $label
     * @param string $date
     */
    public function the_academic_year_starts_on(string $label, string $date): void {
        steps::instance()->the_academic_year_starts_on($label, $date);
    }

    /**
     * Creates and tags an assessment.
     *
     * Options after "with": marker allocation, a marking deadline "...", an essay question,
     * grading method "First attempt|Last attempt|Highest grade", 2 markers, moderation,
     * marking workflow, reopened attempts, a cut-off "...", manual agreement, tagged "..." (a second tag),
     * N attempts allowed, marks never shown, marks shown immediately after only, marks shown after
     * close only, a maximum grade of 0 (quiz), marks entry only, file submission or no submission types
     * recorded (coursework), required Submit (assign: students must click Submit, which makes the submission
     * final). Also: opens "..." and (when submissions open).
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
        self::skipping(fn() => steps::instance()->an_assessment_is_due($tag, $modname, $name, $course, $opts, $due));
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
        self::skipping(fn() => steps::instance()->an_assessment_with_no_due_date($tag, $modname, $name, $course, $opts));
    }

    /**
     * An activity of a type that has grade items, without tags.
     *
     * @Given /^an? "(?P<mod>\w+)" activity with grade items exists in "(?P<course>[^"]*)"$/
     * @param string $modname
     * @param string $shortname
     */
    public function an_activity_with_grade_items_exists(string $modname, string $shortname): void {
        self::skipping(fn() => steps::instance()->an_activity_with_grade_items_exists($modname, $shortname));
    }

    /**
     * Enables or disables an activity type.
     *
     * @Given /^the "(?P<type>[^"]*)" activity type is (?P<not>not )?enabled in My assessments$/
     * @param string $type
     * @param string $not
     */
    public function the_activity_type_is_enabled(string $type, string $not = ''): void {
        steps::instance()->the_activity_type_is_enabled($type, $not);
    }

    /**
     * Saves the formative tag setting, as the settings page does: its callback queues a backfill.
     *
     * @When /^the formative tag is changed to "(?P<tag>[^"]*)" in the settings$/
     * @param string $tag
     */
    public function the_formative_tag_is_changed(string $tag): void {
        steps::instance()->the_formative_tag_is_changed($tag);
    }

    /**
     * Unticks an activity type and saves, as the Activity types page does.
     *
     * @When /^the "(?P<type>[^"]*)" activity type is unticked on the Activity types page$/
     * @param string $type
     */
    public function the_activity_type_is_unticked(string $type): void {
        steps::instance()->the_activity_type_is_unticked($type);
    }

    /**
     * Makes indexing of a type fail.
     *
     * @Given /^indexing of "(?P<mod>\w+)" activities fails with an error$/
     * @param string $modname
     */
    public function indexing_fails(string $modname): void {
        steps::instance()->indexing_fails($modname);
    }

    /**
     * Makes reading every student's data fail, for every activity.
     *
     * @Given /^reading student data fails for every student$/
     */
    public function reading_student_data_fails(): void {
        steps::instance()->reading_student_data_fails();
    }

    /**
     * Undoes the indexing failures.
     *
     * @Given /^reading student data works again$/
     */
    public function reading_student_data_works(): void {
        steps::instance()->reading_student_data_works();
    }

    /**
     * Asserts a module is not installed, skipping the scenario on a site that has it.
     *
     * @Given /^"mod_(?P<mod>\w+)" is not installed$/
     * @param string $modname
     */
    public function module_is_not_installed(string $modname): void {
        self::skipping(fn() => steps::instance()->module_is_not_installed($modname));
    }

    // Access steps.

    /**
     * Hides an activity from students.
     *
     * @Given /^"(?P<name>[^"]*)" is hidden from students$/
     * @param string $name
     */
    public function is_hidden_from_students(string $name): void {
        steps::instance()->is_hidden_from_students($name);
    }

    /**
     * Hides the section an activity is in.
     *
     * @Given /^the section containing "(?P<name>[^"]*)" is hidden$/
     * @param string $name
     */
    public function the_section_is_hidden(string $name): void {
        steps::instance()->the_section_is_hidden($name);
    }

    /**
     * Hides a course.
     *
     * @Given /^the course "(?P<course>[^"]*)" is hidden from students$/
     * @param string $shortname
     */
    public function the_course_is_hidden(string $shortname): void {
        steps::instance()->the_course_is_hidden($shortname);
    }

    /**
     * Restricts an activity to a group.
     *
     * @Given /^"(?P<name>[^"]*)" is restricted to group "(?P<group>[^"]*)"$/
     * @param string $name
     * @param string $group
     */
    public function is_restricted_to_group(string $name, string $group): void {
        steps::instance()->is_restricted_to_group($name, $group);
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
        steps::instance()->is_restricted_to_group_not($name, $group, $other);
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
        steps::instance()->is_added_to_group($user, $how, $group);
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
        steps::instance()->are_team_of($u1, $u2, $group, $name);
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
        steps::instance()->is_available_once_complete($name, $other, $shortname);
    }

    /**
     * Restricts an activity to open later.
     *
     * @Given /^"(?P<name>[^"]*)" is only available from "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function is_only_available_from(string $name, string $when): void {
        steps::instance()->is_only_available_from($name, $when);
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
        steps::instance()->the_enrolment_ended($username, $shortname, $when);
    }

    /**
     * Suspends an enrolment.
     *
     * @Given /^the enrolment of "(?P<user>[^"]*)" in "(?P<course>[^"]*)" is suspended$/
     * @param string $username
     * @param string $shortname
     */
    public function the_enrolment_is_suspended(string $username, string $shortname): void {
        steps::instance()->the_enrolment_is_suspended($username, $shortname);
    }

    /**
     * Unenrols a user.
     *
     * @Given /^"(?P<user>[^"]*)" is unenrolled from "(?P<course>[^"]*)"$/
     * @param string $username
     * @param string $shortname
     */
    public function is_unenrolled(string $username, string $shortname): void {
        steps::instance()->is_unenrolled($username, $shortname);
    }

    /**
     * Creates a role from an archetype.
     *
     * @Given /^the role "(?P<name>[^"]*)" exists, based on "(?P<archetype>[^"]*)"$/
     * @param string $name
     * @param string $archetype
     */
    public function the_role_exists(string $name, string $archetype): void {
        steps::instance()->the_role_exists($name, $archetype);
    }

    /**
     * Adds a role to one of the block's role settings.
     *
     * @Given /^the My assessments block treats "(?P<name>[^"]*)" as a (?P<kind>student|staff|module convenor) role$/
     * @param string $name Role name or shortname
     * @param string $kind
     */
    public function treats_as_role(string $name, string $kind): void {
        steps::instance()->treats_as_role($name, $kind);
    }

    /**
     * Saves the view a user last opened.
     *
     * @Given /^"(?P<user>[^"]*)" last opened the "(?P<view>[^"]*)"$/
     * @param string $username
     * @param string $view
     */
    public function last_opened(string $username, string $view): void {
        steps::instance()->last_opened($username, $view);
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
        steps::instance()->is_enrolled_as($username, $shortname, $role, $only);
    }

    /**
     * Customises a section title through the local language pack.
     *
     * @Given /^the language string for the "(?P<title>[^"]*)" section title is customised to "(?P<text>[^"]*)"$/
     * @param string $title
     * @param string $text
     */
    public function the_section_title_is_customised(string $title, string $text): void {
        steps::instance()->the_section_title_is_customised($title, $text);
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
        steps::instance()->have_submitted($u1, $u2, $name);
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
        steps::instance()->submitted_at($username, $name, $when);
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
        steps::instance()->has_an_extension($username, $name, $when);
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
        steps::instance()->self_certified($username, $name, $when);
    }

    /**
     * A mitigation of one of the activity's own types (coursework: permanent or temporary).
     *
     * @Given /^"(?P<user>[^"]*)" has an? "(?P<type>[^"]*)" mitigation on "(?P<name>[^"]*)"$/
     * @param string $username
     * @param string $type
     * @param string $name
     */
    public function has_a_mitigation_on(string $username, string $type, string $name): void {
        steps::instance()->has_a_mitigation_on($username, $type, $name);
    }

    /**
     * A student's mitigation of a type is taken away.
     *
     * @When /^the "(?P<type>[^"]*)" mitigation of "(?P<user>[^"]*)" on "(?P<name>[^"]*)" is removed$/
     * @param string $type
     * @param string $username
     * @param string $name
     */
    public function the_mitigation_is_removed(string $type, string $username, string $name): void {
        steps::instance()->the_mitigation_is_removed($type, $username, $name);
    }

    /**
     * Does nothing, for scenario outlines where one example needs no event.
     *
     * @Given /^nothing happens$/
     */
    public function nothing_happens(): void {
        steps::instance()->nothing_happens();
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
        steps::instance()->has_submitted_for_group($username, $name, $group);
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
        steps::instance()->have_finished($u1, $u2, $name);
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
        steps::instance()->has_finished_attempts($username, $count, $name);
    }

    /**
     * A quiz attempt the student never submitted, left to expire: it uses up an attempt but hands nothing in.
     *
     * @Given /^"(?P<user>[^"]*)" has abandoned an attempt at "(?P<name>[^"]*)"$/
     * @param string $username
     * @param string $name
     */
    public function has_abandoned_attempt(string $username, string $name): void {
        steps::instance()->has_abandoned_attempt($username, $name);
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
        steps::instance()->the_attempt_is($which, $username, $name, $state);
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
        steps::instance()->the_submission_is_allocated($student, $name, $marker, $marker2);
    }

    /**
     * Allocates every submission.
     *
     * @Given /^both submissions of "(?P<name>[^"]*)" are allocated to "(?P<marker>[^"]*)"$/
     * @param string $name
     * @param string $marker
     */
    public function both_submissions_are_allocated(string $name, string $marker): void {
        steps::instance()->both_submissions_are_allocated($name, $marker);
    }

    /**
     * Makes someone the moderator of every submission of a coursework.
     *
     * @Given /^"(?P<marker>[^"]*)" moderates "(?P<name>[^"]*)"$/
     * @param string $marker
     * @param string $name
     */
    public function moderates(string $marker, string $name): void {
        steps::instance()->moderates($marker, $name);
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
        steps::instance()->marks_the_submission($marker, $student, $name);
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
        steps::instance()->the_grade_is_released($student, $name);
    }

    /**
     * Reopens a student's assign submission as a new attempt, as a teacher would.
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is reopened$/
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_reopened(string $student, string $name): void {
        steps::instance()->the_submission_is_reopened($student, $name);
    }

    /**
     * Both markers give feedback.
     *
     * @Given /^both markers have given feedback on the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)"$/
     * @param string $student
     * @param string $name
     */
    public function both_markers_have_given_feedback(string $student, string $name): void {
        steps::instance()->both_markers_have_given_feedback($student, $name);
    }

    /**
     * Publishes the agreed grade.
     *
     * @Given /^the agreed grade for "(?P<student>[^"]*)" on "(?P<name>[^"]*)" is published$/
     * @param string $student
     * @param string $name
     */
    public function the_agreed_grade_is_published(string $student, string $name): void {
        steps::instance()->the_agreed_grade_is_published($student, $name);
    }

    // Freshness steps (perf review F1 to F5, F9).

    /**
     * A re-index of an assessment is running: its task has started and read the old data.
     *
     * @Given /^a re-index of "(?P<name>[^"]*)" has started$/
     * @param string $name
     */
    public function a_reindex_has_started(string $name): void {
        steps::instance()->a_reindex_has_started($name);
    }

    /**
     * A student submits (the activity's own events fire).
     *
     * @When /^"(?P<user>[^"]*)" submits "(?P<name>[^"]*)"$/
     * @param string $username
     * @param string $name
     */
    public function submits(string $username, string $name): void {
        steps::instance()->submits($username, $name);
    }

    /**
     * The running re-index finishes, having read the data before the change.
     *
     * @When /^the running re-index of "(?P<name>[^"]*)" finishes$/
     * @param string $name
     */
    public function the_running_reindex_finishes(string $name): void {
        steps::instance()->the_running_reindex_finishes($name);
    }

    /**
     * Re-indexes an assessment in two processes at once.
     *
     * @When /^"(?P<name>[^"]*)" is re-indexed twice at the same time$/
     * @param string $name
     */
    public function reindexed_twice_at_once(string $name): void {
        steps::instance()->reindexed_twice_at_once($name);
    }

    /**
     * Both parallel re-indexes succeeded.
     *
     * @Then /^neither re-index should fail$/
     */
    public function neither_reindex_should_fail(): void {
        steps::instance()->neither_reindex_should_fail();
    }

    /**
     * The index has the assessment and a row for every student.
     *
     * @Then /^the index for "(?P<name>[^"]*)" should match the activity$/
     * @param string $name
     */
    public function the_index_should_match(string $name): void {
        steps::instance()->the_index_should_match($name);
    }

    /**
     * The next write of an assessment's index rows fails.
     *
     * @Given /^writing the index for "(?P<name>[^"]*)" fails once$/
     * @param string $name
     */
    public function writing_fails_once(string $name): void {
        steps::instance()->writing_fails_once($name);
    }

    /**
     * An assessment is in the index.
     *
     * @Then /^"(?P<name>[^"]*)" should (?P<not>not )?be in the index$/
     * @param string $name
     * @param string $not
     */
    public function should_be_in_the_index(string $name, string $not = ''): void {
        steps::instance()->should_be_in_the_index($name, $not);
    }

    /**
     * Moves an existing assessment's deadline, through the activity, and re-indexes it.
     *
     * @Given /^"(?P<name>[^"]*)" was due "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function was_due(string $name, string $when): void {
        steps::instance()->was_due($name, $when);
    }

    /**
     * Hides or shows an activity's grade item in the gradebook.
     *
     * @Given /^the grade item of "(?P<name>[^"]*)" is (?P<state>hidden|shown)$/
     * @param string $name
     * @param string $state
     */
    public function the_grade_item_is(string $name, string $state): void {
        steps::instance()->the_grade_item_is($name, $state);
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
        steps::instance()->grades_with($marker, $student, $name, $grade);
    }

    /**
     * A final grade saved under a grader id that is no real user, as Turnitin's grade sync does (-1).
     *
     * @Given /^the grade of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is saved as "(?P<grade>[\d.]+)" with grader id "(?P<id>-?\d+)"$/
     * @param string $student
     * @param string $name
     * @param string $grade
     * @param string $id
     */
    public function grade_saved_with_grader_id(string $student, string $name, string $grade, string $id): void {
        steps::instance()->grade_saved_with_grader_id($student, $name, $grade, (int)$id);
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
     * The logged-in student hands in a file through the assignment's page.
     *
     * @When /^I submit a file to "(?P<name>[^"]*)"$/
     * @param string $name
     */
    public function i_submit_a_file_to(string $name): void {
        $this->getSession()->visit($this->locate_path('/mod/assign/view.php?id=' . $this->cm($name)->id));
        $this->execute('behat_forms::press_button', [get_string('addsubmission', 'assign')]);
        $this->execute(
            'behat_repository_upload::i_upload_file_to_filemanager',
            ['lib/tests/fixtures/empty.txt', get_string('pluginname', 'assignsubmission_file')]
        );
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
        steps::instance()->submitted_untold($username, $name);
    }

    /**
     * A student uploads a file to a coursework and the file sub-plugin's event fires; nothing else tells the index.
     *
     * @When /^"(?P<user>[^"]*)" uploads a file to coursework "(?P<name>[^"]*)" and the upload event fires$/
     * @param string $username
     * @param string $name
     */
    public function uploads_a_file_and_the_event_fires(string $username, string $name): void {
        steps::instance()->uploads_a_file_and_the_event_fires($username, $name);
    }

    /**
     * A student self-certifies through block_rhb and coursework's event fires; nothing else tells the index.
     *
     * @When /^"(?P<user>[^"]*)" self-certifies "(?P<name>[^"]*)" to "(?P<when>[^"]*)" and the mitigation added event fires$/
     * @param string $username
     * @param string $name
     * @param string $when
     */
    public function self_certifies_and_the_event_fires(string $username, string $name, string $when): void {
        steps::instance()->self_certifies_and_the_event_fires($username, $name, $when);
    }

    /**
     * A student's self-certification is removed and coursework's event fires; nothing else tells the index.
     *
     * @When /^"(?P<user>[^"]*)" removes the self-certification of "(?P<name>[^"]*)" and the mitigation removed event fires$/
     * @param string $username
     * @param string $name
     */
    public function removes_the_self_certification_and_the_event_fires(string $username, string $name): void {
        steps::instance()->removes_the_self_certification_and_the_event_fires($username, $name);
    }

    /**
     * A student's index row is as it was saved.
     *
     * @Then /^the index row of "(?P<user>[^"]*)" for "(?P<name>[^"]*)" should be unchanged$/
     * @param string $username
     * @param string $name
     */
    public function the_index_row_should_be_unchanged(string $username, string $name): void {
        steps::instance()->the_index_row_should_be_unchanged($username, $name);
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
        steps::instance()->are_in_group($u1, $u2, $group);
    }

    /**
     * Sets a number setting.
     *
     * @Given /^the "(?P<setting>[^"]*)" setting is "(?P<value>\d+)" months$/
     * @param string $setting
     * @param int $value
     */
    public function the_setting_is(string $setting, int $value): void {
        steps::instance()->the_setting_is($setting, $value);
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
        self::skipping(fn() => steps::instance()->a_course_with($course, $tag, $modname, $name, $due));
    }

    /**
     * What the index holds for an assessment.
     *
     * @Then /^the index should (?:still )?show "(?P<name>[^"]*)"(?: due "(?P<when>[^"]*)")?$/
     * @param string $name
     * @param string $when
     */
    public function the_index_should_show(string $name, string $when = ''): void {
        steps::instance()->the_index_should_show($name, $when);
    }

    /**
     * Renames an activity the usual way (course_module_updated fires).
     *
     * @When /^"(?P<name>[^"]*)" is renamed to "(?P<new>[^"]*)"$/
     * @param string $name
     * @param string $new
     */
    public function is_renamed(string $name, string $new): void {
        steps::instance()->is_renamed($name, $new);
    }

    /**
     * An assessment was not re-indexed since time last passed: its index time is still in the past.
     *
     * @Then /^"(?P<name>[^"]*)" should have been re-indexed once$/
     * @param string $name
     */
    public function reindexed_once(string $name): void {
        steps::instance()->reindexed_once($name);
    }

    // Index steps.

    /**
     * Runs pending index tasks.
     *
     * @Given /^the My assessments index (?:is up to date|catches up)$/
     */
    public function the_index_catches_up(): void {
        steps::instance()->the_index_catches_up();
    }

    /**
     * Runs the nightly reconcile.
     *
     * @When /^the My assessments nightly reconcile runs$/
     */
    public function the_nightly_reconcile_runs(): void {
        steps::instance()->the_nightly_reconcile_runs();
    }

    /**
     * Time passes until the deadline is one second behind.
     *
     * @When /^the deadline of "(?P<name>[^"]*)" has just passed$/
     * @param string $name
     */
    public function the_deadline_has_just_passed(string $name): void {
        steps::instance()->the_deadline_has_just_passed($name);
    }

    /**
     * Changes a deadline without any event.
     *
     * @Given /^the deadline of "(?P<name>[^"]*)" is changed directly in the database to "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function the_deadline_is_changed_directly(string $name, string $when): void {
        steps::instance()->the_deadline_is_changed_directly($name, $when);
    }

    /**
     * Time passes: stored dates move back, so nothing is re-indexed.
     *
     * @When /^(?P<n>\d+) minutes pass$/
     * @param int $minutes
     */
    public function minutes_pass(int $minutes): void {
        steps::instance()->minutes_pass($minutes);
    }

    /**
     * Gives a convenor many modules, each with a tagged assessment.
     *
     * @Given /^"(?P<user>[^"]*)" convenes (?P<n>\d+) modules with tagged assessments$/
     * @param string $username
     * @param int $count
     */
    public function convenes_modules(string $username, int $count): void {
        self::skipping(fn() => steps::instance()->convenes_modules($username, $count));
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
     * Opens one view for an academic year, without logging in again.
     *
     * @When /^I view the "(?P<view>[^"]*)" for academic year "(?P<year>[^"]*)"$/
     * @param string $view
     * @param string $year
     */
    public function i_view_for_year(string $view, string $year): void {
        $this->i_view_my_dashboard_again();
        $this->open_view($view);
        $this->execute('behat_forms::i_set_the_field_to', [get_string('academicyear', 'block_le_dashboard'), $year]);
        $this->wait_for_pending_js();
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
        return steps::view_key($label);
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
            throw new failed("The '$view' is not open (open: '$open')");
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
     * Clears an assessment's latest individual deadline in the index, as rows indexed before beta31 have it.
     *
     * @Given /^the index row of "(?P<name>[^"]*)" has no latest individual deadline$/
     * @param string $name
     */
    public function the_index_row_has_no_latest_due(string $name): void {
        steps::instance()->the_index_row_has_no_latest_due($name);
    }

    /**
     * Switches off an activity's due date, as unticking Enable in its settings does.
     *
     * @When /^the due date of "(?P<name>[^"]*)" is switched off$/
     * @param string $name
     */
    public function the_due_date_is_switched_off(string $name): void {
        steps::instance()->the_due_date_is_switched_off($name);
    }

    /**
     * Sets the brief guidance link.
     *
     * @Given /^the My assessments block guidance link is "(?P<url>[^"]*)"$/
     * @param string $url
     */
    public function the_guidance_link_is(string $url): void {
        steps::instance()->the_guidance_link_is($url);
    }

    /**
     * Sets how long Past work keeps Mitigate after the deadline.
     *
     * @Given /^the My assessments Mitigate stays open "(?P<hours>\d+)" hours after the deadline$/
     * @param int $hours
     */
    public function the_mitigate_window_is(int $hours): void {
        steps::instance()->the_mitigate_window_is($hours);
    }

    /**
     * What the block_rhb stand-in offers (tests/fixtures/rhb_stub.php).
     *
     * @Given /^block_rhb offers "(?P<what>a link|a delete link|a message only|nothing|no link|an unreadable link)" for (?P<which>self-certification|the request form)$/
     * @param string $what
     * @param string $which
     */
    public function block_rhb_offers(string $what, string $which): void {
        steps::instance()->block_rhb_offers($what, $which);
    }

    /**
     * Sets the feedback contact.
     *
     * @Given /^the My assessments feedback contact is "(?P<text>[^"]*)" linking to "(?P<url>[^"]*)"$/
     * @param string $text
     * @param string $url
     */
    public function the_feedback_contact_is(string $text, string $url): void {
        steps::instance()->the_feedback_contact_is($text, $url);
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
        steps::instance()->the_grade_was_released($student, $name, $when);
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
     * Sets the site's grade display type and re-indexes.
     *
     * @Given /^the grade display type is "(?P<label>[^"]*)"$/
     * @param string $label
     */
    public function the_grade_display_type_is(string $label): void {
        steps::instance()->the_grade_display_type_is($label);
    }

    /**
     * The words of a row's grade line: its label and its grade, without the hints after them.
     *
     * @Then /^the grade line of "(?P<name>[^"]*)" should read "(?P<text>[^"]*)"$/
     * @param string $name
     * @param string $text
     */
    public function the_grade_line_should_read(string $name, string $text): void {
        $grade = $this->row($name)->find('css', '[data-region="ma-grade"]');
        $label = $grade ? $grade->find('css', '.ma-grade-label') : null;
        $value = $grade ? $grade->find('css', '.ma-grade-value') : null;
        $got = $label && $value ? trim($label->getText()) . ' ' . trim($value->getText()) : '';
        if ($got !== $text) {
            throw new ExpectationException("The grade line of '$name' reads '$got', not '$text'", $this->getSession());
        }
    }

    /**
     * A row's grade line is on one line, or breaks only between its label and its grade: the label and the grade each
     * sit on one line box, and the grade is on the label's line or the next.
     *
     * @Then /^the grade line of "(?P<name>[^"]*)" should be on one line or break only before the grade$/
     * @param string $name
     */
    public function the_grade_line_breaks_only_before_the_grade(string $name): void {
        $rowid = $this->row($name)->getAttribute('id');
        $lines = $this->evaluate_script("return (function() {
            var grade = document.querySelector('#$rowid [data-region=\"ma-grade\"]');
            var tops = function(el) {
                var range = document.createRange();
                range.selectNodeContents(el);
                var seen = [];
                Array.from(range.getClientRects()).forEach(function(r) {
                    var top = Math.round(r.top);
                    if (r.width > 0 && !seen.some(function(t) { return Math.abs(t - top) < 4; })) {
                        seen.push(top);
                    }
                });
                return seen;
            };
            return grade ? {label: tops(grade.querySelector('.ma-grade-label')),
                value: tops(grade.querySelector('.ma-grade-value'))} : null;
        })()");
        if (!$lines || count($lines['label']) !== 1 || count($lines['value']) !== 1) {
            throw new ExpectationException("The grade line of '$name' breaks inside the label or the grade: " .
                json_encode($lines), $this->getSession());
        }
        if ($lines['value'][0] < $lines['label'][0] - 3) {
            throw new ExpectationException("The grade of '$name' sits above its label", $this->getSession());
        }
    }

    /**
     * Sets the late penalties link.
     *
     * @Given /^the My assessments late penalty link is "(?P<url>[^"]*)"$/
     * @param string $url
     */
    public function the_late_penalty_link_is(string $url): void {
        steps::instance()->the_late_penalty_link_is($url);
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
        steps::instance()->the_submission_is_flagged($student, $name, $status);
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
        steps::instance()->guidance_never_saved();
    }

    /**
     * Hints and guidance as a site upgraded to beta27 has them (config::keep_today()): every hint on, marking deadlines
     * shown to students as set, and the old guidance text. For scenarios written before beta27's neutral defaults.
     *
     * @Given /^the My assessments block keeps its hints and guidance from before beta27$/
     */
    public function keeps_hints_from_before_beta27(): void {
        steps::instance()->keeps_hints_from_before_beta27();
    }

    /**
     * Runs the upgrade step that keeps an older site's hints and guidance as they were (config::keep_today()).
     *
     * @When /^the site was upgraded from before the hint switches existed$/
     */
    public function upgraded_from_before_the_hint_switches(): void {
        steps::instance()->upgraded_from_before_the_hint_switches();
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
        steps::instance()->the_setting_should_be($name, $how, $value);
    }

    /**
     * A theme with rounder corners than Bootstrap's default, as Boost Union can set (raw SCSS on Boost).
     *
     * @Given /^the theme sets its border radius to "(?P<radius>[0-9.]+rem)"$/
     * @param string $radius
     */
    public function the_theme_sets_its_border_radius(string $radius): void {
        steps::instance()->the_theme_sets_its_border_radius($radius);
    }

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
        $expected = steps::$themeradius === null ? null : round((float)steps::$themeradius * 16) . 'px';
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

    /**
     * The flags under a row's name, in order (hints beside a date, pill or grade aren't flags).
     *
     * @Then /^the hints of "(?P<name>[^"]*)" should be "(?P<hints>[^"]*)" in that order$/
     * @param string $name
     * @param string $hints Comma-separated hint names, as in HINTS.
     */
    public function the_hints_should_be(string $name, string $hints): void {
        $actual = array_map(fn($e) => $e->getAttribute('data-hint'), $this->row($name)->findAll('css', '.ma-flags [data-hint]'));
        $expected = array_map(fn($h) => steps::HINTS[trim($h)], explode(',', $hints));
        if ($actual !== $expected) {
            throw new ExpectationException("'$name' has the hints " . implode(', ', $actual), $this->getSession());
        }
    }

    /**
     * Whether a hint sits beside one of a row's dates.
     *
     * @Then /^the (?P<hint>[a-z ]+?) hint of "(?P<name>[^"]*)" should sit beside its "(?P<label>[^"]*)" date$/
     * @param string $hint
     * @param string $name
     * @param string $label
     */
    public function the_hint_should_sit_beside_its_date(string $hint, string $name, string $label): void {
        $xpath = ".//div[@data-date][span[contains(@class, 'ma-row-date-label')][normalize-space(.) = " .
            behat_context_helper::escape($label) . "]]//*[@data-hint=" . behat_context_helper::escape(steps::HINTS[$hint]) . "]";
        if (!$this->row($name)->find('xpath', $xpath)) {
            throw new ExpectationException("'$name' has no $hint hint beside its '$label' date", $this->getSession());
        }
        if ($this->row($name)->find('css', '.ma-flags [data-hint="' . steps::HINTS[$hint] . '"]')) {
            throw new ExpectationException("'$name' also has the $hint hint in its flags", $this->getSession());
        }
    }

    /**
     * Clicks a row's hint.
     *
     * @When /^I click on the (?P<hint>[a-z ]+?) hint of "(?P<name>[^"]*)"$/
     * @param string $hint
     * @param string $name
     */
    public function i_click_on_the_hint(string $hint, string $name): void {
        $button = $this->row($name)->find('css', '[data-hint="' . steps::HINTS[$hint] . '"]')
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
        $has = (bool)$this->row($name)->find('css', '[data-hint="' . steps::HINTS[$hint] . '"]');
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
     * A row's due date, labelled Due or, under an extension, Extended deadline.
     *
     * @Then /^"(?P<name>[^"]*)" should show the due date "(?P<when>[^"]*)"$/
     * @param string $name
     * @param string $when
     */
    public function should_show_the_due_date(string $name, string $when): void {
        // A student's deadline under an extension is labelled as the extended one (CS5).
        $row = $this->row($name);
        $label = get_string('date_due', 'block_le_dashboard');
        $extended = get_string('date_extendeddeadline', 'block_le_dashboard');
        try {
            $this->row_date($row, $name, $label, $when);
        } catch (\Throwable $e) {
            try {
                $this->row_date($row, $name, $extended, $when);
            } catch (\Throwable $other) {
                throw $e;
            }
        }
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
        $expected = date('Y-m-d', phrases::time($when));
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
        steps::instance()->the_index_was_last_updated($when);
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
        steps::instance()->the_group_is_deleted($group);
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
        steps::instance()->the_enrolment_method_is($method, $shortname, $state);
    }

    /**
     * Deletes an activity from the course page: it is only flagged, and core's deletion task
     * is held back, as when the recycle bin's backup fails (EC7).
     *
     * @Given /^"(?P<name>[^"]*)" is deleted from the course page$/
     * @param string $name
     */
    public function deleted_from_the_course_page(string $name): void {
        steps::instance()->deleted_from_the_course_page($name);
    }

    /**
     * Reverts an assign submission to draft, as a marker does to let the student rework it.
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is reverted to draft$/
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_reverted_to_draft(string $student, string $name): void {
        steps::instance()->the_submission_is_reverted_to_draft($student, $name);
    }

    /**
     * A teacher locks a student's assignment submission (Prevent submission changes).
     *
     * @Given /^the submission of "(?P<student>[^"]*)" for "(?P<name>[^"]*)" is locked by a teacher$/
     * @param string $student
     * @param string $name
     */
    public function the_submission_is_locked(string $student, string $name): void {
        steps::instance()->the_submission_is_locked($student, $name);
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
        steps::instance()->the_teacher_locks_submissions($teacher, $action, $student, $name);
    }

    /**
     * Sets a guidance box. Quotes inside the HTML are written \" in the step.
     *
     * @Given /^the "(?P<name>guidance_[a-z]+)" setting is "(?P<html>.*)"$/
     * @param string $name
     * @param string $html
     */
    public function the_guidance_setting_is(string $name, string $html): void {
        steps::instance()->the_guidance_setting_is($name, $html);
    }

    /**
     * Empties a guidance box.
     *
     * @Given /^the "(?P<name>guidance_[a-z]+)" setting is empty$/
     * @param string $name
     */
    public function the_guidance_setting_is_empty(string $name): void {
        steps::instance()->the_guidance_setting_is_empty($name);
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
        steps::instance()->the_start_date_is_removed($shortname);
    }

    /**
     * Runs the rebuild (the backfill ad hoc task).
     *
     * @When /^the rebuild task runs$/
     */
    public function the_rebuild_task_runs(): void {
        steps::instance()->the_rebuild_task_runs();
    }

    /**
     * Adds an index row for a course module that doesn't exist, as a lost delete event would leave.
     *
     * @Given /^the index holds a row for an activity that no longer exists$/
     */
    public function the_index_holds_an_orphan(): void {
        steps::instance()->the_index_holds_an_orphan();
    }

    /**
     * Empties the index as the admin page does, without letting the rebuild run.
     *
     * @Given /^an admin has rebuilt the index and the rebuild task has not run yet$/
     */
    public function the_index_was_rebuilt_but_not_run(): void {
        steps::instance()->the_index_was_rebuilt_but_not_run();
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
        $key = steps::HINTS[$key] ?? $key;
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
        self::skipping(fn() => steps::instance()->the_site_runs_moodle($version));
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
        $key = steps::HINTS[$key] ?? $key;
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
        steps::instance()->the_gradebook_grade_is_set($student, $name, $grade);
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
        steps::instance()->has_a_quiz_override($username, $name, $when);
    }

    /**
     * Turns off marks in every review option of a quiz.
     *
     * @Given /^the review options of "(?P<name>[^"]*)" never show marks$/
     * @param string $name
     */
    public function the_review_options_never_show_marks(string $name): void {
        steps::instance()->the_review_options_never_show_marks($name);
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
        steps::instance()->grades_the_essay($marker, $student, $name);
    }

    /**
     * A backfill that is running: it has started and has read its data.
     *
     * @Given /^a backfill has started$/
     */
    public function a_backfill_has_started(): void {
        steps::instance()->a_backfill_has_started();
    }

    /**
     * How many backfills are queued and not started.
     *
     * @Then /^"(?P<n>\d+)" backfills? should be waiting to start$/
     * @param int $n
     */
    public function backfills_should_be_waiting(int $n): void {
        steps::instance()->backfills_should_be_waiting($n);
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
        steps::instance()->is_given_the_role_on_the_category($username, $role, $category);
    }

    /**
     * Whether a course re-index is waiting.
     *
     * @Then /^a re-index of the course "(?P<course>[^"]*)" should (?P<not>not )?be queued$/
     * @param string $shortname
     * @param string $not
     */
    public function a_course_reindex_should_be_queued(string $shortname, string $not = ''): void {
        steps::instance()->a_course_reindex_should_be_queued($shortname, $not);
    }

    /**
     * Whether a re-index of an assessment is waiting.
     *
     * @Then /^a re-index of "(?P<name>[^"]*)" should (?P<not>not )?be queued$/
     * @param string $name
     * @param string $not
     */
    public function a_reindex_should_be_queued(string $name, string $not = ''): void {
        steps::instance()->a_reindex_should_be_queued($name, $not);
    }

    /**
     * A draft: an assignment saved but not submitted under required Submit, or coursework not finalised.
     *
     * @Given /^"(?P<user>[^"]*)" has saved a draft of "(?P<name>[^"]*)"$/
     * @param string $username
     * @param string $name
     */
    public function has_saved_a_draft_of(string $username, string $name): void {
        steps::instance()->has_saved_a_draft_of($username, $name);
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
        steps::instance()->has_saved_blank_feedback($marker, $student, $name);
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
        self::skipping(fn() => steps::instance()->several_assessments_are_due($n, $tag, $modname, $course, $due));
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
            throw new failed('The search box outer corners are ' . implode(', ', $outer));
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
            throw new failed('The search box inner corners are ' . implode(', ', $inner));
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
            throw new failed("Focus ring: group '{$c['group']['box-shadow']}', input " .
                "'{$c['input']['box-shadow']}'");
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
        steps::instance()->every_compatible_activity_is_tagged($courses, $tag);
    }

    /**
     * Sets the tagging guidance link.
     *
     * @Given /^the My assessments tagging guidance link is "(?P<url>[^"]*)"$/
     * @param string $url
     */
    public function the_tagging_guidance_link_is(string $url): void {
        steps::instance()->the_tagging_guidance_link_is($url);
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
        self::skipping(fn() => steps::instance()->an_assessment_is_due_in_zone($tag, $modname, $name, $course, $due, $zone));
    }

    /**
     * Sets the time the dashboard is drawn at (config::now()), a date and time in a time zone.
     *
     * @Given /^it is now "(?P<when>[^"]*)" in "(?P<zone>[^"]*)"$/
     * @param string $when Such as "Mon 12 October 2026 20:00".
     * @param string $zone
     */
    public function it_is_now(string $when, string $zone): void {
        steps::instance()->it_is_now($when, $zone);
    }

    /**
     * Sets a user's Moodle time zone.
     *
     * @Given /^the user "(?P<user>[^"]*)" has the time zone "(?P<zone>[^"]*)"$/
     * @param string $username
     * @param string $zone
     */
    public function the_user_has_the_time_zone(string $username, string $zone): void {
        steps::instance()->the_user_has_the_time_zone($username, $zone);
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
        steps::instance()->block_rhb_allows($n);
    }

    /**
     * Self-certified extensions a student has used, on other courseworks (mod_coursework's table).
     *
     * @Given /^"(?P<user>[^"]*)" has used (?P<n>\d+) self-certified extensions?$/
     * @param string $username
     * @param int $n
     */
    public function has_used_self_certified_extensions(string $username, int $n): void {
        steps::instance()->has_used_self_certified_extensions($username, $n);
    }

    /**
     * The items of a row's Mitigate menu, in order: live (a link, with its count as the note), info (a line
     * starting with the item text, which the next link reads out), html (block_rhb's own) or divider ("---").
     *
     * @Then /^the Mitigate menu of "(?P<name>[^"]*)" should list:$/
     * @param string $name
     * @param TableNode $table item, state, note
     */
    public function the_mitigate_menu_should_list(string $name, TableNode $table): void {
        $menu = $this->mitigate_menu($name);
        $items = $menu->findAll('css', '.ma-mitigate-item, .ma-mitigate-divider');
        $rows = $table->getHash();
        if (count($items) !== count($rows)) {
            throw new ExpectationException("The Mitigate menu of '$name' has " . count($items) . ' items: ' .
                $menu->getText(), $this->getSession());
        }
        $info = null;
        foreach ($rows as $i => $row) {
            $item = $items[$i];
            $note = trim($row['note']);
            $left = $item->find('css', '.ma-mitigate-left');
            $label = trim(preg_replace('/\s+/', ' ', $item->getText()));
            if ($left) {
                $label = trim(substr($label, 0, -strlen(trim($left->getText()))));
            }
            if ($row['state'] === 'divider') {
                $ok = $item->hasClass('dropdown-divider') && $row['item'] === '---';
            } else if ($row['state'] === 'info') {
                $ok = $item->hasClass('ma-mitigate-note') && str_starts_with($label, $row['item'])
                    && !$item->find('css', 'a, button');
                $info = $item->getAttribute('id');
            } else {
                $ok = $item->getAttribute('aria-disabled') !== 'true' && $label === $row['item']
                    && ($note === '' ? !$left : $left && trim($left->getText()) === $note);
                if ($ok && $info !== null && $item->getAttribute('href') !== null) {
                    $ok = $item->getAttribute('aria-describedby') === $info;
                    $info = null;
                }
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
            throw new failed("block_rhb's delete link was " . ($not ? '' : 'not ') . 'followed');
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
        steps::instance()->the_rhb_stand_in_treats_as_coursework($name);
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
        steps::instance()->every_index_run_has_a_limit($n);
    }

    /**
     * Ad hoc tasks never run: the steps that catch the index up leave them queued.
     *
     * @Given /^ad hoc tasks don't run$/
     */
    public function ad_hoc_tasks_dont_run(): void {
        steps::instance()->ad_hoc_tasks_dont_run();
    }

    /**
     * Rebuilds the index as the Index page's button does.
     *
     * @Given /^the My assessments index is rebuilt$/
     */
    public function the_index_is_rebuilt(): void {
        steps::instance()->the_index_is_rebuilt();
    }

    /**
     * One nightly check run, whatever the hour.
     *
     * @When /^the nightly check runs$/
     */
    public function the_nightly_check_runs(): void {
        steps::instance()->the_nightly_check_runs();
    }

    /**
     * The hourly task runs at a time of day, server time, on the same day as its last run in this scenario
     * when that is later, or else the next day.
     *
     * @When /^the hourly index task runs at "(?P<time>\d\d:\d\d)"$/
     * @param string $time
     */
    public function the_hourly_task_runs_at(string $time): void {
        steps::instance()->the_hourly_task_runs_at($time);
    }

    /**
     * The nightly check died part-way: it saved its progress up to a course, then stopped without ending.
     *
     * @Given /^the nightly check died after "(?P<course>[^"]*)"$/
     * @param string $shortname
     */
    public function the_nightly_check_died_after(string $shortname): void {
        steps::instance()->the_nightly_check_died_after($shortname);
    }

    /**
     * The courses the last index run did.
     *
     * @Then /^the (?:last index run|first fill) should have done (?P<courses>"[^"]*"(?:(?:, | and )"[^"]*")*) only$/
     * @param string $courses
     */
    public function the_last_run_should_have_done(string $courses): void {
        steps::instance()->the_last_run_should_have_done($courses);
    }

    /**
     * The courses the nightly check has done in this scenario so far.
     *
     * @Then /^the nightly check should have done (?P<courses>no courses|"[^"]*"(?:(?:, | and )"[^"]*")*) so far$/
     * @param string $courses
     */
    public function the_nightly_check_should_have_done(string $courses): void {
        steps::instance()->the_nightly_check_should_have_done($courses);
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
        steps::instance()->i_run_the_command_line_rebuild();
    }

    /**
     * Every course with a tagged assessment is in the index.
     *
     * @Then /^it should finish with every course done$/
     */
    public function it_should_finish_with_every_course_done(): void {
        steps::instance()->it_should_finish_with_every_course_done();
    }

    /**
     * Whether a first fill (backfill ad hoc task) is queued, and "Preparing" pending.
     *
     * @Then /^no first fill should be queued$/
     */
    public function no_first_fill_should_be_queued(): void {
        steps::instance()->no_first_fill_should_be_queued();
    }

    // Colour modes (Moodle 5.3).

    /**
     * Turns on Boost's colour modes, which Moodle 5.3 added. Earlier versions have none, so the scenario is skipped.
     *
     * @Given /^the site has colour modes turned on$/
     */
    public function the_site_has_colour_modes_turned_on(): void {
        self::skipping(fn() => steps::instance()->the_site_has_colour_modes_turned_on());
    }

    /**
     * Turns on colour modes where the site has them, so one scenario outline covers 4.5, 5.2 and 5.3.
     *
     * @Given /^the site has colour modes turned on if it can$/
     */
    public function the_site_has_colour_modes_turned_on_if_it_can(): void {
        steps::instance()->the_site_has_colour_modes_turned_on_if_it_can();
    }

    /**
     * Opens every section's hidden rows, where a section has more than it shows.
     *
     * @When /^I press "Show more" in the "My assessments" block if there is one$/
     */
    public function i_press_show_more_if_there_is_one(): void {
        foreach ($this->getSession()->getPage()->findAll('css', self::BLOCK . ' .ma-more[aria-expanded="false"]') as $more) {
            if ($more->isVisible()) {
                $more->click();
            }
        }
        $this->wait_for_pending_js();
    }

    /**
     * Sets a user's colour mode, the way the navbar menu stores it. Light is every version's only mode before 5.3.
     *
     * @Given /^"(?P<user>[^"]*)" uses the (?P<mode>light|dark) colour mode$/
     * @param string $username
     * @param string $mode
     */
    public function uses_the_colour_mode(string $username, string $mode): void {
        self::skipping(fn() => steps::instance()->uses_the_colour_mode($username, $mode));
    }

    /**
     * Picks a colour mode from the navbar menu, which switches the page without reloading it.
     *
     * @When /^I choose the (?P<mode>light|dark) colour mode from the navbar$/
     * @param string $mode
     */
    public function i_choose_the_colour_mode(string $mode): void {
        $this->find('css', '[data-region="colourmode-menu"] [data-bs-toggle="dropdown"]')->click();
        $this->find('css', '[data-action="set-colourmode"][data-colourmode="' . $mode . '"]')->click();
        $this->wait_for_pending_js();
    }

    /**
     * The colour mode the page is drawn in: Boost's data-bs-theme attribute, missing before 5.3.
     *
     * @Then /^the page should be in (?P<mode>light|dark) colour mode$/
     * @param string $mode
     */
    public function the_page_should_be_in_colour_mode(string $mode): void {
        $actual = $this->evaluate_script('return document.documentElement.getAttribute("data-bs-theme") || "light"');
        if ($actual !== $mode) {
            throw new ExpectationException("The page is in $actual colour mode", $this->getSession());
        }
    }

    /**
     * Every visible text, icon, progress bar and control outline in the block meets the block's contrast targets.
     *
     * @Then /^the My assessments block should meet its contrast targets$/
     */
    public function the_block_should_meet_its_contrast_targets(): void {
        $this->assert_contrast($this->contrast('all', self::BLOCK), 'At rest');
    }

    /**
     * The same, with the pointer over each kind of control in turn: one of each tab, button, flag, link and select.
     *
     * @Then /^every kind of control in the My assessments block should meet its contrast targets when hovered$/
     */
    public function every_control_should_meet_its_contrast_targets_when_hovered(): void {
        $failures = [];
        $kinds = [];
        $selector = self::BLOCK . ' :is(.nav-link, .btn, .ma-flag, .ma-row-iconbtn, .ma-untagged-summary, a, select,' .
            ' .dropdown-item)';
        foreach ($this->getSession()->getPage()->findAll('css', $selector) as $control) {
            $kind = $control->getTagName() . ' ' . preg_replace('/\b(active|show|ma-row-\S*--\S+)\b/', '',
                (string) $control->getAttribute('class'));
            if (isset($kinds[$kind]) || !$control->isVisible()) {
                continue;
            }
            $kinds[$kind] = true;
            $id = 'ma-contrast-' . count($kinds);
            $this->evaluate_script('document.evaluate(' . json_encode($control->getXpath()) . ', document, null, ' .
                'XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue.setAttribute("data-contrast", "' . $id . '")');
            $control->mouseOver();
            foreach ($this->contrast('all', '[data-contrast="' . $id . '"]') as $failure) {
                $failures[] = "hovered $kind: $failure";
            }
        }
        $this->evaluate_script('document.querySelectorAll("[data-contrast]").forEach(e => e.removeAttribute("data-contrast"))');
        $this->getSession()->getPage()->find('css', 'body')->mouseOver();
        $this->assert_contrast($failures, 'Hovered');
    }

    /**
     * Tabbing through the block: every control's focus indicator reaches 3:1 and Lc 45 against what's behind it.
     *
     * @Then /^keyboard focus should be visible on every control in the My assessments block$/
     */
    public function keyboard_focus_should_be_visible_on_every_control(): void {
        $inblock = 'return !!document.activeElement.closest(' . json_encode(self::BLOCK) . ')';
        $this->evaluate_script('document.activeElement.blur(); document.body.focus()');
        for ($i = 0; $i < 100 && !$this->evaluate_script($inblock); $i++) {
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
        }
        $failures = [];
        for ($stops = 0; $stops < 300 && $this->evaluate_script($inblock); $stops++) {
            $failures = array_merge($failures, $this->contrast('focus', self::BLOCK));
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
        }
        if ($stops < 5) {
            throw new ExpectationException("Tab reached only $stops controls in the block", $this->getSession());
        }
        $this->assert_contrast($failures, 'Focused');
    }

    /**
     * Text drawn in the block's body or secondary colour reaches the given APCA level and WCAG ratio.
     *
     * @Then /^the (?P<which>body|secondary) text in the My assessments block should reach APCA Lc (?P<lc>\d+) and (?P<ratio>[\d.]+):1$/
     * @param string $which
     * @param string $lc
     * @param string $ratio
     */
    public function the_text_should_reach(string $which, string $lc, string $ratio): void {
        $this->assert_contrast($this->contrast("text:$which:$lc:$ratio", self::BLOCK), ucfirst($which) . ' text');
    }

    /**
     * Body text stands far enough above secondary text, on the block's surface, to read as a separate level.
     *
     * @Then /^the body text in the My assessments block should be at least (?P<lc>\d+) Lc brighter than its secondary text$/
     * @param string $lc
     */
    public function the_body_text_should_be_brighter_than_secondary(string $lc): void {
        $this->assert_contrast($this->contrast("gap:$lc", self::BLOCK), 'Text levels');
    }

    /**
     * Dark mode's link underlines: 1px, dimmer than the link, and still Lc 45 and 3:1 against what's behind them.
     *
     * @Then /^every link underline in the My assessments block should be thin, dimmer than its link and meet its contrast target$/
     */
    public function every_link_underline_should_be_toned(): void {
        $this->assert_contrast($this->contrast('underlines', self::BLOCK), 'Underlines');
    }

    /**
     * Every link's underline is the link's own colour, as in light mode.
     *
     * @Then /^link underlines in the My assessments block should be the same colour as their links$/
     */
    public function link_underlines_should_be_full_colour(): void {
        $this->assert_contrast($this->contrast('underlines-full', self::BLOCK), 'Underlines');
    }

    /**
     * Points at a link in the block.
     *
     * @When /^I hover over the "(?P<name>[^"]*)" link in the My assessments block$/
     * @param string $name A link's text, or "course of" and an assessment for that row's course link
     */
    public function i_hover_over_the_link(string $name): void {
        $this->block_link($name)->mouseOver();
    }

    /**
     * The link's underline is the link's own colour.
     *
     * @Then /^the underline of the "(?P<name>[^"]*)" link should be the same colour as the link$/
     * @param string $name
     */
    public function the_underline_should_be_the_link_colour(string $name): void {
        $link = $this->block_link($name);
        $this->evaluate_script('document.evaluate(' . json_encode($link->getXpath()) . ', document, null, ' .
            'XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue.setAttribute("data-contrast", "underline")');
        $failures = $this->contrast('underlines-full', '[data-contrast="underline"]');
        $this->evaluate_script('document.querySelector("[data-contrast]").removeAttribute("data-contrast")');
        $this->assert_contrast($failures, 'Hovered');
    }

    /**
     * The link has no underline.
     *
     * @Then /^the "(?P<name>[^"]*)" link in the My assessments block should have no underline$/
     * @param string $name
     */
    public function the_link_should_have_no_underline(string $name): void {
        $link = $this->block_link($name);
        $this->evaluate_script('document.evaluate(' . json_encode($link->getXpath()) . ', document, null, ' .
            'XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue.setAttribute("data-contrast", "underline")');
        $failures = $this->contrast('no-underline', '[data-contrast="underline"]');
        $this->evaluate_script('document.querySelector("[data-contrast]").removeAttribute("data-contrast")');
        $this->assert_contrast($failures, 'Underline');
    }

    /**
     * No section header has a rule under it: its fill sets it off from the rows.
     *
     * @Then /^the section headers in the My assessments block should have no line under them$/
     */
    public function the_section_headers_should_have_no_line(): void {
        $this->assert_contrast($this->contrast('header-lines', self::BLOCK), 'Section headers');
    }

    /**
     * The first visible link in the block with this text, or with "course of" and an assessment, that row's course link.
     *
     * @param string $name
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function block_link(string $name): \Behat\Mink\Element\NodeElement {
        if (str_starts_with($name, 'course of ')) {
            return $this->row(substr($name, strlen('course of ')))->find('css', '.ma-row-course') ??
                throw new ExpectationException("No course link in the row for '$name'", $this->getSession());
        }
        foreach ($this->getSession()->getPage()->findAll('css', self::BLOCK . ' a') as $link) {
            if (trim($link->getText()) === $name && $link->isVisible()) {
                return $link;
            }
        }
        throw new ExpectationException("No \"$name\" link in the My assessments block", $this->getSession());
    }

    /**
     * Runs tests/fixtures/contrast.js in the page.
     *
     * @param string $check "all", "focus" or one of the checks tests/fixtures/contrast.js lists
     * @param string $selector What to check
     * @return string[] One line per failure
     */
    protected function contrast(string $check, string $selector): array {
        $js = file_get_contents(__DIR__ . '/../fixtures/contrast.js');
        $js = trim(preg_replace('~^\s*//.*$~m', '', $js));
        return $this->evaluate_script('return (' . $js . ')(' . json_encode($check) . ', ' . json_encode($selector) . ')');
    }

    /**
     * Fails with every contrast failure, one per line.
     *
     * @param string[] $failures
     * @param string $state
     */
    protected function assert_contrast(array $failures, string $state): void {
        if ($failures) {
            $failures = array_values(array_unique($failures));
            throw new ExpectationException("$state, " . count($failures) . " parts of the block miss their contrast targets:\n" .
                implode("\n", $failures), $this->getSession());
        }
    }
}
