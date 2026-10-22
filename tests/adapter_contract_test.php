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

use block_le_dashboard\local\adapter\registry;

/**
 * What an activity type does is the adapter's to say (beta29, F5): no code outside the adapters names one.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\adapter\registry
 */
final class adapter_contract_test extends \advanced_testcase {
    /**
     * Files that may name an activity type outside the adapters, and why.
     *
     * @var string[] path => reason
     */
    const EXEMPT = [
        'classes/local/genericname.php' => 'the generic names list holds the words assignment, coursework, quiz',
        'classes/local/mitigation.php' => 'the Behat stand-in for coursework on sites without it (moves in R5)',
        'db/events.php' => 'the observers list names each adapter\'s events',
    ];

    /**
     * Outside classes/local/adapter and the tests, no PHP code (comments aside) names assign, quiz, peerwork
     * or coursework: a string literal of the type's name or one of its classes.
     */
    public function test_no_code_outside_the_adapters_names_a_type(): void {
        global $CFG;
        $root = $CFG->dirroot . '/blocks/le_dashboard';
        $names = array_keys(registry::OWN);
        $pattern = '~(^|[\\\\\'"])(mod_)?(' . implode('|', $names) . ')(\\\\|[\'"]|$)~';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $found = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $skip = !str_ends_with($path, '.php') || str_starts_with($path, 'classes/local/adapter/')
                || str_starts_with($path, 'tests/') || str_starts_with($path, 'vendor/') || isset(self::EXEMPT[$path]);
            if ($skip) {
                continue;
            }
            $kinds = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
                T_STRING];
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], $kinds)) {
                    continue;
                }
                if (preg_match($pattern, $token[1])) {
                    $found[] = "$path:{$token[2]} {$token[1]}";
                }
            }
        }
        $this->assertSame([], $found, "Only an adapter says what an activity type does:\n" . implode("\n", $found));
        foreach (array_keys(self::EXEMPT) as $path) {
            $this->assertFileExists("$root/$path", "exemption for a file that no longer exists");
        }
    }
}
