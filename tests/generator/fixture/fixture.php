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
 * What the tests can do to one activity type: the write-side twin of the adapter contract (beta30, B1).
 *
 * An adapter reads a plugin's tables into index rows; a fixture writes the plugin's tables the way its users
 * would, so a test can set up any state the adapter must read. Behat steps and PHPUnit tests both go through a
 * fixture, so nothing outside tests/generator/fixture/ names a plugin's tables or classes
 * (tests/fixture_contract_test.php keeps that rule). A fixture that cannot do something for its type throws
 * \coding_exception from base, which the step then reports.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface fixture {
    /**
     * The module this fixture writes.
     *
     * @return string
     */
    public function modname(): string;

    /**
     * Creates and tags an activity.
     *
     * @param \stdClass $course
     * @param string[] $tags Tags to set on the module; the first is summative or formative.
     * @param string $name
     * @param int|null $due Null for no due date.
     * @param string $opts The step's options phrase ("with marker allocation, 2 markers"), as the features say it.
     * @return \stdClass The module generator's instance record, with cmid.
     */
    public function create(\stdClass $course, array $tags, string $name, ?int $due, string $opts = ''): \stdClass;

    /**
     * Sets the due date directly in the activity's table, firing no event (0 switches it off).
     *
     * @param \cm_info $cm
     * @param int $due
     */
    public function set_due(\cm_info $cm, int $due): void;

    /**
     * Switches the due date off the way the settings form would, firing course_module_updated.
     *
     * @param \cm_info $cm
     */
    public function switch_due_off(\cm_info $cm): void;

    /**
     * One student's submission, through the plugin's own API or generator; optionally dated.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int|null $time When it was submitted, or null for now.
     */
    public function submit(\cm_info $cm, int $userid, ?int $time = null): void;

    /**
     * A submission for a group (team assignments, peerwork).
     *
     * @param \cm_info $cm
     * @param int $userid The member who submits.
     * @param int $groupid
     */
    public function submit_for_group(\cm_info $cm, int $userid, int $groupid): void;

    /**
     * A draft the student has not handed in.
     *
     * @param \cm_info $cm
     * @param int $userid
     */
    public function save_draft(\cm_info $cm, int $userid): void;

    /**
     * The students who have submitted.
     *
     * @param \cm_info $cm
     * @return int[]
     */
    public function submitters(\cm_info $cm): array;

    /**
     * Allocates one student's marking to markers ([] leaves it unallocated).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int[] $markerids
     */
    public function allocate(\cm_info $cm, int $userid, array $markerids): void;

    /**
     * Makes someone the moderator of every submission.
     *
     * @param \cm_info $cm
     * @param int $markerid
     */
    public function moderate(\cm_info $cm, int $markerid): void;

    /**
     * A marker marks a student's work.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int $markerid
     * @param float $grade
     * @param bool $final Whether this is the final (agreed) grade where the type has stages.
     * @param string $stage The marking stage where the type has stages.
     */
    public function mark(
        \cm_info $cm,
        int $userid,
        int $markerid,
        float $grade = 60,
        bool $final = false,
        string $stage = 'assessor_1'
    ): void;

    /**
     * Every marker allocated to a student marks it.
     *
     * @param \cm_info $cm
     * @param int $userid
     */
    public function all_markers_mark(\cm_info $cm, int $userid): void;

    /**
     * Feedback saved with no grade.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int $markerid
     */
    public function blank_feedback(\cm_info $cm, int $userid, int $markerid): void;

    /**
     * Releases a student's grade to them (publishes it), and moves what happened so far a minute or two into
     * the past so that anything later in the test is visibly newer.
     *
     * @param \cm_info $cm
     * @param int $userid
     */
    public function release(\cm_info $cm, int $userid): void;

    /**
     * Reopens a student's submission for another attempt, as a teacher would.
     *
     * @param \cm_info $cm
     * @param int $userid
     */
    public function reopen(\cm_info $cm, int $userid): void;

    /**
     * Reverts a submission to draft.
     *
     * @param \cm_info $cm
     * @param int $userid
     */
    public function revert_to_draft(\cm_info $cm, int $userid): void;

    /**
     * Locks or unlocks a student's submission (prevent submission changes).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param bool $locked
     * @param int|null $teacherid Who does it, when it should go through the plugin and fire its events.
     */
    public function lock(\cm_info $cm, int $userid, bool $locked = true, ?int $teacherid = null): void;

    /**
     * Gives a student an extension (an extended deadline, or a self-certified one where the type has those).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int $until
     * @param bool $selfcert
     */
    public function extend(\cm_info $cm, int $userid, int $until, bool $selfcert = false): void;

    /**
     * Takes a student's extension away again.
     *
     * @param \cm_info $cm
     * @param int $userid
     */
    public function remove_extension(\cm_info $cm, int $userid): void;

    /**
     * Fires the activity's own event for a change already made, as the activity's page would.
     *
     * @param \cm_info $cm
     * @param int $userid The student the change was for, who made it.
     * @param string $event "upload", "mitigation added" or "mitigation removed"
     */
    public function event_fires(\cm_info $cm, int $userid, string $event): void;

    /**
     * A per-student deadline override (a quiz override closing later).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int $due
     */
    public function override(\cm_info $cm, int $userid, int $due): void;

    /**
     * Finished attempts (a quiz), answering its question each time.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param int $count
     */
    public function attempts(\cm_info $cm, int $userid, int $count): void;

    /**
     * An attempt the student never submitted, left to expire.
     *
     * @param \cm_info $cm
     * @param int $userid
     */
    public function abandon_attempt(\cm_info $cm, int $userid): void;

    /**
     * Grades a student's first or last attempt by hand, as a marker.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param string $which first or last
     * @param int|null $markerid Who grades, or null for whoever is logged in.
     */
    public function grade_attempt(\cm_info $cm, int $userid, string $which, ?int $markerid = null): void;

    /**
     * Whether a student's first or last attempt is still waiting for grading.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param string $which first or last
     * @return bool
     */
    public function attempt_ungraded(\cm_info $cm, int $userid, string $which): bool;

    /**
     * Turns off marks in every review option (a quiz).
     *
     * @param \cm_info $cm
     */
    public function hide_marks(\cm_info $cm): void;

    /**
     * Flags a student's submission for plagiarism (a coursework).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @param string $status
     */
    public function flag_plagiarism(\cm_info $cm, int $userid, string $status = '0'): void;

    /**
     * The grouping a team activity's teams come from.
     *
     * @param \cm_info $cm
     * @return int
     */
    public function team_grouping(\cm_info $cm): int;

    /**
     * Self-certified extensions a student has already used elsewhere, where the type counts them.
     *
     * @param int $userid
     * @param int $count
     */
    public function used_self_certs(int $userid, int $count): void;

    /**
     * Tables and columns holding times, for tests that move time: the plugin's own, not the index.
     *
     * @return array table => [columns]
     */
    public function time_fields(): array;
}
