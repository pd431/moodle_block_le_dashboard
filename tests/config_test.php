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

namespace block_my_assessments;

use block_my_assessments\local\config;

/**
 * Tests for the settings the views read.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\local\config
 */
final class config_test extends \advanced_testcase {
    /**
     * G4: Mitigate from 90 days before the student's deadline to 24 hours after, by default,
     * and as set.
     */
    public function test_mitigation_window(): void {
        $this->resetAfterTest();
        $now = 1790000000;
        $this->assertFalse(config::mitigation_open(null, $now));
        $this->assertFalse(config::mitigation_open($now + 91 * DAYSECS, $now));
        $this->assertTrue(config::mitigation_open($now + 90 * DAYSECS, $now));
        $this->assertTrue(config::mitigation_open($now - 24 * HOURSECS, $now));
        $this->assertFalse(config::mitigation_open($now - 25 * HOURSECS, $now));

        set_config('mitigationopens', 30, 'block_my_assessments');
        set_config('mitigationcloses', 48, 'block_my_assessments');
        $this->assertFalse(config::mitigation_open($now + 40 * DAYSECS, $now));
        $this->assertTrue(config::mitigation_open($now - 30 * HOURSECS, $now));
    }

    /**
     * G9: the grace period, in hours, defaults to 24.
     */
    public function test_grace(): void {
        $this->resetAfterTest();
        $this->assertSame(24 * HOURSECS, config::grace());
        set_config('graceperiod', 0, 'block_my_assessments');
        $this->assertSame(0, config::grace());
    }
}
