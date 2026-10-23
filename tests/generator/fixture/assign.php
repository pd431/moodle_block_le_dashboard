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

/**
 * Writes assignments the way their users do: submissions, drafts, marker allocation (assign_user_flags before
 * Moodle 5.0, assign_allocated_marker from 5.0), marks under marking workflow and with several markers,
 * release, reopening, locking and extensions.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assign extends base {
    #[\Override]
    public function modname(): string {
        return 'assign';
    }

    #[\Override]
    protected function due_field(): string {
        return 'duedate';
    }

    #[\Override]
    protected function opens_field(): ?string {
        return 'allowsubmissionsfromdate';
    }

    #[\Override]
    protected function record(array $record, ?int $due, string $opts): array {
        global $DB;
        $allocation = str_contains($opts, 'marker allocation');
        // EC5: "with no online submission" means the assignment only records a mark.
        $record += ['duedate' => (int)$due, 'submissiondrafts' => str_contains($opts, 'required Submit') ? 1 : 0,
            'assignsubmission_onlinetext_enabled' => str_contains($opts, 'no online submission') ? 0 : 1,
            'assignsubmission_file_enabled' => 0,
            'gradingduedate' => preg_match('/marking deadline "([^"]*)"/', $opts, $m) ? phrases::time($m[1]) : 0,
            'markingworkflow' => $allocation || str_contains($opts, 'marking workflow') ? 1 : 0,
            'markingallocation' => $allocation ? 1 : 0];
        if (str_contains($opts, 'team submission')) {
            $record += ['teamsubmission' => 1, 'requireallteammemberssubmit' => 0, 'preventsubmissionnotingroup' => 0];
            // Teams from one grouping, so a student's other groups (an extension group) aren't teams.
            if (preg_match('/team submission in grouping "([^"]*)"/', $opts, $m)) {
                $grouping = $DB->get_record('groupings', ['courseid' => $record['course'], 'name' => $m[1]])
                    ?: self::gen()->create_grouping(['courseid' => $record['course'], 'name' => $m[1]]);
                $record['teamsubmissiongroupingid'] = $grouping->id;
            }
        }
        if (str_contains($opts, 'reopened attempts')) {
            $record += ['attemptreopenmethod' => 'manual', 'maxattempts' => -1];
        }
        if (preg_match('/cut-off "([^"]*)"/', $opts, $m)) {
            $record['cutoffdate'] = phrases::time($m[1]);
        }
        if (preg_match('/(\d+) markers/', $opts, $m)) {
            if (!$DB->get_manager()->field_exists('assign', 'markercount')) {
                throw new unavailable('Several assign markers need Moodle 5.0');
            }
            $record += ['markercount' => (int)$m[1],
                'multimarkmethod' => str_contains($opts, 'manual agreement') ? 'manual' : 'average'];
        }
        return $record;
    }

    #[\Override]
    public function switch_due_off(\cm_info $cm): void {
        $this->set_due($cm, 0);
        self::instance($cm)->update_calendar($cm->id);
        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm(get_fast_modinfo($cm->course)->get_cm($cm->id))->trigger();
    }

    #[\Override]
    public function submit(\cm_info $cm, int $userid, ?int $time = null): void {
        global $DB;
        self::plugin_gen('mod_assign')->create_submission(['userid' => $userid, 'cmid' => $cm->id, 'onlinetext' => 'My work']);
        // Where students must click Submit, the generator leaves a draft: submit it, as the student would.
        $DB->set_field(
            'assign_submission',
            'status',
            'submitted',
            ['assignment' => $cm->instance, 'userid' => $userid, 'latest' => 1]
        );
        if ($time !== null) {
            $DB->set_field('assign_submission', 'timemodified', $time, ['assignment' => $cm->instance, 'userid' => $userid]);
        }
        self::queue($cm);
    }

    #[\Override]
    public function submit_for_group(\cm_info $cm, int $userid, int $groupid): void {
        global $DB;
        // A team assignment submits for the member's group (CI caught this step using peerwork's generator).
        $this->submit($cm, $userid);
        if (
            !$DB->record_exists('assign_submission', ['assignment' => $cm->instance, 'groupid' => $groupid,
                'status' => 'submitted'])
        ) {
            throw new \coding_exception("User $userid did not submit '{$cm->name}' for group $groupid");
        }
    }

    #[\Override]
    public function save_draft(\cm_info $cm, int $userid): void {
        // Under required Submit the generator leaves a draft.
        self::plugin_gen('mod_assign')->create_submission(['userid' => $userid, 'cmid' => $cm->id, 'onlinetext' => 'Draft']);
        self::queue($cm);
    }

    #[\Override]
    public function submitters(\cm_info $cm): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'assign_submission',
            'userid',
            "assignment = :id AND status = 'submitted' AND latest = 1",
            ['id' => $cm->instance]
        ));
    }

    #[\Override]
    public function allocate(\cm_info $cm, int $userid, array $markerids): void {
        self::allocate_marker($cm->id, $userid, $markerids);
        self::queue($cm);
    }

    /**
     * Allocates a student's marking to markers ([] removes the allocation), optionally setting the workflow state.
     *
     * @param int $cmid
     * @param int $userid
     * @param int[] $markerids Only the first fits before Moodle 5.0.
     * @param string|null $workflowstate
     */
    public static function allocate_marker(int $cmid, int $userid, array $markerids, ?string $workflowstate = null): void {
        global $DB;
        $assign = self::instance(\context_module::instance($cmid));
        $flags = $assign->get_user_flags($userid, true);
        if ($workflowstate !== null) {
            $flags->workflowstate = $workflowstate;
        }
        if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
            $params = ['assignment' => $assign->get_instance()->id, 'student' => $userid];
            $DB->delete_records('assign_allocated_marker', $params);
            foreach ($markerids as $markerid) {
                $DB->insert_record('assign_allocated_marker', $params + ['marker' => $markerid]);
            }
        } else {
            if (count($markerids) > 1) {
                throw new unavailable('Several assign markers need Moodle 5.0');
            }
            $flags->allocatedmarker = $markerids[0] ?? 0;
        }
        $assign->update_user_flags($flags);
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
        $assign = self::instance($cm);
        $record = $assign->get_user_grade($userid, true);
        if ((int)($assign->get_instance()->markercount ?? 1) > 1) {
            // Moodle 5.0+ with several markers: a marker's own mark, not the final grade.
            $record->grader = $markerid;
            $assign->update_mark($record, $grade);
            self::queue($cm);
            return;
        }
        $record->grade = $grade;
        $record->grader = $markerid;
        $assign->update_grade($record);
        if ($assign->get_instance()->markingworkflow) {
            // Under marking workflow, a marker's work is done when it goes to review.
            self::set_flags($assign, $userid, ['workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW]);
        }
        self::queue($cm);
    }

    #[\Override]
    public function all_markers_mark(\cm_info $cm, int $userid): void {
        global $DB;
        // Moodle 5.0+: each allocated marker gives their own mark.
        $markers = $DB->get_fieldset_select(
            'assign_allocated_marker',
            'marker',
            'assignment = :a AND student = :s',
            ['a' => $cm->instance, 's' => $userid]
        );
        foreach ($markers as $markerid) {
            $this->mark($cm, $userid, (int)$markerid);
        }
    }

    #[\Override]
    public function release(\cm_info $cm, int $userid): void {
        global $DB;
        $assign = self::instance($cm);
        if ($assign->get_instance()->markingworkflow) {
            self::set_flags($assign, $userid, ['workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_RELEASED]);
            // Pushes the grade to the gradebook, now that its state allows it.
            $assign->update_grade($assign->get_user_grade($userid, false));
        }
        $where = 'assignment = :id AND userid = :userid';
        $params = ['id' => $cm->instance, 'userid' => $userid];
        $DB->execute("UPDATE {assign_submission} SET timemodified = timemodified - 120 WHERE $where", $params);
        $DB->execute("UPDATE {assign_grades} SET timemodified = timemodified - 60 WHERE $where", $params);
        $DB->execute("UPDATE {grade_grades} SET timemodified = timemodified - 60, timecreated = timecreated - 60
                       WHERE itemid = :itemid AND userid = :userid", ['itemid' => self::grade_item($cm)->id, 'userid' => $userid]);
        self::queue($cm);
    }

    #[\Override]
    public function reopen(\cm_info $cm, int $userid): void {
        $assign = self::instance($cm);
        // Core's add_attempt() is what "Allow another attempt" calls; it isn't public.
        $method = new \ReflectionMethod($assign, 'add_attempt');
        $method->setAccessible(true);
        if (!$method->invoke($assign, $userid)) {
            throw new \coding_exception("Could not reopen the submission of user $userid for '{$cm->name}'");
        }
        self::queue($cm);
    }

    #[\Override]
    public function revert_to_draft(\cm_info $cm, int $userid): void {
        self::instance($cm)->revert_to_draft($userid);
    }

    #[\Override]
    public function lock(\cm_info $cm, int $userid, bool $locked = true, ?int $teacherid = null): void {
        $assign = self::instance($cm);
        if ($teacherid === null) {
            self::set_flags($assign, $userid, ['locked' => $locked ? 1 : 0]);
            self::queue($cm);
            return;
        }
        // Through the assignment as the teacher, firing Moodle's events; nothing is queued, so the dashboard
        // shows only what the block's observer did.
        self::as_user($teacherid, function () use ($assign, $userid, $locked) {
            $locked ? $assign->lock_submission($userid) : $assign->unlock_submission($userid);
        });
    }

    #[\Override]
    public function extend(\cm_info $cm, int $userid, int $until, bool $selfcert = false): void {
        if ($selfcert) {
            $this->unsupported('Self-certified extensions on');
        }
        self::set_flags(self::instance($cm), $userid, ['extensionduedate' => $until]);
        self::queue($cm);
    }

    #[\Override]
    public function team_grouping(\cm_info $cm): int {
        global $DB;
        return (int)$DB->get_field('assign', 'teamsubmissiongroupingid', ['id' => $cm->instance], MUST_EXIST);
    }

    #[\Override]
    public function time_fields(): array {
        return [
            'assign' => ['duedate', 'cutoffdate', 'gradingduedate', 'allowsubmissionsfromdate'],
            'assign_overrides' => ['duedate', 'cutoffdate'],
            'assign_user_flags' => ['extensionduedate'],
            'assign_submission' => ['timecreated', 'timemodified'],
            'assign_grades' => ['timecreated', 'timemodified'],
            'assign_mark' => ['timecreated', 'timemodified'],
        ];
    }

    /**
     * The assignment object.
     *
     * @param \cm_info|\context_module $cm
     * @return \assign
     */
    private static function instance($cm): \assign {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        return $cm instanceof \cm_info ? new \assign($cm->context, $cm, null) : new \assign($cm, null, null);
    }

    /**
     * Sets a student's user flags.
     *
     * @param \assign $assign
     * @param int $userid
     * @param array $fields
     */
    private static function set_flags(\assign $assign, int $userid, array $fields): void {
        $flags = $assign->get_user_flags($userid, true);
        foreach ($fields as $field => $value) {
            $flags->$field = $value;
        }
        $assign->update_user_flags($flags);
    }
}
