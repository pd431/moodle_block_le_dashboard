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

use block_le_dashboard\local\testing;

/**
 * The test seams: one gate, named fault points, and no other class reading them.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\testing
 */
final class testing_test extends \advanced_testcase {
    /**
     * Only testing.php knows it is a test run: no other PHP under classes/ reads PHPUNIT_TEST or a Behat
     * constant, or a testfail, testdeadlock or behat config key.
     */
    public function test_only_testing_reads_the_seams(): void {
        global $CFG;
        $root = $CFG->dirroot . '/blocks/le_dashboard/classes';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $found = [];
        foreach ($files as $file) {
            $path = 'classes/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (!str_ends_with($path, '.php') || $path === 'classes/local/testing.php') {
                continue;
            }
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                $constant = $token[0] === T_STRING && preg_match('~^(PHPUNIT_TEST|BEHAT_SITE_RUNNING|BEHAT_TEST)$~', $token[1]);
                $key = $token[0] === T_CONSTANT_ENCAPSED_STRING
                    && preg_match('~^[\'"](testfail(?!ure)|testdeadlock|behat)~', $token[1]);
                $seam = $constant || $key;
                if ($seam) {
                    $found[] = "$path:{$token[2]} {$token[1]}";
                }
            }
        }
        $this->assertSame([], $found, "Only testing.php reads the test seams:\n" . implode("\n", $found));
    }

    /**
     * The fault points fire from their config keys, which keep the names the Behat steps set.
     */
    public function test_fault_points(): void {
        $this->resetAfterTest();
        $this->assertTrue(testing::enabled());
        testing::fail_type('assign');
        testing::fail_part('student', [1, 2]);
        $this->assertFalse(testing::fail_write(5));
        $this->assertFalse(testing::deadlock(5));
        $this->assertNull(testing::now());
        $this->assertSame(0, testing::course_limit());
        $this->assertNull(testing::rhb_stub());
        $this->assertFalse(testing::rhb_stub_coursework('assign', 1));

        set_config('testfailtypes', 'assign,quiz', 'block_le_dashboard');
        set_config('testfailstudent', 2, 'block_le_dashboard');
        set_config('testfailmarking', 3, 'block_le_dashboard');
        set_config('testfailwrite', 5, 'block_le_dashboard');
        set_config('testdeadlock', '5:2', 'block_le_dashboard');
        set_config('behatnow', 1790848800, 'block_le_dashboard');
        set_config('behatindexcourses', 2, 'block_le_dashboard');
        set_config('behatrhbstub', 1, 'block_le_dashboard');
        set_config('behatcoursework', 'assign:1', 'block_le_dashboard');

        testing::fail_type('forum');
        $this->assert_throws(fn() => testing::fail_type('quiz'), 'quiz');
        testing::fail_part('student', [1]);
        testing::fail_part('marking', [2]);
        $this->assert_throws(fn() => testing::fail_part('student', [1, 2]), 'student 2');
        $this->assert_throws(fn() => testing::fail_part('marking', [3]), 'marking 3');
        $this->assertFalse(testing::fail_write(4));
        $this->assertTrue(testing::fail_write(5));
        $this->assertFalse(testing::fail_write(5), 'once');
        $this->assertFalse(testing::deadlock(4));
        $this->assertTrue(testing::deadlock(5));
        $this->assertTrue(testing::deadlock(5));
        $this->assertFalse(testing::deadlock(5), 'twice');
        $this->assertSame(1790848800, testing::now());
        $this->assertSame(2, testing::course_limit());
        $this->assertStringEndsWith('/tests/fixtures/rhb_stub.php', testing::rhb_stub());
        $this->assertTrue(testing::rhb_stub_coursework('assign', 1));
        $this->assertFalse(testing::rhb_stub_coursework('assign', 2));
    }

    /**
     * Asserts a call throws the plugin's test failure naming something.
     *
     * @param callable $call
     * @param string $what
     */
    protected function assert_throws(callable $call, string $what): void {
        try {
            $call();
            $this->fail("no failure for $what");
        } catch (\moodle_exception $e) {
            $this->assertSame('testfailure', $e->errorcode);
            $this->assertSame($what, $e->a);
        }
    }
}
