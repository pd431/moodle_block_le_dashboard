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

namespace block_le_dashboard\fixture;

/**
 * The fixture for any type without its own: it can be created with a due date (a workshop's submission end,
 * anything else's duedate) and nothing more, as the generic adapter reads nothing more.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generic extends base {
    /** @var string */
    private string $modname;

    /**
     * Writes one type.
     *
     * @param string $modname
     */
    public function __construct(string $modname) {
        $this->modname = $modname;
    }

    #[\Override]
    public function modname(): string {
        return $this->modname;
    }

    #[\Override]
    protected function due_field(): string {
        return $this->modname === 'workshop' ? 'submissionend' : 'duedate';
    }
}
