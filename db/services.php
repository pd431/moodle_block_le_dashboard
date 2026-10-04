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
 * Web services, all AJAX-only and acting on the logged-in user.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_my_assessments_get_view' => [
        'classname' => '\block_my_assessments\external\get_view',
        'description' => 'Render one view of the My assessments dashboard for the current user.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        // Rendering never writes the session, so it doesn't wait for a Refresh in another request (PR10).
        'readonlysession' => true,
    ],
    'block_my_assessments_refresh' => [
        'classname' => '\block_my_assessments\external\refresh',
        'description' => 'Re-index the current user\'s scope for one view, inline or queued.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
