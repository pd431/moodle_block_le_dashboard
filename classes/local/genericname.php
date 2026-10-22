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

use block_le_dashboard\local\output\format;

/**
 * The generic name hint: an assessment named only with generic words ("Essay 1",
 * "CW2 - Summative", the module's own name) gets a hint for staff suggesting a descriptive name.
 *
 * A name is generic when every word in it is a listed term, a number, a roman numeral up to x, or a word of the
 * course's full or short name, alone or with digits after it ("CW2", "Part3"). Case and punctuation don't count.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class genericname {
    /** The default terms, one per line. */
    const TERMS = "assessment\nassignment\ncoursework\ncw\nessay\nreport\npresentation\nexam\ntest\nquiz\nproject\n" .
        "portfolio\nsubmission\ntask\npart\nfinal\nmidterm\nmid-term\nformative\nsummative\nindividual\ngroup\n" .
        "one\ntwo\nthree\nfour\nfive\nfirst\nsecond\nthird";

    /** Roman numerals counted as numbers. */
    const ROMAN = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];

    /**
     * Whether the hint is on (the genericnames setting, config::HINTS).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return config::shown('genericnames');
    }

    /**
     * The hint for a row, or null when it's off or the name says what the assessment is.
     *
     * @param string $rowid
     * @param string $name The assessment's name.
     * @param int $courseid
     * @return array|null format::hint()
     */
    public static function hint(string $rowid, string $name, int $courseid): ?array {
        if (!self::enabled() || !self::is_generic($name, self::course_words($courseid))) {
            return null;
        }
        $text = trim((string)get_config('block_le_dashboard', 'genericnametext'));
        $text = $text !== '' ? $text : get_string('genericnametext_default', 'block_le_dashboard');
        return format::hint($rowid, 'genericname', $text);
    }

    /**
     * Whether a name is made only of generic words.
     *
     * @param string $name
     * @param string[] $coursewords Words of the course's names, which count as generic too.
     * @return bool
     */
    public static function is_generic(string $name, array $coursewords = []): bool {
        $words = self::words($name);
        if (!$words) {
            return false;
        }
        $generic = array_flip(array_merge(self::terms(), $coursewords, self::ROMAN));
        foreach ($words as $word) {
            // A number, a generic word, or a generic word with a number after it ("CW2").
            $stem = preg_replace('/\d+$/', '', $word);
            if ($stem !== '' && !isset($generic[$word]) && !isset($generic[$stem])) {
                return false;
            }
        }
        return true;
    }

    /**
     * The generic terms: the genericterms setting, or the default.
     *
     * @return string[]
     */
    public static function terms(): array {
        $setting = get_config('block_le_dashboard', 'genericterms');
        return self::words($setting === false ? self::TERMS : (string)$setting);
    }

    /**
     * The words of a text: letters and digits, lower case.
     *
     * @param string $text
     * @return string[]
     */
    public static function words(string $text): array {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text)), 'strlen'));
    }

    /**
     * The words of a course's full and short names, once per request.
     *
     * @param int $courseid
     * @return string[]
     */
    protected static function course_words(int $courseid): array {
        return memo::get('genericname:' . $courseid, function () use ($courseid) {
            global $DB;
            $course = $DB->get_record('course', ['id' => $courseid], 'fullname, shortname');
            return $course ? self::words(strip_tags($course->fullname . ' ' . $course->shortname)) : [];
        });
    }
}
