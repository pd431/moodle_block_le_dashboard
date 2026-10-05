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

namespace block_le_dashboard\local\view;

use block_le_dashboard\local\scope;

/**
 * Makes a view object for a key, checking the user may open it.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class factory {
    /**
     * The view, or an exception if the user may not open it.
     *
     * @param string $key
     * @param int $userid
     * @param int|null $yearid
     * @return base
     */
    public static function make(string $key, int $userid, ?int $yearid = null): base {
        if (!in_array($key, scope::views($userid))) {
            throw new \moodle_exception('noaccesstoview', 'block_le_dashboard');
        }
        return match ($key) {
            scope::STUDENT => new student($userid),
            scope::STAFF => new staff($userid, null, $yearid),
            scope::CONVENOR => new convenor($userid, null, $yearid),
        };
    }
}
