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
 * Install steps.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Default academic years, default settings and the first fill of the index.
 */
function xmldb_block_my_assessments_install() {
    \block_my_assessments\local\years::create_defaults();
    if (get_config('block_my_assessments', 'enabledtypes') === false) {
        set_config('enabledtypes', \block_my_assessments\local\adapter\registry::DEFAULT_ENABLED, 'block_my_assessments');
    }
    \block_my_assessments\task\backfill::queue();
    return true;
}
