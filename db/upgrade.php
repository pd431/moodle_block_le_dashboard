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
 * Upgrade steps.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the block.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_le_dashboard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100700) {
        // Assessments where students hand nothing in online. The backfill fills both fields in.
        $table = new xmldb_table('block_le_dashboard_cm');
        $field = new xmldb_field('nosubmission', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'stages');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Quizzes that never release marks (F10).
        $field = new xmldb_field('norelease', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'nosubmission');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        \block_le_dashboard\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100700, 'le_dashboard', 'block');
    }

    if ($oldversion < 2026100702) {
        // Settings a tagged assessment is missing, for the staff "Check settings" flag. The backfill fills it in.
        $table = new xmldb_table('block_le_dashboard_cm');
        $field = new xmldb_field('setup', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'norelease');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        \block_le_dashboard\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100702, 'le_dashboard', 'block');
    }

    if ($oldversion < 2026100800) {
        // Mitigate is offered as rc6 did, so the window's opening setting goes. Coursework is read from the
        // fields Exeter's coursework relies on: re-index it.
        unset_config('mitigationopens', 'block_le_dashboard');
        \block_le_dashboard\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100800, 'le_dashboard', 'block');
    }

    if ($oldversion < 2026100801) {
        // Whether the last index couldn't read all student data, so staff rows can say so (Pierre, 2026-10-08).
        $table = new xmldb_table('block_le_dashboard_cm');
        $field = new xmldb_field('loaderror', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'setup');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_block_savepoint(true, 2026100801, 'le_dashboard', 'block');
    }

    if ($oldversion < 2026100901) {
        // Why a submission can't be changed, for the "Can't resubmit" control (Pierre, 2026-10-09). Locked work
        // no longer leaves Upcoming early, and students an access restriction shuts out leave the marking
        // counts: re-index everything.
        $table = new xmldb_table('block_le_dashboard_stu');
        $field = new xmldb_field('lockreason', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'reopened');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Guidance and support text moves into rich-text boxes (Pierre, 2026-10-09).
        \block_le_dashboard\local\guidance::migrate();
        \block_le_dashboard\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100901, 'le_dashboard', 'block');
    }

    if ($oldversion < 2026100908) {
        // Hints and guidance (beta27): new sites start with some hints off and neutral guidance; this site keeps
        // what it has today.
        \block_le_dashboard\local\config::keep_today();
        upgrade_block_savepoint(true, 2026100908, 'le_dashboard', 'block');
    }

    return true;
}
