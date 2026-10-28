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

namespace block_le_dashboard\local\view;

use block_le_dashboard\local\config;
use block_le_dashboard\local\genericname;
use block_le_dashboard\local\output\format;
use block_le_dashboard\local\scope;

/**
 * One builder for the rows of the staff and convenor views (beta29, F3): the row, its dates, its bars and its
 * flags in one fixed order, whichever view draws it. What differs between views is passed in as flags.
 *
 * The student view keeps its own row (student::row()): its shape differs (stacked meta, overlays, Mitigate).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class staff_row {
    /**
     * A staff or convenor row: format::row() with dates (submission deadline, marking deadline), bars, hints,
     * the View action and the not-loaded flag.
     *
     * The hints come in one order: no online submission, check settings, no brief, generic name, unallocated,
     * work came back, extensions to come, academic conduct flags. Each shows only when its switch is on and the
     * row calls for it.
     *
     * @param \stdClass $cm Index record.
     * @param string $course The course's short name.
     * @param string $view scope::STAFF or scope::CONVENOR.
     * @param array $bars The row's bars, already built.
     * @param array $flags What this row calls for:
     *     course (callable): rewrites the course text, with its year, for a row outside the selected year (EC3);
     *     undated (bool): no dates (an Undated row);
     *     brief (bool): say when the activity has no brief or description (convenor);
     *     unallocated (int): units unallocated where I convene (staff);
     *     returned (bool): work arrived after marking was finished (G6);
     *     pending (\stdClass|null): students with extensions still to come (marking_rules::pending_extensions());
     *     flagged (\stdClass|null): units flagged for an academic conduct check, open and withheld;
     *     nostudents (bool): no student's data was read, for the not-loaded note (format::load_error()).
     * @return array
     */
    public static function build(\stdClass $cm, string $course, string $view, array $bars, array $flags = []): array {
        $row = format::row($cm, $course, $view);
        if (!empty($flags['course'])) {
            $row['course'] = $flags['course']($row['course']);
        }
        $row['dates'] = empty($flags['undated']) ? array_values(array_filter([
            $cm->due ? format::date('submissiondeadline', (int)$cm->due) : null,
            format::markingdue($row, $cm),
        ])) : [];
        $row['bars'] = $bars;
        $row['hints'] = self::hints($row['id'], $cm, $view, $flags);
        $row['actions'] = [format::view_action($row['url'])];
        return format::load_error($row, $cm, !empty($flags['nostudents']));
    }

    /**
     * The row's hints, in order.
     *
     * @param string $rowid
     * @param \stdClass $cm
     * @param string $view
     * @param array $flags As build().
     * @return array
     */
    protected static function hints(string $rowid, \stdClass $cm, string $view, array $flags): array {
        $hints = [];
        if (!empty($cm->nosubmission) && config::shown('hint_nosubmission')) {
            $hints[] = format::nosubmission_hint($rowid, true);
        }
        if (config::shown('hint_setup') && $setup = format::setup_hint($rowid, (int)$cm->cmid, (string)$cm->setup)) {
            $hints[] = $setup;
        }
        if (!empty($flags['brief']) && empty($cm->hasbrief) && config::shown('hint_brief')) {
            $hints[] = format::hint($rowid, 'brief', get_string('briefhint_text', 'block_le_dashboard'));
        }
        if ($generic = genericname::hint($rowid, (string)$cm->name, (int)$cm->courseid)) {
            $hints[] = $generic;
        }
        $unallocated = (int)($flags['unallocated'] ?? 0);
        if ($unallocated && config::shown('hint_unallocated')) {
            $hints[] = format::hint(
                $rowid,
                'unallocated',
                get_string(
                    $unallocated === 1 ? 'hint_unallocated_one' : 'hint_unallocated_many',
                    'block_le_dashboard',
                    $unallocated
                ),
                null,
                get_string('hint_unallocated', 'block_le_dashboard', $unallocated),
                $unallocated
            );
        }
        if (!empty($flags['returned']) && config::shown('hint_returned')) {
            // The marker's wording or the convenor's.
            $text = get_string('hint_returned_' . ($view === scope::CONVENOR ? 'convenor' : 'marker'), 'block_le_dashboard');
            $hints[] = format::hint($rowid, 'returned', $text);
        }
        if (!empty($flags['pending']) && config::shown('hint_extensions')) {
            $hints[] = format::extensions_hint($rowid, $flags['pending']);
        }
        if (!empty($flags['flagged'])) {
            $hints[] = format::plagiarism_hint($rowid, (int)$flags['flagged']->open, (int)$flags['flagged']->withheld);
        }
        return $hints;
    }
}
