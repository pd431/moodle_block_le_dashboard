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
 * My assessments block.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_my_assessments extends block_base {
    /**
     * Sets the block title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_my_assessments');
    }

    #[\Override]
    public function has_config() {
        return true;
    }

    #[\Override]
    public function instance_allow_multiple() {
        return false;
    }

    #[\Override]
    public function applicable_formats() {
        return ['my' => true, 'site-index' => true, 'course-view' => false, 'mod' => false];
    }

    #[\Override]
    public function get_content() {
        global $USER, $OUTPUT;
        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = (object)['text' => '', 'footer' => ''];
        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }
        $dashboard = new \block_my_assessments\output\dashboard((int)$USER->id);
        $context = $dashboard->export_for_template($OUTPUT);
        $this->content->text = $OUTPUT->render_from_template('block_my_assessments/dashboard', $context);
        $this->page->requires->js_call_amd('block_my_assessments/dashboard', 'init', [
            $context['uniqid'], get_user_preferences('block_my_assessments_type', 'both'),
        ]);
        return $this->content;
    }
}
