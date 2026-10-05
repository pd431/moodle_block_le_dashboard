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

/**
 * Re-indexes one assessment on the Behat site, for the step that runs two at once
 * ("... is re-indexed twice at the same time"). Prints "ok" on success.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
define('CLI_SCRIPT', true);
define('BEHAT_TEST', 1);

// The site's config.php: blocks/le_dashboard/tests/fixtures, under public/ on Moodle 5.1+.
$dir = __DIR__;
while (!file_exists($dir . '/config.php') && dirname($dir) !== $dir) {
    $dir = dirname($dir);
}
require($dir . '/config.php');

(new \block_le_dashboard\local\index\indexer())->index_cm((int)$argv[1]);
echo "ok\n";
