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

use block_le_dashboard\local\genericname;

/**
 * The generic name hint (Pierre, 2026-10-17).
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\genericname
 */
final class genericname_test extends \advanced_testcase {
    /**
     * Which names are generic, with the default terms.
     *
     * @dataProvider names_provider
     * @param string $name
     * @param bool $generic
     */
    public function test_is_generic(string $name, bool $generic): void {
        $this->resetAfterTest();
        $course = genericname::words('Computing in History - CSM1056_B_23_48353 CSM1056_B_23');
        $this->assertSame($generic, genericname::is_generic($name, $course), $name);
    }

    /**
     * Cases for test_is_generic.
     *
     * @return array
     */
    public static function names_provider(): array {
        return [
            ['Essay 1', true],
            ['CW2 - Summative', true],
            ['Coursework (Part 2)', true],
            ['Essay II', true],
            ['Mid-term exam', true],
            ['ESSAY one', true],
            ['Computing in History Essay', true],
            ['CSM1056 Assessment 1', true],
            ['Computing the Census, 1841-1911', false],
            ['Online Learning Essay', false],
            ['Essays', false],
            ['', false],
            ['- 1 -', true],
            ['---', false],
        ];
    }

    /**
     * The settings: off on a new site (beta27), other terms, other text.
     */
    public function test_settings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Law and Society', 'shortname' => 'LAW1001']);
        $this->assertFalse(genericname::enabled());
        $this->assertNull(genericname::hint('row1', 'Law Essay', (int)$course->id));
        set_config('genericnames', 1, 'block_le_dashboard');
        $this->assertTrue(genericname::enabled());
        $hint = genericname::hint('row1', 'Law Essay', (int)$course->id);
        $this->assertSame('genericname', $hint['key']);
        $this->assertStringStartsWith('Students benefit from descriptive names', $hint['text']);

        set_config('genericterms', "resit\nessay", 'block_le_dashboard');
        set_config('genericnametext', 'Please give this a proper title.', 'block_le_dashboard');
        $this->assertNull(genericname::hint('row1', 'Coursework 1', (int)$course->id));
        $this->assertSame('Please give this a proper title.', genericname::hint('row1', 'Resit Essay', (int)$course->id)['text']);

        set_config('genericnames', 0, 'block_le_dashboard');
        $this->assertNull(genericname::hint('row1', 'Essay 1', (int)$course->id));
    }
}
