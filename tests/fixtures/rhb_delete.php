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
 * Stands in for block_rhb's link that deletes a self-certification, on the Behat site: records that it was
 * followed ("block_rhb's delete link should have been followed").
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState,moodle.Files.RequireLogin.Missing
// The site's config.php: blocks/le_dashboard/tests/fixtures, under public/ on Moodle 5.1+.
$dir = __DIR__;
while (!file_exists($dir . '/config.php') && dirname($dir) !== $dir) {
    $dir = dirname($dir);
}
require($dir . '/config.php');

if (!defined('BEHAT_SITE_RUNNING')) {
    throw new moodle_exception('invalidaccess');
}
set_config('behatrhbdeleted', 1, 'block_le_dashboard');
echo "ok\n";
