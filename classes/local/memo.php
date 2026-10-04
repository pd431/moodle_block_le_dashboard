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

namespace block_my_assessments\local;

/**
 * Per-request memo for lookups that a page or a regrade repeats many times: role ids,
 * course scopes, the default year, and grade item to activity (perf review F6, F7).
 *
 * A request cache, so it never outlives the request, and test resets clear it.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class memo {
    /**
     * The value for a key, computed once per request.
     *
     * @param string $key
     * @param callable $compute Returns the value; false and null are kept too.
     * @return mixed
     */
    public static function get(string $key, callable $compute) {
        $cache = self::cache();
        $hit = $cache->get($key);
        if ($hit !== false) {
            return $hit['v'];
        }
        $value = $compute();
        $cache->set($key, ['v' => $value]);
        return $value;
    }

    /**
     * Forgets everything, for code that changes what it memoises within one request.
     */
    public static function reset(): void {
        self::cache()->purge();
    }

    /**
     * The request cache.
     *
     * @return \cache
     */
    protected static function cache(): \cache {
        return \cache::make_from_params(\cache_store::MODE_REQUEST, 'block_my_assessments', 'memo', [], ['simplekeys' => false]);
    }
}
