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
 * The index: what it holds, and a rebuild for testing changes back and forth.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_le_dashboard\local\index\indexer;
use block_le_dashboard\task\backfill;

admin_externalpage_setup('block_le_dashboard_index');

$rebuild = optional_param('rebuild', 0, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if ($rebuild && $confirm && data_submitted() && confirm_sesskey()) {
    backfill::rebuild();
    redirect($PAGE->url, get_string('rebuildqueued', 'block_le_dashboard'), null, \core\output\notification::NOTIFY_SUCCESS);
}
if ($rebuild) {
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('rebuildconfirm', 'block_le_dashboard'),
        new single_button(
            new moodle_url($PAGE->url, ['rebuild' => 1, 'confirm' => 1]),
            get_string('continue'),
            'post',
            single_button::BUTTON_DANGER
        ),
        $PAGE->url
    );
    echo $OUTPUT->footer();
    die();
}

$counts = indexer::counts();
$pending = get_config('block_le_dashboard', 'backfillpending');
$done = get_config('block_le_dashboard', 'backfilldone');
$context = [
    'counts' => [
        ['label' => get_string('indexcount_cm', 'block_le_dashboard', $counts['cm'])],
        ['label' => get_string('indexcount_stu', 'block_le_dashboard', $counts['stu'])],
        ['label' => get_string('indexcount_mark', 'block_le_dashboard', $counts['mark'])],
    ],
    'status' => $pending
        ? get_string('indexpending', 'block_le_dashboard', userdate((int)$pending))
        : ($done ? get_string('indexdone', 'block_le_dashboard', userdate((int)$done)) : null),
    'rebuildurl' => (new moodle_url($PAGE->url, ['rebuild' => 1]))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('indexpage', 'block_le_dashboard'));
echo $OUTPUT->render_from_template('block_le_dashboard/admin_index', $context);
echo $OUTPUT->footer();
