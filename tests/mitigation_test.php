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

namespace block_le_dashboard;

use block_le_dashboard\local\mitigation;

/**
 * Mitigate menu items from block_rhb's links.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\mitigation
 */
final class mitigation_test extends \advanced_testcase {
    /**
     * Only the address is kept; the label is ours, by kind. Unreadable addresses keep block_rhb's HTML,
     * and HTML with no link gives no item.
     */
    public function test_item(): void {
        global $CFG;
        $item = mitigation::item(
            mitigation::KIND_REQUEST,
            '<a class="btn" href="' . $CFG->wwwroot . '/blocks/rhb/srm.php?a=1&amp;b=2" target="_blank">Old wording</a>'
        );
        $this->assertSame(['url' => $CFG->wwwroot . '/blocks/rhb/srm.php?a=1&b=2', 'label' => 'Request mitigation'], $item);
        $item = mitigation::item(mitigation::KIND_SELFCERT, "<a href='https://forms.example.com/x'>Apply</a>");
        $this->assertSame(['url' => 'https://forms.example.com/x', 'label' => 'Self-certify an extension'], $item);
        $odd = '<a href="javascript:void(0)" data-action="open">Request</a>';
        $this->assertSame(['html' => $odd], mitigation::item(mitigation::KIND_REQUEST, $odd));
        $this->assertNull(mitigation::item(mitigation::KIND_SELFCERT, '<span>Limit reached</span>'));
    }
}
