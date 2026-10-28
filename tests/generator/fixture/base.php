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

/**
 * What every fixture shares: creating the module from the options phrase, tagging it, dating enrolments before
 * its deadline, queueing the re-index the plugin's event would have queued, and "not supported" for everything a
 * type cannot do.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base implements fixture {
    /**
     * The module's field for its due date.
     *
     * @return string
     */
    abstract protected function due_field(): string;

    /**
     * The module's field for when submissions open, or null if it has none.
     *
     * @return string|null
     */
    protected function opens_field(): ?string {
        return null;
    }

    /**
     * The type's own part of the record the module generator gets: its due field and whatever the options ask for.
     *
     * @param array $record course, name, and intro when the options set it.
     * @param int|null $due
     * @param string $opts
     * @return array
     */
    protected function record(array $record, ?int $due, string $opts): array {
        $record[$this->due_field()] = (int)$due;
        return $record;
    }

    /**
     * Anything to do once the module exists (a quiz's question, its review options).
     *
     * @param \stdClass $instance
     * @param string $opts
     */
    protected function after_create(\stdClass $instance, string $opts): void {
    }

    #[\Override]
    public function create(\stdClass $course, array $tags, string $name, ?int $due, string $opts = ''): \stdClass {
        global $DB;
        $record = ['course' => $course->id, 'name' => $name];
        if (str_contains($opts, 'no description')) {
            $record['intro'] = '';
        } else if (preg_match('/description "([^"]*)"/', $opts, $m)) {
            $record['intro'] = $m[1];
        }
        $record = $this->record($record, $due, $opts);
        if (preg_match('/opens "([^"]*)"/', $opts, $m)) {
            $field = $this->opens_field();
            if ($field === null) {
                throw new \coding_exception("{$this->modname()} has no opening date");
            }
            $record[$field] = phrases::time($m[1]);
        }
        if (str_contains($opts, 'no grade')) {
            // A grade of 0 is grade type None.
            $record['grade'] = 0;
        }
        $instance = self::gen()->create_module($this->modname(), $record);
        if (isset($record['intro'])) {
            // Module generators fill in an empty intro, so set it afterwards.
            $DB->set_field($this->modname(), 'intro', $record['intro'], ['id' => $instance->id]);
        }
        if (str_contains($opts, 'brief file')) {
            get_file_storage()->create_file_from_string(['contextid' => \context_module::instance($instance->cmid)->id,
                'component' => 'mod_' . $this->modname(), 'filearea' => 'intro', 'itemid' => 0, 'filepath' => '/',
                'filename' => 'brief.pdf'], 'Brief');
        }
        $this->after_create($instance, $opts);
        if ($due) {
            self::joined_before((int)$course->id, $due);
        }
        if (preg_match('/tagged "([^"]*)"/', $opts, $m)) {
            $tags[] = $m[1];
        }
        \core_tag_tag::set_item_tags(
            'core',
            'course_modules',
            $instance->cmid,
            \context_module::instance($instance->cmid),
            $tags
        );
        return $instance;
    }

    #[\Override]
    public function set_due(\cm_info $cm, int $due): void {
        global $DB;
        $DB->set_field($this->modname(), $this->due_field(), $due, ['id' => $cm->instance]);
    }

    #[\Override]
    public function switch_due_off(\cm_info $cm): void {
        $this->set_due($cm, 0);
        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm(get_fast_modinfo($cm->course)->get_cm($cm->id))->trigger();
    }

    #[\Override]
    public function submit(\cm_info $cm, int $userid, ?int $time = null): void {
        $this->unsupported('Submitting');
    }

    #[\Override]
    public function submit_for_group(\cm_info $cm, int $userid, int $groupid): void {
        $this->unsupported('Submitting for a group');
    }

    #[\Override]
    public function save_draft(\cm_info $cm, int $userid): void {
        $this->unsupported('Saving a draft of');
    }

    #[\Override]
    public function submitters(\cm_info $cm): array {
        $this->unsupported('Listing the submitters of');
    }

    #[\Override]
    public function allocate(\cm_info $cm, int $userid, array $markerids): void {
        $this->unsupported('Allocating');
    }

    #[\Override]
    public function moderate(\cm_info $cm, int $markerid): void {
        $this->unsupported('Moderating');
    }

    #[\Override]
    public function mark(
        \cm_info $cm,
        int $userid,
        int $markerid,
        float $grade = 60,
        bool $final = false,
        string $stage = 'assessor_1'
    ): void {
        $this->unsupported('Marking');
    }

    #[\Override]
    public function all_markers_mark(\cm_info $cm, int $userid): void {
        $this->unsupported('Marking by every marker of');
    }

    #[\Override]
    public function blank_feedback(\cm_info $cm, int $userid, int $markerid): void {
        $this->unsupported('Blank feedback on');
    }

    #[\Override]
    public function release(\cm_info $cm, int $userid): void {
        $this->unsupported('Releasing a grade of');
    }

    #[\Override]
    public function reopen(\cm_info $cm, int $userid): void {
        $this->unsupported('Reopening');
    }

    #[\Override]
    public function revert_to_draft(\cm_info $cm, int $userid): void {
        $this->unsupported('Reverting to draft');
    }

    #[\Override]
    public function lock(\cm_info $cm, int $userid, bool $locked = true, ?int $teacherid = null): void {
        $this->unsupported('Locking');
    }

    #[\Override]
    public function extend(\cm_info $cm, int $userid, int $until, bool $selfcert = false): void {
        $this->unsupported('Extensions on');
    }

    #[\Override]
    public function remove_extension(\cm_info $cm, int $userid): void {
        $this->unsupported('Removing extensions on');
    }

    #[\Override]
    public function event_fires(\cm_info $cm, int $userid, string $event): void {
        $this->unsupported("The $event event of");
    }

    #[\Override]
    public function override(\cm_info $cm, int $userid, int $due): void {
        $this->unsupported('Overriding the deadline of');
    }

    #[\Override]
    public function attempts(\cm_info $cm, int $userid, int $count): void {
        $this->unsupported('Attempts at');
    }

    #[\Override]
    public function abandon_attempt(\cm_info $cm, int $userid): void {
        $this->unsupported('Abandoning an attempt at');
    }

    #[\Override]
    public function grade_attempt(\cm_info $cm, int $userid, string $which, ?int $markerid = null): void {
        $this->unsupported('Grading an attempt at');
    }

    #[\Override]
    public function attempt_ungraded(\cm_info $cm, int $userid, string $which): bool {
        $this->unsupported('Reading the attempts at');
    }

    #[\Override]
    public function hide_marks(\cm_info $cm): void {
        $this->unsupported('Hiding the marks of');
    }

    #[\Override]
    public function flag_plagiarism(\cm_info $cm, int $userid, string $status = '0'): void {
        $this->unsupported('Flagging plagiarism on');
    }

    #[\Override]
    public function team_grouping(\cm_info $cm): int {
        $this->unsupported('Teams of');
    }

    #[\Override]
    public function used_self_certs(int $userid, int $count): void {
        $this->unsupported('Self-certified extensions on');
    }

    #[\Override]
    public function time_fields(): array {
        return [];
    }

    /**
     * Says the type cannot do something.
     *
     * @param string $what
     * @return never
     */
    protected function unsupported(string $what): void {
        throw new \coding_exception("$what {$this->modname()} is not supported by its fixture");
    }

    /**
     * Queues the re-index that the plugin's own event would have queued.
     *
     * @param \cm_info $cm
     */
    protected static function queue(\cm_info $cm): void {
        indexer::queue_cm((int)$cm->id);
    }

    /**
     * The core data generator.
     *
     * @return \testing_data_generator
     */
    protected static function gen(): \testing_data_generator {
        return \testing_util::get_data_generator();
    }

    /**
     * A plugin's generator.
     *
     * @param string $component
     * @return \component_generator_base
     */
    protected static function plugin_gen(string $component): \component_generator_base {
        return self::gen()->get_plugin_generator($component);
    }

    /**
     * Runs something as another user, as the plugin's own pages would.
     *
     * @param int $userid
     * @param callable $fn
     */
    protected static function as_user(int $userid, callable $fn): void {
        global $USER;
        $saved = $USER;
        \core\session\manager::set_user(\core_user::get_user($userid));
        try {
            $fn();
        } finally {
            \core\session\manager::set_user($saved);
        }
    }

    /**
     * The activity's grade item.
     *
     * @param \cm_info $cm
     * @return \grade_item|false
     */
    protected static function grade_item(\cm_info $cm) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        return \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course]);
    }

    /**
     * Dates the enrolments already in a course before a deadline: students on the module when an assessment is
     * set were there before its deadline. Students enrolled later in a test are late joiners (EC10).
     *
     * @param int $courseid
     * @param int $due
     */
    public static function joined_before(int $courseid, int $due): void {
        global $DB;
        $before = $due - DAYSECS;
        $DB->execute(
            "UPDATE {user_enrolments}
                SET timecreated = :t1, timestart = CASE WHEN timestart > :t2 THEN :t3 ELSE timestart END
              WHERE timecreated > :t4 AND enrolid IN (SELECT id FROM {enrol} WHERE courseid = :courseid)",
            ['t1' => $before, 't2' => $before, 't3' => $before, 't4' => $before, 'courseid' => $courseid]
        );
    }
}
