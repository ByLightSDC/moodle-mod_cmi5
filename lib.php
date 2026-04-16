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
 * Library functions for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the information on whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed true if supported, null if unknown
 */
function cmi5_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_GROUPS:
            return false;
        default:
            return null;
    }
}

/**
 * Add a cmi5 activity instance.
 *
 * @param stdClass $data Form data
 * @param mod_cmi5_mod_form $mform
 * @return int New instance ID
 */
function cmi5_add_instance($data, $mform = null) {
    global $DB;

    // Preserve the draft file ID before cleaning data for DB insert.
    $draftitemid = $data->packagefile ?? 0;
    $packagesource = $data->packagesource ?? 'upload';
    $packageid = !empty($data->packageid) ? (int) $data->packageid : null;
    $libraryauid = $data->libraryauid ?? '';

    $data->timecreated = time();
    $data->timemodified = time();

    // Set defaults from admin settings.
    if (empty($data->lrsendpoint)) {
        $data->lrsendpoint = get_config('mod_cmi5', 'defaultlrsendpoint') ?: '';
    }
    if (empty($data->lrskey)) {
        $data->lrskey = get_config('mod_cmi5', 'defaultlrskey') ?: '';
    }
    if (empty($data->lrssecret)) {
        $data->lrssecret = get_config('mod_cmi5', 'defaultlrssecret') ?: '';
    }
    if (!isset($data->lrsmode)) {
        $data->lrsmode = get_config('mod_cmi5', 'defaultlrsmode') ?: 0;
    }
    if (empty($data->sessiontimeout)) {
        $data->sessiontimeout = get_config('mod_cmi5', 'defaultsessiontimeout') ?: 3600;
    }

    // Build a clean record with only DB columns.
    $record = new stdClass();
    $record->course = $data->course;
    $record->name = $data->name;
    $record->intro = $data->intro ?? '';
    $record->introformat = $data->introformat ?? FORMAT_HTML;
    $record->grademethod = $data->grademethod ?? 0;
    $record->maxgrade = $data->maxgrade ?? 100;
    $record->launchmethod = $data->launchmethod ?? 0;
    $record->sessiontimeout = $data->sessiontimeout;
    $record->lrsendpoint = $data->lrsendpoint;
    $record->lrskey = $data->lrskey;
    $record->lrssecret = $data->lrssecret;
    $record->lrsmode = $data->lrsmode;
    $record->launchparameters = $data->launchparameters ?? null;
    $record->profileid = !empty($data->profileid) ? (int) $data->profileid : null;
    $record->packageid = $packageid;
    $record->timecreated = $data->timecreated;
    $record->timemodified = $data->timemodified;

    $data->id = $DB->insert_record('cmi5', $record);

    if ($packagesource === 'library' && $packageid) {
        // Resolve packageid to its latestversion.
        $libpackage = \mod_cmi5\content_library::get_package($packageid);
        $versionid = $libpackage ? (int) $libpackage->latestversion : 0;

        // Auto-inherit profile from library package version if not explicitly set on the form.
        if (empty($record->profileid) && $versionid) {
            $version = \mod_cmi5\content_library::get_version($versionid);
            if ($version && !empty($version->profileid)) {
                $record->profileid = (int) $version->profileid;
                $DB->set_field('cmi5', 'profileid', $record->profileid, ['id' => $data->id]);
            }
        }

        // Set packageversionid on the activity.
        if ($versionid) {
            $DB->set_field('cmi5', 'packageversionid', $versionid, ['id' => $data->id]);
        }

        // Parse the AU selection: format is "packageid:auid" or empty for all.
        $singleauid = null;
        if (!empty($libraryauid) && strpos($libraryauid, ':') !== false) {
            [, $singleauid] = explode(':', $libraryauid, 2);
            $singleauid = (int) $singleauid;
        }
        // Copy structure from library package version to activity.
        if ($versionid) {
            \mod_cmi5\content_library::copy_structure_to_activity($versionid, $data->id, $singleauid);
            \mod_cmi5\content_library::increment_usage($versionid);

            // Copy courseid_iri from the package version.
            if (!empty($version->courseid_iri)) {
                $DB->set_field('cmi5', 'courseid_iri', $version->courseid_iri, ['id' => $data->id]);
            }
        }
    } else if ($mform && !empty($draftitemid)) {
        // Handle direct file upload and package parsing.
        $cmid = $data->coursemodule;
        $context = context_module::instance($cmid);

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_cmi5',
            'package',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.zip']]
        );

        $package = new \mod_cmi5\cmi5_package($context, $data->id);
        $package->process_uploaded_package();
    }

    cmi5_grade_item_update($data);

    return $data->id;
}

