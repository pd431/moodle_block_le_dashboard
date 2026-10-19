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

use block_le_dashboard\local\guidance;
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
            // Popovers open on hover unless an admin turned it off (beta27 hints audit).
            'hoverpopovers' => \block_le_dashboard\local\config::shown('hoverpopovers'),
            'notice' => self::notice(),
            // The zone Moodle draws dates in; localtime.js shows them in the browser's when that differs.
            'timezone' => \core_date::get_user_timezone(),
            'view' => $view,
        ];
    }

    /**
     * The admin's notice above the tabs (Pierre, 2026-10-17), formatted, or null when there is none.
     *
     * @return string|null
     */
    public static function notice(): ?string {
        $raw = (string)get_config('block_le_dashboard', 'notice');
        if (guidance::is_empty($raw)) {
            return null;
        }
        // Filters (multilang included) apply, and unsafe HTML is cleaned.
        $html = format_text($raw, FORMAT_HTML, ['context' => \context_system::instance()]);
        return guidance::is_empty($html) ? null : $html;
    }
}
