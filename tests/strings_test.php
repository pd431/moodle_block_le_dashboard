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

namespace block_my_assessments;

use block_my_assessments\local\mitigation;
use block_my_assessments\local\scope;
use block_my_assessments\local\view\classifier;
use block_my_assessments\local\view\staff;
use block_my_assessments\local\view\student;
use block_my_assessments\privacy\provider;

/**
 * Every language string the block asks for exists, including keys built at runtime.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class strings_test extends \advanced_testcase {
    /** Section keys per view, as the views build them. */
    const SECTIONS = [
        scope::STUDENT => [classifier::OVERDUE, classifier::UPCOMING, classifier::PAST],
        scope::STAFF => [classifier::TOMARK, classifier::COMPLETED, classifier::STAFF_UPCOMING],
        scope::CONVENOR => [classifier::STAFF_UPCOMING, classifier::INPROGRESS, classifier::CONVENOR_PAST],
    ];

    /** Summary box keys per view. */
    const BOXES = [
        scope::STUDENT => ['overdue', 'upcoming', 'submitted', 'selfcert'],
        scope::STAFF => ['tomark', 'duesoon', 'marked'],
    ];

    /** Row date keys. */
    const DATES = ['due', 'feedbackexpected', 'markingdue', 'submissiondeadline'];

    /** Marking stages with their own label (markerN uses stage_markern). */
    const STAGES = ['marked', 'markern', 'agreed', 'moderated'];

    /**
     * The keys built by concatenation.
     *
     * @return string[]
     */
    protected static function built_keys(): array {
        $keys = [];
        foreach (self::SECTIONS as $view => $sections) {
            $keys[] = 'view_' . $view;
            $keys[] = 'empty_' . $view;
            $keys[] = 'searchplaceholder_' . $view;
            foreach ($sections as $section) {
                $keys[] = 'section_' . $view . '_' . $section;
            }
        }
        foreach (self::BOXES as $view => $boxes) {
            foreach ($boxes as $box) {
                $keys[] = 'box_' . $view . '_' . $box;
            }
        }
        foreach ((new \ReflectionClass(classifier::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_')) {
                $keys[] = 'status_' . $value;
            }
        }
        foreach (array_keys(staff::RANGES) as $range) {
            $keys[] = 'range_' . $range;
        }
        foreach (array_keys(student::OVERLAY_ICONS) as $state) {
            $keys[] = 'tip_' . $state;
        }
        foreach (self::DATES as $date) {
            $keys[] = 'date_' . $date;
        }
        foreach (self::STAGES as $stage) {
            $keys[] = 'stage_' . $stage;
        }
        foreach (['support_full', 'support_generic'] as $support) {
            $keys[] = $support;
            $keys[] = $support . '_desc';
        }
        foreach (provider::PREFERENCES as $preference) {
            $keys[] = 'privacy:metadata:preference:' . $preference;
        }
        foreach (array_keys(mitigation::SELFCERT_SETTINGS) as $setting) {
            $keys[] = $setting;
            $keys[] = $setting . '_desc';
        }
        return $keys;
    }

    /**
     * Built keys exist.
     */
    public function test_built_keys_exist(): void {
        $sm = get_string_manager();
        foreach (self::built_keys() as $key) {
            $this->assertTrue($sm->string_exists($key, 'block_my_assessments'), "Missing string '$key'");
        }
    }

    /**
     * Literal keys in PHP, templates and JS exist.
     */
    public function test_literal_keys_exist(): void {
        global $CFG;
        $sm = get_string_manager();
        $dir = $CFG->dirroot . '/blocks/my_assessments';
        $patterns = [
            '/get_string\(\s*\'([a-z0-9_:]+)\',\s*\'block_my_assessments\'/',
            '/lang_string\(\s*\'([a-z0-9_:]+)\',\s*\'block_my_assessments\'/',
            '/\{\{#str\}\}\s*([a-z0-9_:]+),\s*block_my_assessments/',
            '/getString\(\s*\'([a-z0-9_:]+)\',\s*\'block_my_assessments\'/',
        ];
        $found = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (
                !preg_match('/\.(php|mustache|js)$/', $file->getFilename()) || str_contains($file->getPathname(), '/amd/build/')
                    || str_contains($file->getPathname(), '/tests/')
            ) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach ($matches[1] as $key) {
                    $found++;
                    $this->assertTrue(
                        $sm->string_exists($key, 'block_my_assessments'),
                        "Missing string '$key' used in " . $file->getFilename()
                    );
                }
            }
        }
        $this->assertGreaterThan(50, $found, 'The scan found the block\'s string calls');
    }
}
