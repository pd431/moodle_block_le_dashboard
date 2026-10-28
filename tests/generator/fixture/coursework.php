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
 * Writes courseworks (mod_coursework, where installed) the way their users do: finalised and draft submissions,
 * assessor and moderator allocation, feedback at each stage, the agreed grade and its publication,
 * self-certified extensions, plagiarism flags.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class coursework extends base {
    /** @var int The mitigation remove_extension() took away last. */
    private static int $removed = 0;

    #[\Override]
    public function modname(): string {
        return 'coursework';
    }

    #[\Override]
    protected function due_field(): string {
        return 'deadline';
    }

    #[\Override]
    protected function opens_field(): ?string {
        return 'startdate';
    }

    #[\Override]
    protected function record(array $record, ?int $due, string $opts): array {
        $markingdue = preg_match('/marking deadline "([^"]*)"/', $opts, $m) ? phrases::time($m[1]) : 0;
        $record += ['deadline' => (int)$due,
            'numberofmarkers' => preg_match('/(\d+) markers/', $opts, $m) ? (int)$m[1] : 1,
            'allocationenabled' => str_contains($opts, 'marker allocation') ? 1 : 0,
            'moderationenabled' => str_contains($opts, 'moderation') ? 1 : 0,
            'markingdeadlineenabled' => $markingdue ? 1 : 0, 'initialmarkingdeadline' => $markingdue];
        return $record;
    }

    /**
     * The submission types: "with marks entry only", "with file submission", "with marks entry and file submission",
     * or "with no submission types recorded"; otherwise whatever coursework's generator gives.
     *
     * @param \stdClass $instance
     * @param string $opts
     */
    #[\Override]
    protected function after_create(\stdClass $instance, string $opts): void {
        global $DB;
        $types = array_keys(array_filter(['marksentry' => str_contains($opts, 'marks entry'),
            'file' => str_contains($opts, 'file submission')]));
        if (!$types && !str_contains($opts, 'no submission types recorded')) {
            return;
        }
        $DB->delete_records('coursework_sub_plugin_cwk', ['courseworkid' => $instance->id]);
        foreach ($types as $type) {
            $DB->insert_record('coursework_sub_plugin_cwk', self::filled('coursework_sub_plugin_cwk', [
                'courseworkid' => $instance->id,
                'subpluginid' => $DB->get_field('coursework_sub_plugin', 'id', ['name' => $type], MUST_EXIST)]));
        }
    }

    #[\Override]
    public function submit(\cm_info $cm, int $userid, ?int $time = null): void {
        global $DB;
        self::plugin_gen('mod_coursework')->create_submission(['courseworkid' => $cm->instance,
            'allocatableid' => $userid, 'allocatabletype' => 'user']);
        $params = self::submission_params($cm, $userid);
        $DB->set_field('coursework_submissions', 'finalised', 1, $params);
        $DB->set_field('coursework_submissions', 'timesubmitted', $time ?? time(), $params);
        self::queue($cm);
    }

    #[\Override]
    public function save_draft(\cm_info $cm, int $userid): void {
        self::plugin_gen('mod_coursework')->create_submission(['courseworkid' => $cm->instance,
            'allocatableid' => $userid, 'allocatabletype' => 'user', 'finalised' => 0]);
        self::queue($cm);
    }

    #[\Override]
    public function submitters(\cm_info $cm): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'coursework_submissions',
            'allocatableid',
            "courseworkid = :id AND allocatabletype = 'user'",
            ['id' => $cm->instance]
        ));
    }

    #[\Override]
    public function allocate(\cm_info $cm, int $userid, array $markerids): void {
        foreach ($markerids as $markerid) {
            self::allocation($cm, $userid, $markerid, 'assessor_1');
        }
        self::queue($cm);
    }

    #[\Override]
    public function moderate(\cm_info $cm, int $markerid): void {
        foreach ($this->submitters($cm) as $userid) {
            self::allocation($cm, $userid, $markerid, 'moderator');
        }
        self::queue($cm);
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
        global $DB;
        if (!$DB->record_exists('coursework_submissions', self::submission_params($cm, $userid))) {
            // Marks entry creates the submission row as the mark goes in.
            $this->save_draft($cm, $userid);
        }
        self::plugin_gen('mod_coursework')->create_feedback(['courseworkid' => $cm->instance, 'allocatableid' => $userid,
            'assessorid' => $markerid, 'stage_identifier' => $stage, 'grade' => $grade, 'isfinalgrade' => $final ? 1 : 0,
            'finalised' => 1]);
        self::queue($cm);
    }

    #[\Override]
    public function all_markers_mark(\cm_info $cm, int $userid): void {
        $this->mark($cm, $userid, get_admin()->id, 60, false, 'assessor_1');
        $this->mark($cm, $userid, get_admin()->id, 60, false, 'assessor_2');
    }

    #[\Override]
    public function blank_feedback(\cm_info $cm, int $userid, int $markerid): void {
        global $DB;
        $submissionid = $DB->get_field(
            'coursework_submissions',
            'id',
            ['courseworkid' => $cm->instance, 'allocatableid' => $userid],
            MUST_EXIST
        );
        self::plugin_gen('mod_coursework')->create_feedback(['submissionid' => $submissionid, 'assessorid' => $markerid,
            'grade' => null, 'feedbackcomment' => '', 'stage_identifier' => 'assessor_1', 'finalised' => 0]);
        self::queue($cm);
    }

    #[\Override]
    public function release(\cm_info $cm, int $userid): void {
        // Publishing is for the whole coursework; the agreed grade is given with mark(..., true, 'final_agreed_1').
        \mod_coursework\models\coursework::get_from_id($cm->instance)->publish_grades();
        self::queue($cm);
    }

    #[\Override]
    public function extend(\cm_info $cm, int $userid, int $until, bool $selfcert = false): void {
        global $DB;
        if (!$selfcert) {
            $this->unsupported('Extensions (other than self-certified) on');
        }
        $record = ['courseworkid' => $cm->instance, 'allocatableid' => $userid, 'allocatabletype' => 'user',
            'extended_deadline' => $until, 'type' => 'extension', 'selfcert' => 1, 'timecreated' => time()];
        $DB->insert_record('coursework_mitigations', $record);
        self::queue($cm);
    }

    #[\Override]
    public function remove_extension(\cm_info $cm, int $userid): void {
        global $DB;
        $params = self::submission_params($cm, $userid) + ['type' => 'extension'];
        self::$removed = (int)$DB->get_field('coursework_mitigations', 'MAX(id)', $params);
        $DB->delete_records('coursework_mitigations', $params);
        self::queue($cm);
    }

    #[\Override]
    public function event_fires(\cm_info $cm, int $userid, string $event): void {
        global $DB;
        $params = self::submission_params($cm, $userid);
        [$class, $objectid] = match ($event) {
            'upload' => ['\courseworksubmission_file\event\assessable_uploaded',
                $DB->get_field('coursework_submissions', 'id', $params, MUST_EXIST)],
            'mitigation added' => ['\mod_coursework\event\coursework_mitigation_added',
                $DB->get_field('coursework_mitigations', 'MAX(id)', $params + ['type' => 'extension'])],
            'mitigation removed' => ['\mod_coursework\event\coursework_mitigation_removed', self::$removed],
        };
        // Built as block_rhb and the file sub-plugin build them. Should Exeter's classes want more in
        // 'other', create() says which here.
        $class::create(['context' => \context_module::instance($cm->id), 'objectid' => (int)$objectid,
            'relateduserid' => $userid, 'userid' => $userid, 'other' => []])->trigger();
    }

    #[\Override]
    public function flag_plagiarism(\cm_info $cm, int $userid, string $status = '0'): void {
        global $DB;
        $submissionid = $DB->get_field('coursework_submissions', 'id', self::submission_params($cm, $userid), MUST_EXIST);
        $DB->insert_record('coursework_plagiarism_flags', self::filled('coursework_plagiarism_flags',
            ['courseworkid' => $cm->instance, 'submissionid' => $submissionid, 'status' => $status]));
        self::queue($cm);
    }

    #[\Override]
    public function used_self_certs(int $userid, int $count): void {
        global $DB;
        for ($i = 0; $i < $count; $i++) {
            $DB->insert_record('coursework_mitigations', ['courseworkid' => 0, 'allocatableid' => $userid,
                'allocatabletype' => 'user', 'extended_deadline' => time(), 'type' => 'extension', 'selfcert' => 1,
                'timecreated' => time()]);
        }
    }

    /**
     * A manual allocation at a stage.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int $markerid
     * @param string $stage
     */
    private static function allocation(\cm_info $cm, int $userid, int $markerid, string $stage): void {
        self::plugin_gen('mod_coursework')->create_allocation(['courseworkid' => $cm->instance, 'allocatableid' => $userid,
            'allocatabletype' => 'user', 'assessorid' => $markerid, 'stage_identifier' => $stage, 'ismanual' => 1]);
    }

    /**
     * A record with every other required column of the table filled with an empty value.
     *
     * @param string $table
     * @param array $record
     * @return array
     */
    private static function filled(string $table, array $record): array {
        global $DB;
        foreach ($DB->get_columns($table) as $column) {
            if ($column->name !== 'id' && !isset($record[$column->name]) && $column->not_null && !$column->has_default) {
                $record[$column->name] = in_array($column->meta_type, ['C', 'X']) ? '' : 0;
            }
        }
        return $record;
    }

    /**
     * The conditions that find a student's submission.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array
     */
    private static function submission_params(\cm_info $cm, int $userid): array {
        return ['courseworkid' => $cm->instance, 'allocatableid' => $userid, 'allocatabletype' => 'user'];
    }
}
