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

namespace block_my_assessments\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Privacy provider.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Export and delete a student's index rows and preferences.
     */
    public function test_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $gen->get_plugin_generator('block_my_assessments')->configure();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $other = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('assign', ['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $gen->get_plugin_generator('block_my_assessments')->tag($a->cmid);
        set_user_preference('block_my_assessments_view', 'student', $student);

        $context = \context_user::instance($student->id);
        $contexts = provider::get_contexts_for_userid($student->id);
        $this->assertEquals([$context->id], $contexts->get_contextids());

        provider::export_user_data(new approved_contextlist($student, 'block_my_assessments', [$context->id]));
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'block_my_assessments')]);
        $this->assertCount(1, $data->assessments);
        provider::export_user_preferences($student->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('block_my_assessments');
        $this->assertNotEmpty((array)$prefs);

        provider::delete_data_for_user(new approved_contextlist($student, 'block_my_assessments', [$context->id]));
        $this->assertFalse($DB->record_exists('block_my_assessments_stu', ['userid' => $student->id]));
        $this->assertTrue($DB->record_exists('block_my_assessments_stu', ['userid' => $other->id]));
    }

    /**
     * PR13: a marker allocated on students' rows is found, exported and scrubbed.
     */
    public function test_allocated_marker(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $marker = $gen->create_user();
        $keep = $gen->create_user();
        $DB->insert_record('block_my_assessments_stu', (object)['cmid' => 5, 'userid' => $student->id,
            'allocatedto' => ",{$marker->id},{$keep->id},", 'canaccess' => 1, 'individual' => 0, 'reopened' => 0]);
        $DB->insert_record('block_my_assessments_stu', (object)['cmid' => 6, 'userid' => $student->id,
            'allocatedto' => ",{$marker->id},", 'canaccess' => 1, 'individual' => 0, 'reopened' => 0]);

        $context = \context_user::instance($marker->id);
        $this->assertEquals([$context->id], provider::get_contexts_for_userid($marker->id)->get_contextids());
        provider::export_user_data(new approved_contextlist($marker, 'block_my_assessments', [$context->id]));
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'block_my_assessments')]);
        $this->assertCount(2, $data->allocated);

        provider::delete_data_for_user(new approved_contextlist($marker, 'block_my_assessments', [$context->id]));
        $this->assertEquals(
            [5 => ",{$keep->id},", 6 => null],
            $DB->get_records_menu('block_my_assessments_stu', ['userid' => $student->id], 'cmid', 'cmid, allocatedto')
        );
        $this->assertEmpty(provider::get_contexts_for_userid($marker->id)->get_contextids());
    }
}