/**
 * Update a cmi5 activity instance.
 *
 * @param stdClass $data Form data
 * @param mod_cmi5_mod_form $mform
 * @return bool
 */
function cmi5_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $draftitemid = $data->packagefile ?? 0;

    // Build a clean record with only DB columns.
    $record = new stdClass();
    $record->id = $data->id;
    $record->name = $data->name;
    $record->intro = $data->intro ?? '';
    $record->introformat = $data->introformat ?? FORMAT_HTML;
    $record->grademethod = $data->grademethod ?? 0;
    $record->maxgrade = $data->maxgrade ?? 100;
    $record->launchmethod = $data->launchmethod ?? 0;
    $record->sessiontimeout = $data->sessiontimeout ?? 3600;
    $record->lrsendpoint = $data->lrsendpoint ?? '';
    $record->lrskey = $data->lrskey ?? '';
    $record->lrssecret = $data->lrssecret ?? '';
    $record->lrsmode = $data->lrsmode ?? 0;
    $record->launchparameters = $data->launchparameters ?? null;
    $record->profileid = !empty($data->profileid) ? (int) $data->profileid : null;
    $record->timemodified = time();

    $DB->update_record('cmi5', $record);

    // Handle sync to selected version if requested.
    $syncversion = (int) ($data->syncversion ?? 0);
    if ($syncversion > 0) {
        $cmi5 = $DB->get_record('cmi5', ['id' => $data->id]);
        if (!empty($cmi5->packageid)) {
            \mod_cmi5\content_library::sync_activity_to_version($data->id, $syncversion);
        }
    }

    // Handle file re-upload if changed.
    if ($mform && !empty($draftitemid)) {
        $cmid = $data->coursemodule;
        $context = context_module::instance($cmid);

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_cmi5',
            'package',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.zip']]
        );

        // Re-parse the package.
        $package = new \mod_cmi5\cmi5_package($context, $data->id);
        $package->process_uploaded_package();
    }

    cmi5_grade_item_update($data);

    return true;
}

/**
 * Delete a cmi5 activity instance.
 *
 * @param int $id Instance ID
 * @return bool
 */
function cmi5_delete_instance($id) {
    global $DB;

    $cmi5 = $DB->get_record('cmi5', ['id' => $id]);
    if (!$cmi5) {
        return false;
    }

    // Delete all related data.
    $registrations = $DB->get_records('cmi5_registrations', ['cmi5id' => $id]);
    foreach ($registrations as $reg) {
        // Delete sessions and their tokens/statements.
        $sessions = $DB->get_records('cmi5_sessions', ['registrationid' => $reg->id]);
        foreach ($sessions as $session) {
            $DB->delete_records('cmi5_tokens', ['sessionid' => $session->id]);
            $DB->delete_records('cmi5_statements', ['sessionid' => $session->id]);
        }
        $DB->delete_records('cmi5_sessions', ['registrationid' => $reg->id]);
        $DB->delete_records('cmi5_au_status', ['registrationid' => $reg->id]);
        $DB->delete_records('cmi5_block_status', ['registrationid' => $reg->id]);
        $DB->delete_records('cmi5_state_documents', ['registrationid' => $reg->id]);
    }
    $DB->delete_records('cmi5_registrations', ['cmi5id' => $id]);
    $DB->delete_records('cmi5_aus', ['cmi5id' => $id]);
    $DB->delete_records('cmi5_blocks', ['cmi5id' => $id]);

    // Delete files.
    $cm = get_coursemodule_from_instance('cmi5', $id);
    if ($cm) {
        $context = context_module::instance($cm->id);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_cmi5');
    }

    // Decrement library usage count if linked to a package version.
    if (!empty($cmi5->packageversionid)) {
        \mod_cmi5\content_library::decrement_usage((int) $cmi5->packageversionid);
    }

    $DB->delete_records('cmi5', ['id' => $id]);

    cmi5_grade_item_delete($cmi5);

    return true;
}

/**
 * Create/update grade item for given cmi5 activity.
 *
 * @param stdClass $cmi5 Activity instance
 * @param mixed $grades Optional grades
 * @return int GRADE_UPDATE_OK etc.
 */
