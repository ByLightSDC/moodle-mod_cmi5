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
 * Content Library management page for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('mod/cmi5:managelibrary', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$packageid = optional_param('packageid', 0, PARAM_INT);
$versionid = optional_param('versionid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

$PAGE->set_url('/mod/cmi5/library.php', ['search' => $search, 'page' => $page]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('contentlibrary', 'cmi5'));
$PAGE->set_heading(get_string('contentlibrary', 'cmi5'));

// Handle actions.
if ($action === 'delete' && $packageid && confirm_sesskey()) {
    try {
        \mod_cmi5\content_library::delete_package($packageid);
        \core\notification::success(get_string('library:packagedeleted', 'cmi5'));
    } catch (\moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
    redirect(new moodle_url('/mod/cmi5/library.php'));
}

if ($action === 'upload' && data_submitted() && confirm_sesskey()) {
    $title = optional_param('title', '', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    $profileid = optional_param('profileid', 0, PARAM_INT);

    if (!empty($_FILES['packagezip']['tmp_name'])) {
        try {
            // Store the uploaded file into the draft area, then process it.
            require_once($CFG->libdir . '/filelib.php');
            $usercontext = context_user::instance($USER->id);
            $draftitemid = random_int(1, 999999999);
            $fs = get_file_storage();

            $filerecord = [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => clean_filename($_FILES['packagezip']['name']),
            ];
            $fs->create_file_from_pathname($filerecord, $_FILES['packagezip']['tmp_name']);

            \mod_cmi5\content_library::upload_package_from_draft($draftitemid, $title, $description, $profileid);
            \core\notification::success(get_string('library:packageuploaded', 'cmi5'));
        } catch (\moodle_exception $e) {
            \core\notification::error($e->getMessage());
        }
    } else {
        \core\notification::error(get_string('required'));
    }
    redirect(new moodle_url('/mod/cmi5/library.php'));
}

// Upload new version of existing package.
if ($action === 'uploadversion' && $packageid && data_submitted() && confirm_sesskey()) {
    $profileid = optional_param('profileid', 0, PARAM_INT);

    if (!empty($_FILES['packagezip']['tmp_name'])) {
        try {
            require_once($CFG->libdir . '/filelib.php');
            $usercontext = context_user::instance($USER->id);
            $draftitemid = random_int(1, 999999999);
            $fs = get_file_storage();

            $filerecord = [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => clean_filename($_FILES['packagezip']['name']),
            ];
            $fs->create_file_from_pathname($filerecord, $_FILES['packagezip']['tmp_name']);

            $version = \mod_cmi5\content_library::upload_package_from_draft(
                $draftitemid, '', '', $profileid, $packageid
            );
            \core\notification::success(get_string('library:versionuploaded', 'cmi5', $version->versionnumber));
        } catch (\moodle_exception $e) {
            \core\notification::error($e->getMessage());
        }
    } else {
        \core\notification::error(get_string('required'));
    }
    redirect(new moodle_url('/mod/cmi5/library.php', ['action' => 'view', 'packageid' => $packageid]));
}

// Update external AU (new version).
if ($action === 'updateau' && $packageid && data_submitted() && confirm_sesskey()) {
    $title = required_param('autitle', PARAM_TEXT);
    $auid = required_param('auirid', PARAM_RAW);
    $url = required_param('auurl', PARAM_URL);
    $description = optional_param('audescription', '', PARAM_TEXT);
    $launchmethod = optional_param('aulaunchmethod', 'AnyWindow', PARAM_ALPHA);
    $moveoncriteria = optional_param('aumoveoncriteria', 'NotApplicable', PARAM_TEXT);
    $profileid = optional_param('profileid', 0, PARAM_INT);

    try {
        $version = \mod_cmi5\content_library::register_external_au(
            $title, $auid, $url, $description, $launchmethod, $moveoncriteria,
            null, null, $profileid, $packageid
        );
        \core\notification::success(get_string('library:versionuploaded', 'cmi5', $version->versionnumber));
    } catch (\moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
    redirect(new moodle_url('/mod/cmi5/library.php', ['action' => 'view', 'packageid' => $packageid]));
}

if ($action === 'registerau' && data_submitted() && confirm_sesskey()) {
    $title = required_param('autitle', PARAM_TEXT);
    $auid = required_param('auirid', PARAM_RAW);
    $url = required_param('auurl', PARAM_URL);
    $description = optional_param('audescription', '', PARAM_TEXT);
    $launchmethod = optional_param('aulaunchmethod', 'AnyWindow', PARAM_ALPHA);
    $moveoncriteria = optional_param('aumoveoncriteria', 'NotApplicable', PARAM_TEXT);
    $profileid = optional_param('profileid', 0, PARAM_INT);

    try {
        \mod_cmi5\content_library::register_external_au($title, $auid, $url, $description,
            $launchmethod, $moveoncriteria, null, null, $profileid);
        \core\notification::success(get_string('library:auregistered', 'cmi5'));
    } catch (\moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
    redirect(new moodle_url('/mod/cmi5/library.php'));
}

// View package details.
if ($action === 'view' && $packageid) {
    $package = \mod_cmi5\content_library::get_package_details($packageid, $versionid);

    echo $OUTPUT->header();

    $ausdata = [];
    foreach ($package->aus as $au) {
        $ausdata[] = [
            'title' => format_string($au->title),
            'auid' => $au->auid,
            'url' => $au->url,
            'launchmethod' => $au->launchmethod,
            'moveoncriteria' => $au->moveoncriteria,
            'masteryscore' => $au->masteryscore,
            'hasmasteryscore' => $au->masteryscore !== null,
            'isexternal' => !empty($au->isexternal),
        ];
    }

    $blocksdata = [];
    foreach ($package->blocks as $block) {
        $blocksdata[] = [
            'title' => format_string($block->title),
            'blockid' => $block->blockid,
        ];
    }

    $sourcestrings = [
        0 => get_string('library:source_zip', 'cmi5'),
        1 => get_string('library:source_external', 'cmi5'),
        2 => get_string('library:source_api', 'cmi5'),
    ];

    // Look up profile name if set.
    $profilename = '';
    if (!empty($package->profileid)) {
        $profile = $DB->get_record('cmi5_launch_profiles', ['id' => $package->profileid]);
        if ($profile) {
            $profilename = format_string($profile->name);
        }
    }

    // Load all versions for version history.
    $allversions = \mod_cmi5\content_library::get_package_versions($packageid);
    $versionsdata = [];
    foreach ($allversions as $ver) {
        $changelogentries = [];
        if (!empty($ver->changelog)) {
            $decoded = json_decode($ver->changelog, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $changelogentries[] = [
                        'description' => format_changelog_entry_for_display($entry),
                    ];
                }
            }
        }
        $versionsdata[] = [
            'id' => (int) $ver->id,
            'versionnumber' => (int) $ver->versionnumber,
            'source' => $sourcestrings[(int) $ver->source] ?? '',
            'usagecount' => (int) $ver->usagecount,
            'sha256hash' => $ver->sha256hash ? substr($ver->sha256hash, 0, 12) . '...' : '',
            'timecreated' => userdate($ver->timecreated),
            'haschangelog' => !empty($changelogentries),
            'changelog' => $changelogentries,
            'iscurrent' => ((int) $ver->id === (int) ($package->versionid ?? 0)),
            'viewurl' => (new moodle_url('/mod/cmi5/library.php', [
                'action' => 'view',
                'packageid' => $packageid,
                'versionid' => $ver->id,
            ]))->out(false),
        ];
    }

    // Determine if this is a ZIP package or external AU for the upload form.
    $iszip = ((int) ($package->source ?? 0) === \mod_cmi5\content_library::SOURCE_ZIP);
    $isexternal = ((int) ($package->source ?? 0) === \mod_cmi5\content_library::SOURCE_API);

    // Load launch profiles.
    $profiles = $DB->get_records('cmi5_launch_profiles', [], 'name ASC');
    $profilesdata = [];
    foreach ($profiles as $profile) {
        $profilesdata[] = [
            'id' => (int) $profile->id,
            'name' => format_string($profile->name),
        ];
    }

    $templatedata = [
        'title' => format_string($package->title),
        'description' => format_text($package->description ?? '', FORMAT_PLAIN),
        'courseid_iri' => $package->courseid_iri ?? '',
        'source' => $sourcestrings[(int) ($package->source ?? 0)] ?? '',
        'usagecount' => (int) ($package->usagecount ?? 0),
        'timecreated' => userdate($package->timecreated),
        'profilename' => $profilename,
        'hasprofile' => !empty($profilename),
        'versionnumber' => (int) ($package->versionnumber ?? 0),
        'versioncount' => count($allversions),
        'aus' => $ausdata,
        'hasaus' => !empty($ausdata),
        'blocks' => $blocksdata,
        'hasblocks' => !empty($blocksdata),
        'backurl' => (new moodle_url('/mod/cmi5/library.php'))->out(false),
        'versions' => $versionsdata,
        'hasversions' => !empty($versionsdata),
        'packageid' => $packageid,
        'iszip' => $iszip,
        'isexternal' => $isexternal,
        'uploadversionurl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'uploadversion',
            'packageid' => $packageid,
        ]))->out(false),
        'updateauurl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'updateau',
            'packageid' => $packageid,
        ]))->out(false),
        'sesskey' => sesskey(),
        'profiles' => $profilesdata,
        'hasprofiles' => !empty($profilesdata),
    ];

    echo $OUTPUT->render_from_template('mod_cmi5/library_package_detail', $templatedata);
    echo $OUTPUT->footer();
    exit;
}

