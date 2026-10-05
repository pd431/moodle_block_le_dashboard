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
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Default academic years, default settings and the first fill of the index.
 */
function xmldb_block_le_dashboard_install() {
    \block_le_dashboard\local\years::create_defaults();
    if (get_config('block_le_dashboard', 'enabledtypes') === false) {
        set_config('enabledtypes', \block_le_dashboard\local\adapter\registry::DEFAULT_ENABLED, 'block_le_dashboard');
    }
    \block_le_dashboard\task\backfill::queue();
    return true;
}
