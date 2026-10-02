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

namespace block_my_assessments\local\adapter;

/**
 * Which adapter serves which activity type, and which types the site has enabled (R5).
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
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
        $value = get_config('block_my_assessments', 'enabledtypes');
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
        set_config('enabledtypes', implode(',', $types), 'block_my_assessments');
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
            $failing = explode(',', (string)get_config('block_my_assessments', 'testfailtypes'));
            if (in_array($modname, $failing)) {
                throw new \moodle_exception('testfailure', 'block_my_assessments', '', $modname);
            }
        }
    }

    /**
     * Records an indexing error for an activity type, for the admin page.
     *
     * @param string $modname
     * @param \Throwable $e
     */
    public static function record_error(string $modname, \Throwable $e): void {
        set_config(
            'lasterror_' . $modname,
            json_encode(['time' => time(), 'message' => $e->getMessage()]),
            'block_my_assessments'
        );
    }

    /**
     * Clears the recorded error once a type indexes cleanly again.
     *
     * @param string $modname
     */
    public static function clear_error(string $modname): void {
        if (get_config('block_my_assessments', 'lasterror_' . $modname) !== false) {
            unset_config('lasterror_' . $modname, 'block_my_assessments');
        }
    }

    /**
     * The last indexing error for an activity type.
     *
     * @param string $modname
     * @return \stdClass|null time and message
     */
    public static function last_error(string $modname): ?\stdClass {
        $value = get_config('block_my_assessments', 'lasterror_' . $modname);
        return $value ? json_decode($value) : null;
    }
}