// List packages.
echo $OUTPUT->header();

$packages = \mod_cmi5\content_library::list_packages($search, -1, $page * $perpage, $perpage);
$totalcount = \mod_cmi5\content_library::count_packages($search, -1);

$sourcestrings = [
    0 => get_string('library:source_zip', 'cmi5'),
    1 => get_string('library:source_external', 'cmi5'),
    2 => get_string('library:source_api', 'cmi5'),
];

$packagesdata = [];
foreach ($packages as $pkg) {
    // Get version count and latest version info.
    $versions = \mod_cmi5\content_library::get_package_versions((int) $pkg->id);
    $versioncount = count($versions);
    $latestversion = !empty($versions) ? reset($versions) : null;
    $source = $latestversion ? $sourcestrings[(int) $latestversion->source] ?? '' : '';
    $statusactive = $latestversion ? ((int) $latestversion->status === 1) : true;
    $usagecount = 0;
    foreach ($versions as $ver) {
        $usagecount += (int) $ver->usagecount;
    }

    $packagesdata[] = [
        'id' => (int) $pkg->id,
        'title' => format_string($pkg->title),
        'source' => $source,
        'statusactive' => $statusactive,
        'usagecount' => $usagecount,
        'versioncount' => $versioncount,
        'timecreated' => userdate($pkg->timecreated),
        'viewurl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'view', 'packageid' => $pkg->id]))->out(false),
        'deleteurl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'delete',
            'packageid' => $pkg->id,
            'sesskey' => sesskey(),
        ]))->out(false),
        'candelete' => ($usagecount === 0),
    ];
}

