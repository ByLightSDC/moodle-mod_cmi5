<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Admin settings for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Content Library link.
    $libraryurl = new moodle_url('/mod/cmi5/library.php');
    $settings->add(new admin_setting_heading(
        'mod_cmi5/libraryheading',
        get_string('contentlibrary', 'cmi5'),
        get_string('library:settingsdesc', 'cmi5', $libraryurl->out())
    ));

    // Launch Parameter Profiles link.
    $profilesurl = new moodle_url('/mod/cmi5/launch_profiles.php');
    $settings->add(new admin_setting_heading(
        'mod_cmi5/profilesheading',
        get_string('launchprofiles', 'cmi5'),
        get_string('launchprofiles_desc', 'cmi5', $profilesurl->out())
    ));

    // LRS defaults.
    $settings->add(new admin_setting_configtext(
        'mod_cmi5/defaultlrsendpoint',
        get_string('defaultlrsendpoint', 'cmi5'),
        get_string('defaultlrsendpoint_desc', 'cmi5'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'mod_cmi5/defaultlrskey',
        get_string('defaultlrskey', 'cmi5'),
        get_string('defaultlrskey_desc', 'cmi5'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_cmi5/defaultlrssecret',
        get_string('defaultlrssecret', 'cmi5'),
        get_string('defaultlrssecret_desc', 'cmi5'),
        ''
    ));

    $lrsmodeoptions = [
        0 => get_string('lrsmode_local', 'cmi5'),
        1 => get_string('lrsmode_forward', 'cmi5'),
        2 => get_string('lrsmode_lrsonly', 'cmi5'),
    ];
    $settings->add(new admin_setting_configselect(
        'mod_cmi5/defaultlrsmode',
        get_string('defaultlrsmode', 'cmi5'),
        get_string('defaultlrsmode_desc', 'cmi5'),
        0,
        $lrsmodeoptions
    ));

    $settings->add(new admin_setting_configtext(
        'mod_cmi5/defaultsessiontimeout',
        get_string('defaultsessiontimeout', 'cmi5'),
        get_string('defaultsessiontimeout_desc', 'cmi5'),
        3600,
        PARAM_INT
    ));

    $launchoptions = [
        0 => get_string('launchnewwindow', 'cmi5'),
        1 => get_string('launchiframe', 'cmi5'),
    ];
    $settings->add(new admin_setting_configselect(
        'mod_cmi5/defaultlaunchmethod',
        get_string('defaultlaunchmethod', 'cmi5'),
        get_string('defaultlaunchmethod_desc', 'cmi5'),
        0,
        $launchoptions
    ));

    $settings->add(new admin_setting_configtext(
        'mod_cmi5/tokenexpiry',
        get_string('tokenexpiry', 'cmi5'),
        get_string('tokenexpiry_desc', 'cmi5'),
        3600,
        PARAM_INT
    ));

    // Standalone LRS settings.
    $settings->add(new admin_setting_heading(
        'mod_cmi5/lrsstandaloneheading',
        get_string('lrs_standalone', 'cmi5'),
        get_string('lrs_standalone_desc', 'cmi5')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_cmi5/lrs_enabled',
        get_string('lrs_enabled', 'cmi5'),
        get_string('lrs_enabled_desc', 'cmi5'),
        0
    ));

    // Auto-generate API key if not set.
    $existingkey = get_config('mod_cmi5', 'lrs_api_key');
    if (empty($existingkey)) {
        set_config('lrs_api_key', \mod_cmi5\lrs_auth::generate_key(), 'mod_cmi5');
    }

    $settings->add(new admin_setting_configtext(
        'mod_cmi5/lrs_api_key',
        get_string('lrs_api_key', 'cmi5'),
        get_string('lrs_api_key_desc', 'cmi5'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_cmi5/lrs_api_secret',
        get_string('lrs_api_secret', 'cmi5'),
        get_string('lrs_api_secret_desc', 'cmi5'),
        ''
    ));
}
