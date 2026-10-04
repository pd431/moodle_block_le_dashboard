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
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the block. The only supported starting point before the remake is
 * rc6 (2026092400) or earlier, which had a single academic year table.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_my_assessments_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100200) {
        // The index tables and the new academic year table, from install.xml.
        foreach (
            ['block_my_assessments_year', 'block_my_assessments_cm', 'block_my_assessments_stu',
                'block_my_assessments_mark'] as $name
        ) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }

        // Academic years move to a table whose columns aren't reserved words ("end" breaks PostgreSQL).
        $old = new xmldb_table('block_my_assessments_academic_year');
        if ($dbman->table_exists($old)) {
            $defaultid = (int)get_config('block_my_assessments', 'defaultacademicyear');
            $newdefault = 0;
            foreach ($DB->get_records('block_my_assessments_academic_year') as $year) {
                $year = (array)$year;
                $id = $DB->insert_record('block_my_assessments_year', (object)[
                    'label' => $year['label'],
                    'timestart' => (int)$year['start'],
                    'timeend' => (int)$year['end'],
                    'timecreated' => (int)($year['timecreated'] ?? 0),
                    'timemodified' => time(),
                ]);
                if ((int)$year['id'] === $defaultid) {
                    $newdefault = $id;
                }
            }
            set_config('defaultacademicyear', $newdefault, 'block_my_assessments');
            $dbman->drop_table($old);
        }
        if (!$DB->record_exists('block_my_assessments_year', [])) {
            \block_my_assessments\local\years::create_defaults();
        }

        // Settings the remake no longer has.
        foreach (['enabledhandlers', 'id_of_anchor_to_block', 'showoverviewsection', 'convenorroles', 'urlguidance'] as $name) {
            unset_config($name, 'block_my_assessments');
        }
        if (get_config('block_my_assessments', 'enabledtypes') === false) {
            set_config('enabledtypes', \block_my_assessments\local\adapter\registry::DEFAULT_ENABLED, 'block_my_assessments');
        }
        \block_my_assessments\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100200, 'my_assessments');
    }

    if ($oldversion < 2026100300) {
        // Brief hint (convenors) and mitigation modifier icons (students).
        $table = new xmldb_table('block_my_assessments_cm');
        $field = new xmldb_field('hasbrief', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'hasallocation');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('block_my_assessments_stu');
        $field = new xmldb_field('mitigation', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'individual');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        \block_my_assessments\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100300, 'my_assessments');
    }

    if ($oldversion < 2026100400) {
        // Reopened attempts (students) and when work became ready (returned work hint).
        $table = new xmldb_table('block_my_assessments_stu');
        $field = new xmldb_field('reopened', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'mitigation');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('block_my_assessments_mark');
        $field = new xmldb_field('timeready', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'submitted');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        \block_my_assessments\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100400, 'my_assessments');
    }

    if ($oldversion < 2026100500) {
        // Marking arrives at each student's own deadline, and extensions still to come (G9).
        $table = new xmldb_table('block_my_assessments_stu');
        $field = new xmldb_field('allocatedto', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'reopened');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('block_my_assessments_mark');
        $field = new xmldb_field('unitdue', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timeready');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        \block_my_assessments\task\backfill::queue();
        // An index that already has rows has been filled before: no "Preparing" banner over it.
        if ($DB->record_exists('block_my_assessments_cm', [])) {
            set_config('backfilldone', time(), 'block_my_assessments');
        }
        upgrade_block_savepoint(true, 2026100500, 'my_assessments');
    }

    if ($oldversion < 2026100600) {
        // The feedback contact now has a default (D9); beta8 saved it empty because that was its default.
        if (get_config('block_my_assessments', 'feedbackcontact') === '') {
            set_config('feedbackcontact', get_string('feedbackcontact_default', 'block_my_assessments'), 'block_my_assessments');
        }
        upgrade_block_savepoint(true, 2026100600, 'my_assessments');
    }

    if ($oldversion < 2026100800) {
        // Students who left keep their submitted work in marking (EC4); late joiners (EC10);
        // tagged activities without a deadline are listed as Undated (EC2).
        $table = new xmldb_table('block_my_assessments_stu');
        $field = new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'canaccess');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('enrolstart', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'active');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        \block_my_assessments\task\backfill::queue();
        upgrade_block_savepoint(true, 2026100800, 'my_assessments');
    }

    return true;
}
