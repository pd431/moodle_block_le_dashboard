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

use block_le_dashboard\output\dashboard;

/**
 * The admin notice above the dashboard (Pierre, 2026-10-17).
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\output\dashboard::notice
 */
final class notice_test extends \advanced_testcase {
    /**
     * Unset, empty, or only the editor's empty markup: no notice.
     */
    public function test_empty(): void {
        $this->resetAfterTest();
        $this->assertNull(dashboard::notice());
        foreach (['', '  ', '<p><br></p>', '<p>&nbsp;</p>', '<script>alert(1)</script>'] as $raw) {
            set_config('notice', $raw, 'block_le_dashboard');
            $this->assertNull(dashboard::notice(), $raw);
        }
    }

    /**
     * The notice keeps its markup and links, and loses scripts.
     */
    public function test_formatted(): void {
        $this->resetAfterTest();
        set_config('notice', '<div class="alert alert-info">Still in development. '
            . '<a href="https://example.com/form">Feedback form</a></div><script>alert(1)</script>', 'block_le_dashboard');
        $html = dashboard::notice();
        $this->assertStringContainsString('class="alert alert-info"', $html);
        $this->assertStringContainsString('href="https://example.com/form"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * The dashboard passes it to its template.
     */
    public function test_exported(): void {
        global $PAGE;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $output = $PAGE->get_renderer('core');
        $this->assertNull((new dashboard($user->id))->export_for_template($output)['notice']);
        set_config('notice', '<p>Hello</p>', 'block_le_dashboard');
        $this->assertStringContainsString('Hello', (new dashboard($user->id))->export_for_template($output)['notice']);
    }
}