function cmi5_grade_item_update($cmi5, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $cmi5->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => $cmi5->maxgrade ?? 100,
        'grademin' => 0,
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/cmi5', $cmi5->course, 'mod', 'cmi5', $cmi5->id, 0, $grades, $params);
}

/**
 * Delete grade item for given cmi5 activity.
 *
 * @param stdClass $cmi5 Activity instance
 * @return int
 */
function cmi5_grade_item_delete($cmi5) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if (!$cmi5) {
        return GRADE_UPDATE_OK;
    }

    return grade_update('mod/cmi5', $cmi5->course, 'mod', 'cmi5', $cmi5->id, 0, null, ['deleted' => 1]);
}

/**
 * Return grade for given user.
 *
 * @param stdClass $cmi5
 * @param int $userid
 * @return stdClass|null
 */
function cmi5_get_user_grades($cmi5, $userid = 0) {
    $grademanager = new \mod_cmi5\grade_manager($cmi5);
    return $grademanager->get_user_grades($userid);
}

/**
 * Update grades in the gradebook.
 *
 * @param stdClass $cmi5
 * @param int $userid
 * @param bool $nullifnone
 */
function cmi5_update_grades($cmi5, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grades = cmi5_get_user_grades($cmi5, $userid);
    if ($grades) {
        cmi5_grade_item_update($cmi5, $grades);
    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        cmi5_grade_item_update($cmi5, [$userid => $grade]);
    } else {
        cmi5_grade_item_update($cmi5);
    }
}

/**
 * Serves file from mod_cmi5 file areas.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if file not found
 */
function cmi5_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;

    // Library content served from SYSTEM context.
    if ($context->contextlevel == CONTEXT_SYSTEM && $filearea === 'library_content') {
        require_login();
        // First arg is the packageid (itemid), rest is the file path.
        $itemid = (int) array_shift($args);
        $relativepath = implode('/', $args);
        $fullpath = "/{$context->id}/mod_cmi5/library_content/{$itemid}/{$relativepath}";

        $fs = get_file_storage();
        $file = $fs->get_file_by_hash(sha1($fullpath));
        if (!$file || $file->is_directory()) {
            return false;
        }

        // config.json may be patched (e.g. class mode toggle) so don't cache immutably.
        $basename = basename($relativepath);
        if (strtolower($basename) !== 'config.json') {
            $options['immutable'] = true;
        }

        send_stored_file($file, null, 0, $forcedownload, $options);
        return;
    }

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if ($filearea === 'package') {
        require_capability('mod/cmi5:managecontent', $context);
        $revision = (int) array_shift($args);
        $relativepath = implode('/', $args);
        $fullpath = "/{$context->id}/mod_cmi5/package/0/{$relativepath}";

    } else if ($filearea === 'content') {
        require_capability('mod/cmi5:view', $context);
        // First arg is the itemid (cmi5 instance id), rest is the file path.
        $itemid = (int) array_shift($args);
        $relativepath = implode('/', $args);
        $fullpath = "/{$context->id}/mod_cmi5/content/{$itemid}/{$relativepath}";
        $options['immutable'] = true;

    } else {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}

/**
 * Extend course navigation with cmi5 node.
 *
 * @param navigation_node $navref
 * @param stdClass $course
 * @param stdClass $module
 * @param cm_info $cm
 */
function cmi5_extend_navigation(navigation_node $navref, stdClass $course, stdClass $module, cm_info $cm) {
    // No custom navigation nodes needed.
}

/**
 * Extend settings navigation.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $cmi5node
 */
function cmi5_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $cmi5node) {
    global $PAGE;

    if (!$PAGE->cm) {
        return;
    }

    $context = context_module::instance($PAGE->cm->id);

    // Add Metrics tab for users who can view the activity.
    if (has_capability('mod/cmi5:view', $context)) {
        $metricsurl = new moodle_url('/mod/cmi5/view.php', [
            'id' => $PAGE->cm->id,
            'tab' => 'metrics',
        ]);
        $cmi5node->add(
            get_string('metrics:tab', 'cmi5'),
            $metricsurl,
            navigation_node::TYPE_SETTING
        );
    }
}

/**
 * Get extra capabilities for this module.
 *
 * @return array
 */
function cmi5_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * Called when viewing course page.
 *
 * @param cm_info $cm
 */
function cmi5_cm_info_view(cm_info $cm) {
    // No dynamic info on course page.
}
