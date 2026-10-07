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

namespace block_le_dashboard\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add or edit an academic year.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class year extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'label', get_string('yearlabel', 'block_le_dashboard'), ['size' => 20]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', null, 'required', null, 'client');
        $mform->addElement('date_selector', 'timestart', get_string('yearstart', 'block_le_dashboard'));
        $mform->addHelpButton('timestart', 'yearstart', 'block_le_dashboard');
        $mform->addElement('date_selector', 'timeend', get_string('yearend', 'block_le_dashboard'));
        $mform->addHelpButton('timeend', 'yearend', 'block_le_dashboard');
        $this->add_action_buttons();
    }

    /**
     * The stored end (the first day after the year) to the last day the form shows.
     *
     * @param \stdClass $record
     */
    public function set_year(\stdClass $record): void {
        $this->set_data((object)(['timeend' => $record->timeend - 1] + (array)$record));
    }

    /**
     * The submitted year, its end stored as the first day after the last day entered.
     *
     * @return \stdClass|null
     */
    public function get_year(): ?\stdClass {
        $data = $this->get_data();
        if ($data) {
            $end = new \DateTime('@' . $data->timeend);
            $end->setTimezone(\core_date::get_user_timezone_object());
            $data->timeend = $end->modify('+1 day')->getTimestamp();
        }
        return $data;
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // The form shows the year's last day; the stored end is the day after (A11).
        if ($data['timeend'] < $data['timestart']) {
            $errors['timeend'] = get_string('yearendbeforestart', 'block_le_dashboard');
        }
        return $errors;
    }
}
