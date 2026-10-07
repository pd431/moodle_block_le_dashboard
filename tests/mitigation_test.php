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
     * rc6's test for a real link: an anchor with an href.
     */
    public function test_has_link(): void {
        $this->assertTrue(mitigation::has_link('<a class="btn" href="https://example.com/x">Apply</a>'));
        $this->assertTrue(mitigation::has_link("<A HREF='/x'>Apply</A>"));
        $this->assertFalse(mitigation::has_link('<span>Limit reached</span>'));
        $this->assertFalse(mitigation::has_link('<a name="x">Anchor</a>'));
        $this->assertFalse(mitigation::has_link(''));
    }

    /**
     * Links for deleting a self-certification read "Delete mitigation", found by their text or address as
     * rc6 did; other links and the rest of the HTML are left as they are.
     */
    public function test_delete_label(): void {
        $this->resetAfterTest();
        $this->assertSame(
            '<div><a href="/rhb/delete.php?id=3" class="btn" data-action="mitigate-delete">Delete mitigation</a></div>',
            mitigation::delete_label('<div><a href="/rhb/delete.php?id=3" class="btn">Remove</a></div>')
        );
        $this->assertSame(
            '<a href="/rhb/x.php?id=3" data-action="mitigate-delete">Delete mitigation</a>',
            mitigation::delete_label('<a href="/rhb/x.php?id=3"><i></i> Delete self-cert</a>')
        );
        $plain = '<a href="/rhb/selfcert.php?id=3">Self-certify</a> <span>2 left</span>';
        $this->assertSame($plain, mitigation::delete_label($plain));
    }

    /**
     * Without block_rhb there is no Mitigate.
     */
    public function test_links_without_block_rhb(): void {
        global $CFG;
        if (file_exists($CFG->dirroot . '/blocks/rhb/lib.php')) {
            $this->markTestSkipped('block_rhb is installed');
        }
        mitigation::reset_caches();
        $this->assertNull(mitigation::links('assign', 1));
        $this->assertNull(mitigation::links('coursework', 1));
    }
}