// Load launch profiles for the form dropdowns.
$profiles = $DB->get_records('cmi5_launch_profiles', [], 'name ASC');
$profilesdata = [];
foreach ($profiles as $profile) {
    $profilesdata[] = [
        'id' => (int) $profile->id,
        'name' => format_string($profile->name),
    ];
}

$templatedata = [
    'packages' => $packagesdata,
    'haspackages' => !empty($packagesdata),
    'search' => $search,
    'searchurl' => (new moodle_url('/mod/cmi5/library.php'))->out(false),
    'uploadurl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'upload']))->out(false),
    'registerauurl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'registerau']))->out(false),
    'sesskey' => sesskey(),
    'profiles' => $profilesdata,
    'hasprofiles' => !empty($profilesdata),
];

echo $OUTPUT->render_from_template('mod_cmi5/library', $templatedata);

// Pagination.
echo $OUTPUT->paging_bar($totalcount, $page, $perpage,
    new moodle_url('/mod/cmi5/library.php', ['search' => $search]));

echo $OUTPUT->footer();

/**
 * Format a changelog entry for display.
 *
 * @param array $entry Changelog entry.
 * @return string Human-readable description.
 */
function format_changelog_entry_for_display(array $entry): string {
    $type = $entry['type'] ?? '';
    $title = $entry['title'] ?? '';

    switch ($type) {
        case 'au_added':
            return get_string('library:auadded', 'cmi5', $title);
        case 'au_removed':
            return get_string('library:auremoved', 'cmi5', $title);
        case 'au_changed':
            $field = $entry['field'] ?? '';
            return get_string('library:auchanged', 'cmi5', (object) [
                'title' => $title,
                'field' => $field,
            ]);
        case 'block_added':
            return get_string('library:blockadded', 'cmi5', $title);
        case 'block_removed':
            return get_string('library:blockremoved', 'cmi5', $title);
        default:
            return $title;
    }
}
