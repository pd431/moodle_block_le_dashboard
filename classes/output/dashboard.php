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

namespace block_le_dashboard\output;

use block_le_dashboard\local\scope;
use block_le_dashboard\local\view\factory;

/**
 * The whole dashboard: view tabs and the open view.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param int $userid
     */
    public function __construct(
        /** @var int The viewer. */
        protected int $userid
    ) {
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        $views = scope::views($this->userid);
        $active = scope::default_view($this->userid, $views);
        $tabs = [];
        foreach ($views as $key) {
            $tabs[] = [
                'key' => $key,
                'label' => get_string('view_' . $key, 'block_le_dashboard'),
                'active' => $key === $active,
            ];
        }
        $view = factory::make($active, $this->userid)->export();
        return [
            'uniqid' => \html_writer::random_id('ma-dashboard'),
            'hastabs' => count($tabs) > 1,
            'tabs' => $tabs,
            'activeview' => $active,
            'view' => $view,
        ];
    }
}
