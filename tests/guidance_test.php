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

use block_le_dashboard\local\guidance;

/**
 * Guidance and support boxes (Pierre, 2026-10-09).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\guidance
 */
final class guidance_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        guidance::reset_caches();
    }

    /**
     * Editor leftovers count as empty; text or an image doesn't.
     */
    public function test_is_empty(): void {
        foreach (['', '<p><br></p>', '<p dir="ltr"></p>', '<div>&nbsp;</div>', '<p> </p><p></p>', "<p>\u{00A0}</p>"] as $html) {
            $this->assertTrue(guidance::is_empty($html), $html);
        }
        $this->assertFalse(guidance::is_empty('<p>Ask the Hub.</p>'));
        $this->assertFalse(guidance::is_empty('<p><img src="https://example.com/a.png" alt=""></p>'));
    }

    /**
     * Unset boxes use today's wording; an emptied box gives none; text is cleaned.
     */
    public function test_html(): void {
        $this->assertStringContainsString('contact your module convenor', guidance::html('feedbacklate'));
        $this->assertNull(guidance::html('plagiarism'), 'no default');
        set_config('guidance_feedbacklate', '<p><br></p>', 'block_le_dashboard');
        guidance::reset_caches();
        $this->assertNull(guidance::html('feedbacklate'));
        set_config('guidance_brief', '<p>Read this</p><script>alert(1)</script>', 'block_le_dashboard');
        guidance::reset_caches();
        $html = guidance::html('brief');
        $this->assertStringContainsString('Read this', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * Every box has its strings, default included.
     */
    public function test_strings(): void {
        $manager = get_string_manager();
        foreach (guidance::KEYS as $key) {
            foreach (['', '_desc', '_default'] as $suffix) {
                $this->assertTrue($manager->string_exists('guidance_' . $key . $suffix, 'block_le_dashboard'), $key . $suffix);
            }
        }
    }

    /**
     * The upgrade carries edited wording and links into the boxes and removes the old settings.
     */
    public function test_migrate(): void {
        set_config('latenote', 'Up to 10% off', 'block_le_dashboard');
        set_config('latenoteurl', 'https://example.ac.uk/late', 'block_le_dashboard');
        set_config('feedbackcontact', 'the Student Hub', 'block_le_dashboard');
        set_config('feedbackcontacturl', 'https://example.ac.uk/hub', 'block_le_dashboard');
        set_config('briefguidanceurl', 'https://example.ac.uk/briefs', 'block_le_dashboard');
        set_config('untaggedguidanceurl', '', 'block_le_dashboard');
        guidance::migrate();
        $late = get_config('block_le_dashboard', 'guidance_latepenalty');
        $this->assertStringContainsString('Up to 10% off', $late);
        $this->assertStringContainsString('href="https://example.ac.uk/late"', $late);
        $this->assertStringContainsString(
            '<a href="https://example.ac.uk/hub">the Student Hub</a>',
            get_config('block_le_dashboard', 'guidance_feedbacklate')
        );
        $brief = get_config('block_le_dashboard', 'guidance_brief');
        $this->assertStringContainsString('Including the assessment brief', $brief);
        $this->assertStringContainsString('href="https://example.ac.uk/briefs"', $brief);
        $this->assertSame(
            guidance::default('untagged'),
            (string)get_config('block_le_dashboard', 'guidance_untagged'),
            'nothing to carry: the default stays'
        );
        foreach (
            ['latenote', 'latenoteurl', 'feedbackcontact', 'feedbackcontacturl', 'briefguidanceurl',
                'untaggedguidanceurl'] as $name
        ) {
            $this->assertFalse(get_config('block_le_dashboard', $name), $name);
        }
    }

    /**
     * A site that never saved the old settings keeps the defaults; a cleared contact empties its box.
     */
    public function test_migrate_untouched_and_cleared(): void {
        unset_config('guidance_latepenalty', 'block_le_dashboard');
        unset_config('guidance_feedbacklate', 'block_le_dashboard');
        guidance::migrate();
        $this->assertFalse(get_config('block_le_dashboard', 'guidance_latepenalty'), 'left for the default');
        $this->assertFalse(get_config('block_le_dashboard', 'guidance_feedbacklate'), 'left for the default');
        set_config('feedbackcontact', '', 'block_le_dashboard');
        guidance::migrate();
        $this->assertSame('', get_config('block_le_dashboard', 'guidance_feedbacklate'));
    }
}
