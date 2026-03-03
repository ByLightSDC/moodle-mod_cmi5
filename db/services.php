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
 * External functions and services for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_cmi5_get_launch_url' => [
        'classname' => 'mod_cmi5\external\get_launch_url',
        'description' => 'Get launch URL for an AU',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:launch',
    ],
    'mod_cmi5_get_au_status' => [
        'classname' => 'mod_cmi5\external\get_au_status',
        'description' => 'Get current AU status for a user',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:view',
    ],
    'mod_cmi5_library_upload_package' => [
        'classname' => 'mod_cmi5\external\library_upload_package',
        'description' => 'Upload a cmi5 package to the content library',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:managelibrary',
    ],
    'mod_cmi5_library_list_packages' => [
        'classname' => 'mod_cmi5\external\library_list_packages',
        'description' => 'List packages in the content library',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:managelibrary',
    ],
    'mod_cmi5_library_get_package' => [
        'classname' => 'mod_cmi5\external\library_get_package',
        'description' => 'Get package details including AUs and blocks',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:managelibrary',
    ],
    'mod_cmi5_library_delete_package' => [
        'classname' => 'mod_cmi5\external\library_delete_package',
        'description' => 'Delete a package from the content library',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:managelibrary',
    ],
    'mod_cmi5_library_register_external_au' => [
        'classname' => 'mod_cmi5\external\library_register_external_au',
        'description' => 'Register an external AU in the content library',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'mod/cmi5:managelibrary',
    ],
    'mod_cmi5_library_sync_activity' => [
        'classname' => 'mod_cmi5\external\library_sync_activity',
        'description' => 'Sync an activity to the latest (or specified) package version',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:managecontent',
    ],
    'mod_cmi5_get_metrics_overview' => [
        'classname' => 'mod_cmi5\external\get_metrics_overview',
        'description' => 'Get activity-wide metrics overview (KPIs, timeline, verb distribution)',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:view',
    ],
    'mod_cmi5_get_learner_progress' => [
        'classname' => 'mod_cmi5\external\get_learner_progress',
        'description' => 'Get per-learner progress summary or drill-down',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:viewreports',
    ],
    'mod_cmi5_get_au_analytics' => [
        'classname' => 'mod_cmi5\external\get_au_analytics',
        'description' => 'Get per-AU analytics (completion rates, scores, durations)',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:view',
    ],
    'mod_cmi5_delete_registration' => [
        'classname' => 'mod_cmi5\external\delete_registration',
        'description' => 'Delete a learner registration and all related data',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:managecontent',
    ],
    'mod_cmi5_reset_registration_state' => [
        'classname' => 'mod_cmi5\external\reset_registration_state',
        'description' => 'Reset a learner registration state (sessions, statements, state docs) without deleting the registration',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/cmi5:managecontent',
    ],
];
