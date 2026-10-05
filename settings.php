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
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$hidden = $block->is_enabled() === false;
$category = new admin_category(
    'block_le_dashboard_category',
    new lang_string('pluginname', 'block_le_dashboard'),
    $hidden
);
$ADMIN->add('blocksettings', $category);

$settings = new admin_settingpage(
    $section,
    new lang_string('settings', 'block_le_dashboard'),
    'moodle/site:config',
    $hidden
);

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'block_le_dashboard/tagsheading',
        new lang_string('tagsheading', 'block_le_dashboard'),
        new lang_string('tagsheading_desc', 'block_le_dashboard')
    ));
    // Changing what is indexed re-indexes everything in the background, with the Preparing banner (PR5).
    $reindex = 'block_le_dashboard\\task\\backfill::setting_changed';
    foreach (['certaintags' => 'summativetags', 'formativetags' => 'formativetags'] as $name => $string) {
        $setting = new admin_setting_configtext(
            'block_le_dashboard/' . $name,
            new lang_string($string, 'block_le_dashboard'),
            new lang_string($string . '_desc', 'block_le_dashboard'),
            '',
            PARAM_TEXT
        );
        $setting->set_updatedcallback($reindex);
        $settings->add($setting);
    }

    $settings->add(new admin_setting_heading(
        'block_le_dashboard/rolesheading',
        new lang_string('rolesheading', 'block_le_dashboard'),
        new lang_string('rolesheading_desc', 'block_le_dashboard')
    ));
    $roles = [];
    foreach (role_fix_names(get_all_roles(), context_system::instance(), ROLENAME_ORIGINAL) as $role) {
        $roles[$role->shortname] = $role->localname;
    }
    // As in rc6, the convenor role defaults to "moduleconvenor" where the site has one.
    $roledefaults = [
        'studentrole' => ['student'],
        'staffrole' => ['editingteacher', 'teacher'],
        'moduleconvenorrole' => ['moduleconvenor'],
    ];
    foreach ($roledefaults as $name => $defaults) {
        $setting = new admin_setting_configmultiselect(
            'block_le_dashboard/' . $name,
            new lang_string($name, 'block_le_dashboard'),
            new lang_string($name . '_desc', 'block_le_dashboard'),
            array_values(array_intersect($defaults, array_keys($roles))),
            $roles
        );
        $setting->set_updatedcallback($reindex);
        $settings->add($setting);
    }

    $settings->add(new admin_setting_heading(
        'block_le_dashboard/displayheading',
        new lang_string('displayheading', 'block_le_dashboard'),
        ''
    ));
    foreach (['overduetopastthreshold' => 90, 'inlinerefreshlimit' => 30] as $name => $default) {
        $settings->add(new admin_setting_configtext(
            'block_le_dashboard/' . $name,
            new lang_string($name, 'block_le_dashboard'),
            new lang_string($name . '_desc', 'block_le_dashboard'),
            $default,
            PARAM_INT
        ));
    }
    $settings->add(new admin_setting_configtext(
        'block_le_dashboard/briefguidanceurl',
        new lang_string('briefguidanceurl', 'block_le_dashboard'),
        new lang_string('briefguidanceurl_desc', 'block_le_dashboard'),
        '',
        PARAM_URL
    ));
    foreach (\block_le_dashboard\local\config::NUMBERS as $name => $default) {
        $settings->add(new admin_setting_configtext(
            'block_le_dashboard/' . $name,
            new lang_string($name, 'block_le_dashboard'),
            new lang_string($name . '_desc', 'block_le_dashboard'),
            $default,
            PARAM_INT
        ));
    }
    $settings->add(new admin_setting_configtext(
        'block_le_dashboard/feedbackcontact',
        new lang_string('feedbackcontact', 'block_le_dashboard'),
        new lang_string('feedbackcontact_desc', 'block_le_dashboard'),
        get_string('feedbackcontact_default', 'block_le_dashboard'),
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'block_le_dashboard/feedbackcontacturl',
        new lang_string('feedbackcontacturl', 'block_le_dashboard'),
        new lang_string('feedbackcontacturl_desc', 'block_le_dashboard'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configtext(
        'block_le_dashboard/latenote',
        new lang_string('latenote', 'block_le_dashboard'),
        new lang_string('latenote_desc', 'block_le_dashboard'),
        get_string('latenote_default', 'block_le_dashboard'),
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'block_le_dashboard/latenoteurl',
        new lang_string('latenoteurl', 'block_le_dashboard'),
        new lang_string('latenoteurl_desc', 'block_le_dashboard'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'block_le_dashboard/selfcertheading',
        new lang_string('selfcertheading', 'block_le_dashboard'),
        new lang_string('selfcertheading_desc', 'block_le_dashboard')
    ));
    foreach (\block_le_dashboard\local\mitigation::SELFCERT_SETTINGS as $name => $default) {
        $settings->add(new admin_setting_configtext(
            'block_le_dashboard/' . $name,
            new lang_string($name, 'block_le_dashboard'),
            new lang_string($name . '_desc', 'block_le_dashboard'),
            $default,
            PARAM_TEXT
        ));
    }

    $years = [0 => new lang_string('defaultacademicyear_current', 'block_le_dashboard')];
    if (during_initial_install() === false && $DB->get_manager()->table_exists('block_le_dashboard_year')) {
        foreach (\block_le_dashboard\local\years::all() as $year) {
            $years[$year->id] = format_string($year->label, true, ['escape' => false]);
        }
    }
    $settings->add(new admin_setting_configselect(
        'block_le_dashboard/defaultacademicyear',
        new lang_string('defaultacademicyear', 'block_le_dashboard'),
        new lang_string('defaultacademicyear_desc', 'block_le_dashboard'),
        0,
        $years
    ));
}
$ADMIN->add('block_le_dashboard_category', $settings);
foreach (['types' => 'activitytypes', 'years' => 'academicyears'] as $page => $string) {
    $ADMIN->add('block_le_dashboard_category', new admin_externalpage(
        'block_le_dashboard_' . $page,
        new lang_string($string, 'block_le_dashboard'),
        new moodle_url('/blocks/le_dashboard/admin/' . $page . '.php'),
        'moodle/site:config',
        $hidden
    ));
}

// The page is added to the category above, so the block's default page is not wanted.
$settings = null;
