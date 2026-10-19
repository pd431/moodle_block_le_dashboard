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

use block_le_dashboard\local\config;

/**
 * Tests for the settings the views read.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\config
 */
final class config_test extends \advanced_testcase {
    /**
     * Past work keeps Mitigate until 24 hours after the student's deadline, by default, and as set.
     */
    public function test_mitigation_window(): void {
        $this->resetAfterTest();
        $now = 1790000000;
        $this->assertFalse(config::mitigation_open(null, $now));
        $this->assertTrue(config::mitigation_open($now + 91 * DAYSECS, $now));
        $this->assertTrue(config::mitigation_open($now - 24 * HOURSECS, $now));
        $this->assertFalse(config::mitigation_open($now - 25 * HOURSECS, $now));

        set_config('mitigationcloses', 48, 'block_le_dashboard');
        $this->assertTrue(config::mitigation_open($now - 30 * HOURSECS, $now));
        $this->assertFalse(config::mitigation_open($now - 49 * HOURSECS, $now));
    }

    /**
     * G9: the grace period, in hours, defaults to 24.
     */
    public function test_grace(): void {
        $this->resetAfterTest();
        $this->assertSame(24 * HOURSECS, config::grace());
        set_config('graceperiod', 0, 'block_le_dashboard');
        $this->assertSame(0, config::grace());
    }

    /**
     * The marking deadline: the assessment's own wins; without one, the deadline plus the default marking period
     * (12 days, as set, or none at 0); nothing without a deadline (UAT, beta27).
     */
    public function test_markingdue(): void {
        $this->resetAfterTest();
        $due = 1790000000;
        $own = $due + 3 * DAYSECS;
        $this->assertSame([$own, false], config::markingdue((object)['due' => $due, 'markingdue' => $own]));
        $this->assertSame([$due + 12 * DAYSECS, true], config::markingdue((object)['due' => $due, 'markingdue' => null]));
        $this->assertSame([$due + 12 * DAYSECS, true], config::markingdue((object)['due' => $due, 'markingdue' => 0]));
        $this->assertSame([null, false], config::markingdue((object)['due' => null, 'markingdue' => null]));

        set_config('defaultmarkingdays', 20, 'block_le_dashboard');
        $this->assertSame([$due + 20 * DAYSECS, true], config::markingdue((object)['due' => $due, 'markingdue' => null]));
        set_config('defaultmarkingdays', 0, 'block_le_dashboard');
        $this->assertSame([null, false], config::markingdue((object)['due' => $due, 'markingdue' => null]));
    }
}
