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

/**
 * Academic years: list, add, edit and delete.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_le_dashboard\local\years;

admin_externalpage_setup('block_le_dashboard_years');

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if ($delete && !$confirm) {
    // Ask first: deleting a year can also reset the default year.
    $year = $DB->get_record(years::TABLE, ['id' => $delete], '*', MUST_EXIST);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeleteyear', 'block_le_dashboard', format_string($year->label)),
        new single_button(
            new moodle_url($PAGE->url, ['delete' => $delete, 'confirm' => 1]),
            get_string('delete'),
            'post',
            single_button::BUTTON_DANGER
        ),
        $PAGE->url
    );
    echo $OUTPUT->footer();
    die();
}
if ($delete && $confirm && data_submitted() && confirm_sesskey()) {
    $DB->delete_records(years::TABLE, ['id' => $delete]);
    if ((int)get_config('block_le_dashboard', 'defaultacademicyear') === $delete) {
        set_config('defaultacademicyear', 0, 'block_le_dashboard');
    }
    redirect($PAGE->url, get_string('yeardeleted', 'block_le_dashboard'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$record = $id ? $DB->get_record(years::TABLE, ['id' => $id], '*', MUST_EXIST) : null;
$form = new \block_le_dashboard\form\year($PAGE->url->out(false));
if ($record) {
    $form->set_year($record);
}
if ($form->is_cancelled()) {
    redirect($PAGE->url);
} else if ($data = $form->get_year()) {
    $data->timemodified = time();
    if (!empty($data->id)) {
        $DB->update_record(years::TABLE, $data);
    } else {
        unset($data->id);
        $data->timecreated = time();
        $DB->insert_record(years::TABLE, $data);
    }
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$rows = [];
foreach (years::all() as $year) {
    $rows[] = [
        'label' => format_string($year->label, true, ['escape' => false]),
        'start' => userdate($year->timestart, get_string('strftimedate', 'langconfig')),
        'end' => userdate($year->timeend - 1, get_string('strftimedate', 'langconfig')),
        'editurl' => (new moodle_url($PAGE->url, ['id' => $year->id]))->out(false),
        'deleteurl' => (new moodle_url($PAGE->url, ['delete' => $year->id]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('academicyears', 'block_le_dashboard'));
echo $OUTPUT->render_from_template('block_le_dashboard/admin_years', ['rows' => $rows, 'hasrows' => !empty($rows)]);
echo $OUTPUT->heading(get_string($record ? 'edityear' : 'addyear', 'block_le_dashboard'), 3);
$form->display();
echo $OUTPUT->footer();
