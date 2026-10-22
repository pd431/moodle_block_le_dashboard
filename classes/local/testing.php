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

namespace block_le_dashboard\local;

/**
 * Every seam the tests reach into the plugin through, in one place (beta29, F9): the fault points the Behat
 * and PHPUnit scenarios switch on with config keys, the clock a scenario sets, and the stand-ins for plugins a
 * test site may not have. Outside a test run every method is a no-op, and no other class reads these keys.
 *
 * The config keys (testfail*, testdeadlock, behat*) keep their names: the Behat steps set them.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class testing {
    /**
     * Whether this is a test run: PHPUnit, or a Behat site (its command line process and the pages its
     * browser opens alike).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (defined('PHPUNIT_TEST') && PHPUNIT_TEST) || (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING);
    }

    /**
     * A config key of the plugin, read only in a test run.
     *
     * @param string $name
     * @return string
     */
    protected static function config(string $name): string {
        return self::enabled() ? (string)get_config('block_le_dashboard', $name) : '';
    }

    /**
     * Throws when a test asked this activity type to fail (testfailtypes; the "indexing fails" scenario).
     *
     * @param string $modname
     */
    public static function fail_type(string $modname): void {
        if (in_array($modname, explode(',', self::config('testfailtypes')))) {
            throw new \moodle_exception('testfailure', 'block_le_dashboard', '', $modname);
        }
    }

    /**
     * Throws when a test asked reading student rows, or marking rows, to fail for a list of students that
     * includes the one testfailstudent, or testfailmarking, names. A student of -1 fails every student.
     *
     * @param string $part 'student' or 'marking'
     * @param int[] $userids
     */
    public static function fail_part(string $part, array $userids): void {
        $student = (int)self::config('testfailstudent');
        if ($part === 'student' && $student && ($student === -1 || in_array($student, $userids))) {
            throw new \moodle_exception('testfailure', 'block_le_dashboard', '', 'student ' . $student);
        }
        $marking = (int)self::config('testfailmarking');
        if ($part === 'marking' && $marking && in_array($marking, $userids)) {
            throw new \moodle_exception('testfailure', 'block_le_dashboard', '', 'marking ' . $marking);
        }
    }

    /**
     * Whether writing the index for this assessment should fail, once, because testfailwrite names it. The
     * setting is cleared here, outside the write's transaction, so the rollback doesn't bring it back.
     *
     * @param int $cmid
     * @return bool
     */
    public static function fail_write(int $cmid): bool {
        if ((int)self::config('testfailwrite') === $cmid) {
            unset_config('testfailwrite', 'block_le_dashboard');
            return true;
        }
        return false;
    }

    /** @var array The testdeadlock config last read, and deadlocks left to meet. */
    protected static array $deadlock = ['', 0];

    /**
     * Whether writing an activity's rows should now meet a deadlock: testdeadlock is "cmid:n", for the next
     * n writes. Counted in memory, as the write's rollback undoes config changes.
     *
     * @param int $cmid
     * @return bool
     */
    public static function deadlock(int $cmid): bool {
        $config = self::config('testdeadlock');
        if ($config === '') {
            return false;
        }
        if ($config !== self::$deadlock[0]) {
            self::$deadlock = [$config, (int)(explode(':', $config)[1] ?? 0)];
        }
        if ((int)explode(':', $config)[0] !== $cmid || self::$deadlock[1] < 1) {
            return false;
        }
        self::$deadlock[1]--;
        return true;
    }

    /**
     * The time a Behat scenario set the dashboard's clock to (behatnow), or null for the real time.
     *
     * @return int|null
     */
    public static function now(): ?int {
        return (int)self::config('behatnow') ?: null;
    }

    /**
     * How many courses an index pass does before it counts as reaching its limit (behatindexcourses).
     *
     * @return int 0 for no such limit.
     */
    public static function course_limit(): int {
        return (int)self::config('behatindexcourses');
    }

    /**
     * The stand-in for block_rhb's library, for scenarios tagged @block_rhb on a site without it
     * (behatrhbstub): its path, with mod_coursework's model stood in for too where the site lacks it, so
     * block_rhb's self-certification link has something to take. Null when no scenario asked for it.
     *
     * @return string|null
     */
    public static function rhb_stub(): ?string {
        global $CFG;
        if (!self::config('behatrhbstub')) {
            return null;
        }
        if (!class_exists('\mod_coursework\models\coursework')) {
            require_once($CFG->dirroot . '/blocks/le_dashboard/tests/fixtures/coursework_stub.php');
        }
        return $CFG->dirroot . '/blocks/le_dashboard/tests/fixtures/rhb_stub.php';
    }

    /**
     * Whether a scenario has the block_rhb stand-in treat an activity as a coursework (behatcoursework, "the
     * block_rhb stand-in treats ... as a coursework"), so self-certification applies to it on a site
     * without mod_coursework.
     *
     * @param string $modname
     * @param int $instanceid
     * @return bool
     */
    public static function rhb_stub_coursework(string $modname, int $instanceid): bool {
        return self::config('behatrhbstub')
            && in_array("$modname:$instanceid", explode(',', self::config('behatcoursework')));
    }
}
