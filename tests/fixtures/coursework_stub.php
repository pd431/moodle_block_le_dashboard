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
 * A stand-in for mod_coursework's model, which block_rhb's self-certification link takes, for Behat scenarios
 * tagged @block_rhb on sites without mod_coursework. Loaded only with rhb_stub.php.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_coursework\models;

/**
 * Finds a coursework by its instance id; the rhb stand-in only needs an object.
 */
class coursework {
    /**
     * A coursework.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function find($id) {
        return (object)['id' => (int)$id];
    }
}
