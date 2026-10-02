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
 * Library callbacks.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * User preferences the dashboard's JavaScript may set for the current user
 * (core_user_update_user_preferences). Each is typed and limited to known values.
 *
 * @return array
 */
function block_my_assessments_user_preferences(): array {
    return [
        'block_my_assessments_view' => [
            'type' => PARAM_ALPHA,
            'null' => NULL_NOT_ALLOWED,
            'default' => '',
            'choices' => ['', 'student', 'staff', 'convenor'],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
        'block_my_assessments_showgrades' => [
            'type' => PARAM_INT,
            'null' => NULL_NOT_ALLOWED,
            'default' => 0,
            'choices' => [0, 1],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
        'block_my_assessments_window' => [
            'type' => PARAM_INT,
            'null' => NULL_NOT_ALLOWED,
            'default' => 5,
            'choices' => [1, 3, 5, 7, 15],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
        'block_my_assessments_range' => [
            'type' => PARAM_ALPHANUM,
            'null' => NULL_NOT_ALLOWED,
            'default' => '2weeks',
            'choices' => ['1week', '2weeks', 'month', 'year', 'ever'],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
        'block_my_assessments_type' => [
            'type' => PARAM_ALPHA,
            'null' => NULL_NOT_ALLOWED,
            'default' => 'both',
            'choices' => ['both', 'summative', 'formative'],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
        'block_my_assessments_year' => [
            'type' => PARAM_INT,
            'null' => NULL_ALLOWED,
            'default' => null,
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}
