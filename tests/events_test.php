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

use block_my_assessments\local\adapter\registry;

/**
 * Every event an adapter relies on is observed.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_my_assessments\observer
 */
final class events_test extends \advanced_testcase {
    /**
     * Each adapter's events are in db/events.php, and those of installed modules exist.
     */
    public function test_adapter_events_are_observed(): void {
        global $CFG;
        $observers = [];
        require($CFG->dirroot . '/blocks/my_assessments/db/events.php');
        $observed = array_column($observers, 'callback', 'eventname');
        foreach (registry::OWN as $modname => $class) {
            foreach ($class::events() as $event) {
                $this->assertArrayHasKey($event, $observed, "$modname needs $event observed");
                $this->assertSame('\block_my_assessments\observer::module_event', $observed[$event]);
                if (registry::module_installed($modname)) {
                    $this->assertTrue(class_exists($event), "$event does not exist");
                }
            }
        }
    }

    /**
     * Observed core events exist, and every callback is a real method.
     */
    public function test_observers_resolve(): void {
        global $CFG;
        $observers = [];
        require($CFG->dirroot . '/blocks/my_assessments/db/events.php');
        foreach ($observers as $o) {
            if (str_starts_with($o['eventname'], '\core\\')) {
                $this->assertTrue(class_exists($o['eventname']), $o['eventname']);
            }
            [$class, $method] = explode('::', $o['callback']);
            $this->assertTrue(method_exists($class, $method), $o['callback']);
        }
    }
}
