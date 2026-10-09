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
 * A stand-in for block_rhb's request and self-certification links, for Behat scenarios tagged @block_rhb on
 * sites without block_rhb. Loaded only on a Behat site (see mitigation::available()).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


if (!function_exists('block_rhb_get_srm_link')) {
    /**
     * The student request form link, or what block_rhb offers instead (behatrhbsrm).
     *
     * @return string
     */
    function block_rhb_get_srm_link(): string {
        if (get_config('block_le_dashboard', 'behatrhbsrm') === 'no link') {
            return html_writer::span('Requests are closed');
        }
        return html_writer::link(new moodle_url('/'), 'Request mitigating circumstances');
    }
}

if (!function_exists('block_rhb_get_self_certification_link')) {
    /**
     * The self-certification link for a coursework, or what block_rhb offers instead (behatrhbselfcert).
     *
     * @param object $coursework
     * @return string
     */
    function block_rhb_get_self_certification_link($coursework): string {
        switch (get_config('block_le_dashboard', 'behatrhbselfcert') ?: 'a link') {
            case 'a delete link':
                // Its address says delete, as block_rhb's does; following it is recorded (rhb_delete.php).
                return html_writer::link(
                    new moodle_url('/blocks/le_dashboard/tests/fixtures/rhb_delete.php'),
                    'Remove self-certification'
                );
            case 'a message only':
                return html_writer::span('You have used all your self-certifications');
            case 'nothing':
                return '';
            case 'an unreadable link':
                return html_writer::link('javascript:void(0)', 'Self-certify');
            default:
                return html_writer::link(new moodle_url('/', ['selfcert' => 1]), 'Self-certify');
        }
    }
}
