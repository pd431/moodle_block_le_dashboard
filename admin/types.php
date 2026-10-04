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
 * Activity types (R5): every type with gradebook items, what the block can know about it, and a checkbox.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_my_assessments\local\adapter\adapter;
use block_my_assessments\local\adapter\registry;
use block_my_assessments\local\index\indexer;

admin_externalpage_setup('block_my_assessments_types');

$types = registry::listable_types();
if (data_submitted() && confirm_sesskey()) {
    $enabled = optional_param_array('enabled', [], PARAM_PLUGIN);
    $before = registry::enabled_types();
    foreach (array_keys($types) as $modname) {
        registry::set_enabled($modname, !empty($enabled[$modname]));
    }
    if ($before != registry::enabled_types()) {
        \core\task\manager::queue_adhoc_task(new \block_my_assessments\task\backfill(), true);
    }
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$enabled = registry::enabled_types();
$rows = [];
foreach ($types as $modname => $support) {
    $error = registry::last_error($modname);
    $rows[] = [
        'modname' => $modname,
        'name' => get_string('modulename', $modname),
        'support' => get_string($support === adapter::SUPPORT_FULL ? 'support_full' : 'support_generic', 'block_my_assessments'),
        'supportdesc' => get_string(
            ($support === adapter::SUPPORT_FULL ? 'support_full' : 'support_generic') . '_desc',
            'block_my_assessments'
        ),
        'enabled' => in_array($modname, $enabled),
        'error' => $error ? get_string((($error->count ?? 1) > 1) ? 'lasterrorcount' : 'lasterror', 'block_my_assessments', [
            'time' => userdate($error->time), 'message' => $error->message, 'count' => $error->count ?? 1]) : null,
    ];
}
usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('activitytypes', 'block_my_assessments'));
echo $OUTPUT->render_from_template('block_my_assessments/admin_types', [
    'action' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'rows' => $rows,
]);
echo $OUTPUT->footer();
