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
 * Which fixture writes which activity type: the test-side twin of the adapter registry. A type without its own
 * fixture gets the generic one, which can create and date it and nothing more.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class registry {
    /** @var string[] Types with a fixture of their own. */
    const OWN = ['assign', 'quiz', 'coursework', 'peerwork'];

    /** @var fixture[] One instance per type. */
    private static array $fixtures = [];

    /**
     * The fixture for a type.
     *
     * @param string $modname
     * @return fixture
     */
    public static function for(string $modname): fixture {
        if (!isset(self::$fixtures[$modname])) {
            $class = __NAMESPACE__ . '\\' . (in_array($modname, self::OWN) ? $modname : 'generic');
            self::$fixtures[$modname] = new $class($modname);
        }
        return self::$fixtures[$modname];
    }

    /**
     * The fixture for an activity.
     *
     * @param \cm_info $cm
     * @return fixture
     */
    public static function for_cm(\cm_info $cm): fixture {
        return self::for($cm->modname);
    }

    /**
     * Whether a module is on this site.
     *
     * @param string $modname
     * @return bool
     */
    public static function installed(string $modname): bool {
        return (bool)\core_component::get_component_directory('mod_' . $modname);
    }

    /**
     * Every table and column that holds a time in any installed type, for tests that move time.
     *
     * @return array table => [columns]
     */
    public static function time_fields(): array {
        $fields = [];
        foreach (self::OWN as $modname) {
            if (self::installed($modname)) {
                $fields += self::for($modname)->time_fields();
            }
        }
        return $fields;
    }
}
