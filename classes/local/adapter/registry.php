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

namespace block_le_dashboard\local\adapter;

/**
 * Which adapter serves which activity type, and which types the site has enabled (R5).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /** Activity types with their own adapter. */
    const OWN = [
        'assign' => assign::class,
        'quiz' => quiz::class,
        'peerwork' => peerwork::class,
        'coursework' => coursework::class,
    ];

    /** Activity types never offered (R8: LTI is out of scope for now). */
    const EXCLUDED = ['lti'];

    /** Types enabled on a new install. */
    const DEFAULT_ENABLED = 'assign,quiz,peerwork,coursework';

    /**
     * Whether a module is installed and enabled on this site.
     *
     * @param string $modname
     * @return bool
     */
    public static function module_installed(string $modname): bool {
        $mods = \core_plugin_manager::instance()->get_enabled_plugins('mod') ?? [];
        return isset($mods[$modname]);
    }

    /**
     * The enabled activity types.
     *
     * @return string[]
     */
    public static function enabled_types(): array {
        $value = get_config('block_le_dashboard', 'enabledtypes');
        if ($value === false) {
            $value = self::DEFAULT_ENABLED;
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * Enables or disables an activity type.
     *
     * @param string $modname
     * @param bool $enabled
     */
    public static function set_enabled(string $modname, bool $enabled): void {
        $types = array_diff(self::enabled_types(), [$modname]);
        if ($enabled) {
            $types[] = $modname;
        }
        sort($types);
        set_config('enabledtypes', implode(',', $types), 'block_le_dashboard');
    }

    /**
     * The adapter for an activity type: its own, or the generic one.
     *
     * @param string $modname
     * @param bool $onlyenabled Return null for types the site has not enabled.
     * @return adapter|null
     */
    public static function adapter_for(string $modname, bool $onlyenabled = true): ?adapter {
        if (in_array($modname, self::EXCLUDED)) {
            return null;
        }
        if ($onlyenabled && !in_array($modname, self::enabled_types())) {
            return null;
        }
        if (isset(self::OWN[$modname])) {
            $class = self::OWN[$modname];
            return new $class();
        }
        return new generic($modname);
    }

    /**
     * Activity types the admin page lists: types with their own adapter that are
     * installed, and every other type with gradebook items on this site.
     *
     * @return string[] modname => support level
     */
    public static function listable_types(): array {
        global $DB;
        $types = [];
        foreach (array_keys(self::OWN) as $modname) {
            if (self::module_installed($modname)) {
                $types[$modname] = adapter::SUPPORT_FULL;
            }
        }
        $graded = $DB->get_fieldset_sql("SELECT DISTINCT itemmodule FROM {grade_items} WHERE itemtype = 'mod'");
        foreach ($graded as $modname) {
            if (!isset($types[$modname]) && !in_array($modname, self::EXCLUDED) && self::module_installed($modname)) {
                $types[$modname] = adapter::SUPPORT_GENERIC;
            }
        }
        return $types;
    }

    /**
     * Throws when tests asked this type to fail (the "indexing fails" scenario).
     *
     * @param string $modname
     */
    public static function check_test_failure(string $modname): void {
        if ((defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            $failing = explode(',', (string)get_config('block_le_dashboard', 'testfailtypes'));
            if (in_array($modname, $failing)) {
                throw new \moodle_exception('testfailure', 'block_le_dashboard', '', $modname);
            }
        }
    }

    /**
     * Behat and PHPUnit only: fails reading student rows that include the student the
     * testfailstudent setting names, or reading marking rows when testfailmarking is set.
     *
     * @param string $part 'student' or 'marking'
     * @param int[] $userids
     */
    public static function check_test_part_failure(string $part, array $userids): void {
        if ((defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            $student = (int)get_config('block_le_dashboard', 'testfailstudent');
            if ($part === 'student' && $student && in_array($student, $userids)) {
                throw new \moodle_exception('testfailure', 'block_le_dashboard', '', 'student ' . $student);
            }
            if ($part === 'marking' && get_config('block_le_dashboard', 'testfailmarking')) {
                throw new \moodle_exception('testfailure', 'block_le_dashboard', '', 'marking');
            }
        }
    }

    /**
     * Behat and PHPUnit only: whether writing the index for this assessment should fail, once,
     * because the testfailwrite setting names it. The setting is cleared here, outside the write's
     * transaction, so the rollback doesn't bring it back.
     *
     * @param int $cmid
     * @return bool
     */
    public static function take_test_write_failure(int $cmid): bool {
        $testing = (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) || (defined('BEHAT_TEST') && BEHAT_TEST)
            || (defined('PHPUNIT_TEST') && PHPUNIT_TEST);
        if ($testing && (int)get_config('block_le_dashboard', 'testfailwrite') === $cmid) {
            unset_config('testfailwrite', 'block_le_dashboard');
            return true;
        }
        return false;
    }

    /** @var array Errors recorded in this run, by activity type, written once when the run ends (PR14). */
    protected static $errors = [];

    /**
     * Records an indexing error for an activity type, for the admin page. A run (a task, a
     * Refresh) keeps them in memory and stores one entry per type, with a count, when it ends.
     * Scheduled and ad hoc tasks also print it to the task log.
     *
     * @param string $modname
     * @param \Throwable $e
     */
    public static function record_error(string $modname, \Throwable $e): void {
        if (!self::$errors) {
            \core_shutdown_manager::register_function([self::class, 'flush_errors']);
        }
        self::$errors[$modname] = (object)[
            'time' => time(),
            'message' => $e->getMessage(),
            'count' => (self::$errors[$modname]->count ?? 0) + 1,
        ];
        if (CLI_SCRIPT && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            mtrace("block_le_dashboard: indexing $modname failed: " . $e->getMessage());
        }
    }

    /**
     * Stores the errors recorded in this run: one config write per failing type.
     */
    public static function flush_errors(): void {
        foreach (self::$errors as $modname => $error) {
            set_config('lasterror_' . $modname, json_encode($error), 'block_le_dashboard');
        }
        self::$errors = [];
    }

    /**
     * Clears the recorded error once a type indexes cleanly again, unless it also failed in this run.
     *
     * @param string $modname
     */
    public static function clear_error(string $modname): void {
        if (!isset(self::$errors[$modname]) && get_config('block_le_dashboard', 'lasterror_' . $modname) !== false) {
            unset_config('lasterror_' . $modname, 'block_le_dashboard');
        }
    }

    /**
     * The last indexing error for an activity type: this run's, or the stored one.
     *
     * @param string $modname
     * @return \stdClass|null time, message and count
     */
    public static function last_error(string $modname): ?\stdClass {
        if (isset(self::$errors[$modname])) {
            return self::$errors[$modname];
        }
        $value = get_config('block_le_dashboard', 'lasterror_' . $modname);
        return $value ? json_decode($value) : null;
    }
}
