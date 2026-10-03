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
 * Admin settings: Plugins > Blocks > My assessments, with Settings, Activity types and Academic years.
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$hidden = $block->is_enabled() === false;
$category = new admin_category(
    'block_my_assessments_category',
    new lang_string('pluginname', 'block_my_assessments'),
    $hidden
);
$ADMIN->add('blocksettings', $category);

$settings = new admin_settingpage(
    $section,
    new lang_string('settings', 'block_my_assessments'),
    'moodle/site:config',
    $hidden
);

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'block_my_assessments/tagsheading',
        new lang_string('tagsheading', 'block_my_assessments'),
        new lang_string('tagsheading_desc', 'block_my_assessments')
    ));
    foreach (['certaintags' => 'summativetags', 'formativetags' => 'formativetags'] as $name => $string) {
        $settings->add(new admin_setting_configtext(
            'block_my_assessments/' . $name,
            new lang_string($string, 'block_my_assessments'),
            new lang_string($string . '_desc', 'block_my_assessments'),
            '',
            PARAM_TEXT
        ));
    }

    $settings->add(new admin_setting_heading(
        'block_my_assessments/rolesheading',
        new lang_string('rolesheading', 'block_my_assessments'),
        new lang_string('rolesheading_desc', 'block_my_assessments')
    ));
    $roles = [];
    foreach (role_fix_names(get_all_roles(), context_system::instance(), ROLENAME_ORIGINAL) as $role) {
        $roles[$role->shortname] = $role->localname;
    }
    $roledefaults = ['studentrole' => ['student'], 'staffrole' => ['editingteacher', 'teacher'], 'moduleconvenorrole' => []];
    foreach ($roledefaults as $name => $defaults) {
        $settings->add(new admin_setting_configmultiselect(
            'block_my_assessments/' . $name,
            new lang_string($name, 'block_my_assessments'),
            new lang_string($name . '_desc', 'block_my_assessments'),
            array_values(array_intersect($defaults, array_keys($roles))),
            $roles
        ));
    }

    $settings->add(new admin_setting_heading(
        'block_my_assessments/displayheading',
        new lang_string('displayheading', 'block_my_assessments'),
        ''
    ));
    foreach (['overduetopastthreshold' => 90, 'inlinerefreshlimit' => 30] as $name => $default) {
        $settings->add(new admin_setting_configtext(
            'block_my_assessments/' . $name,
            new lang_string($name, 'block_my_assessments'),
            new lang_string($name . '_desc', 'block_my_assessments'),
            $default,
            PARAM_INT
        ));
    }
    $settings->add(new admin_setting_configtext(
        'block_my_assessments/briefguidanceurl',
        new lang_string('briefguidanceurl', 'block_my_assessments'),
        new lang_string('briefguidanceurl_desc', 'block_my_assessments'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'block_my_assessments/selfcertheading',
        new lang_string('selfcertheading', 'block_my_assessments'),
        new lang_string('selfcertheading_desc', 'block_my_assessments')
    ));
    foreach (\block_my_assessments\local\mitigation::SELFCERT_SETTINGS as $name => $default) {
        $settings->add(new admin_setting_configtext(
            'block_my_assessments/' . $name,
            new lang_string($name, 'block_my_assessments'),
            new lang_string($name . '_desc', 'block_my_assessments'),
            $default,
            PARAM_TEXT
        ));
    }

    $years = [0 => new lang_string('defaultacademicyear_current', 'block_my_assessments')];
    if (during_initial_install() === false && $DB->get_manager()->table_exists('block_my_assessments_year')) {
        foreach (\block_my_assessments\local\years::all() as $year) {
            $years[$year->id] = format_string($year->label);
        }
    }
    $settings->add(new admin_setting_configselect(
        'block_my_assessments/defaultacademicyear',
        new lang_string('defaultacademicyear', 'block_my_assessments'),
        new lang_string('defaultacademicyear_desc', 'block_my_assessments'),
        0,
        $years
    ));
}
$ADMIN->add('block_my_assessments_category', $settings);
foreach (['types' => 'activitytypes', 'years' => 'academicyears'] as $page => $string) {
    $ADMIN->add('block_my_assessments_category', new admin_externalpage(
        'block_my_assessments_' . $page,
        new lang_string($string, 'block_my_assessments'),
        new moodle_url('/blocks/my_assessments/admin/' . $page . '.php'),
        'moodle/site:config',
        $hidden
    ));
}

// The page is added to the category above, so the block's default page is not wanted.
$settings = null;
