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
 * Empties the index and fills it again straight away.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use block_le_dashboard\local\index\indexer;
use block_le_dashboard\task\backfill;

[$options, $unrecognised] = cli_get_params(['help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    cli_writeln(get_string('clirebuild_help', 'block_le_dashboard'));
    exit(0);
}
\core\cron::setup_user();
if (!backfill::rebuild_now(fn($line) => cli_writeln($line))) {
    cli_error(get_string('clirebuild_locked', 'block_le_dashboard'));
}
$counts = indexer::counts();
cli_writeln(get_string('indexcount_cm', 'block_le_dashboard', $counts['cm']));
cli_writeln(get_string('indexcount_stu', 'block_le_dashboard', $counts['stu']));
cli_writeln(get_string('indexcount_mark', 'block_le_dashboard', $counts['mark']));
