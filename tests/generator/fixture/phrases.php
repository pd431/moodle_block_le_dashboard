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

namespace block_le_dashboard\fixture;

/**
 * The words the tests use for times, read the same way by Behat steps and PHPUnit tests.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phrases {
    /**
     * A time from words: now, tomorrow, yesterday, "3 days ago", "in 10 days", "in 1 minute", or an absolute time
     * that may name its zone ("12 October 2026 10:00 Europe/London"), which strtotime() reads only as written.
     *
     * @param string $text
     * @return int
     */
    public static function time(string $text): int {
        $absolute = trim($text);
        $text = strtolower($absolute);
        $map = ['now' => 0, 'tomorrow' => DAYSECS, 'yesterday' => -DAYSECS];
        if (isset($map[$text])) {
            return time() + $map[$text];
        }
        if (preg_match('/^in (\d+) (minute|hour|day|week)s?$/', $text, $m)) {
            return strtotime("+{$m[1]} {$m[2]}");
        }
        if (preg_match('/^(\d+) (minute|hour|day|week)s? ago$/', $text, $m)) {
            return strtotime("-{$m[1]} {$m[2]}");
        }
        $time = strtotime($absolute);
        if ($time === false) {
            throw new \coding_exception("Unknown time '$absolute'");
        }
        return $time;
    }
}
