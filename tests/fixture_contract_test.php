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

use block_le_dashboard\fixture\fixture;
use block_le_dashboard\fixture\registry;
use block_le_dashboard\local\adapter\registry as adapters;

/**
 * How a test writes an activity type is its fixture's to say (beta30, B1): the Behat steps and the rule tests go
 * through tests/generator/fixture/ and never name a plugin's tables, generators or classes themselves.
 *
 * The plugin's own PHPUnit tests of the adapters and the index (tests/*_test.php) are not held to this: they test
 * the reading of those tables, so they set them up directly.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\adapter\registry
 */
final class fixture_contract_test extends \advanced_testcase {
    /** @var string[] The test code held to the fixtures, relative to the plugin. */
    const HELD = ['tests/behat', 'tests/rules', 'tests/view_testcase.php'];

    /**
     * Loads the fixtures, which live beside the generator.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        \testing_util::get_data_generator()->get_plugin_generator('block_le_dashboard');
    }

    /**
     * Every type with an adapter of its own has a fixture of its own, and the other way round.
     */
    public function test_every_adapter_has_a_fixture(): void {
        $this->assertEqualsCanonicalizing(array_keys(adapters::OWN), registry::OWN);
        foreach (registry::OWN as $modname) {
            $this->assertInstanceOf(fixture::class, registry::for($modname));
            $this->assertSame($modname, registry::for($modname)->modname());
        }
        $this->assertSame('forum', registry::for('forum')->modname(), 'any other type gets the generic fixture');
    }

    /**
     * The generic fixture creates and dates any type, so a test can put any activity on the dashboard.
     */
    public function test_generic_fixture_creates_and_tags(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $due = time() + DAYSECS;
        $instance = registry::for('workshop')->create($course, ['summative'], 'Review', $due, 'tagged "extra"');
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $this->assertSame('workshop', $cm->modname);
        $this->assertEquals($due, $DB->get_field('workshop', 'submissionend', ['id' => $cm->instance]));
        $this->assertEqualsCanonicalizing(
            ['summative', 'extra'],
            \core_tag_tag::get_item_tags_array('core', 'course_modules', $cm->id)
        );
        $this->expectException(\coding_exception::class);
        registry::for('workshop')->submit($cm, 2);
    }

    /**
     * The Behat steps and the rule tests name no plugin table, generator or class: a string literal or class
     * name starting with assign, quiz, coursework or peerwork, other than the bare type name (which a step
     * passes to the registry) and the plugin's own language strings.
     */
    public function test_steps_and_rule_tests_write_only_through_the_fixtures(): void {
        global $CFG;
        $root = $CFG->dirroot . '/blocks/le_dashboard';
        $names = implode('|', registry::OWN);
        // A table ('assign_submission'), a generator or class ('mod_quiz', '\mod_coursework\models'), a plugin
        // function (quiz_add_quiz_question) or a plugin object (new assign).
        $pattern = '~^(\'|")?(\\\\?mod_(' . $names . ')|(' . $names . ')_[a-z]|\\\\?(' . $names . ')\\\\)~';
        $found = [];
        foreach (self::HELD as $held) {
            $files = is_dir("$root/$held") ? glob("$root/$held/*.php") : (is_file("$root/$held") ? ["$root/$held"] : []);
            foreach ($files as $file) {
                $path = substr($file, strlen($root) + 1);
                $kinds = [T_CONSTANT_ENCAPSED_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING];
                $tokens = token_get_all(file_get_contents($file));
                foreach ($tokens as $i => $token) {
                    if (!is_array($token) || !in_array($token[0], $kinds) || !preg_match($pattern, $token[1])) {
                        continue;
                    }
                    if ($token[0] === T_STRING && $tokens[$i + 1] !== '(') {
                        // A word, not a call.
                        continue;
                    }
                    if (preg_match('~^[\'"](' . $names . ')[\'"]$~', $token[1]) || str_contains($token[1], 'block_le_dashboard')) {
                        continue;
                    }
                    $found[] = "$path:{$token[2]} {$token[1]}";
                }
            }
        }
        $this->assertSame([], $found, "A test writes a plugin's data through its fixture only:\n" . implode("\n", $found));
    }
}
