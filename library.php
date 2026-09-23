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

// Download the original ZIP uploaded for a specific package version.
if ($action === 'download') {
    if (!$packageid || !$versionid) {
        throw new invalid_parameter_exception('A package and version are required');
    }

    $archive = \mod_cmi5\content_library::get_version_archive($packageid, $versionid);
    send_stored_file($archive, 0, 0, true);
}

// Permanently delete an eligible package version. Confirmation submits by POST.
if ($action === 'deleteversion') {
    if (!$packageid || !$versionid || !data_submitted()) {
        throw new invalid_parameter_exception('A POST request with a package and version is required');
    }
    require_sesskey();

    $version = \mod_cmi5\content_library::get_version($versionid);
    \mod_cmi5\content_library::delete_version($packageid, $versionid);
    \core\notification::success(get_string('library:versiondeleted', 'cmi5', $version->versionnumber));
    redirect(new moodle_url('/mod/cmi5/library.php', [
        'action' => 'view',
        'packageid' => $packageid,
        'tab' => 'versions',
    ]));
}

// Confirmation page for permanent version deletion.
if ($action === 'confirmdelete') {
    if (!$packageid || !$versionid) {
        throw new invalid_parameter_exception('A package and version are required');
    }

    $status = \mod_cmi5\content_library::get_version_deletion_status($packageid, $versionid);
    if ($status->islatest) {
        throw new moodle_exception('library:versionislatest', 'cmi5');
    }
    if ($status->usagecount > 0) {
        $errorcode = $status->usagecount === 1 ? 'library:versioninuse_one' : 'library:versioninuse';
        throw new moodle_exception($errorcode, 'cmi5', '', $status->usagecount);
    }

    $confirmdata = (object) [
        'title' => format_string($status->package->title),
        'version' => (int) $status->version->versionnumber,
    ];
    $PAGE->set_url('/mod/cmi5/library.php', [
        'action' => 'confirmdelete',
        'packageid' => $packageid,
        'versionid' => $versionid,
    ]);
    $PAGE->set_title(get_string('library:deleteversionheading', 'cmi5', $confirmdata));

    $continueurl = new moodle_url('/mod/cmi5/library.php', [
        'action' => 'deleteversion',
        'packageid' => $packageid,
        'versionid' => $versionid,
        'sesskey' => sesskey(),
    ]);
    $cancelurl = new moodle_url('/mod/cmi5/library.php', [
        'action' => 'view',
        'packageid' => $packageid,
        'versionid' => $versionid,
        'tab' => 'versions',
    ]);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('library:deleteversionheading', 'cmi5', $confirmdata));
    echo $OUTPUT->confirm(
        get_string('library:deleteversionconfirm', 'cmi5', $confirmdata),
        $continueurl,
        $cancelurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Step one of adding a course: park the ZIP in a draft area and read it. Nothing is stored
// in the library here, so an admin can back out after seeing what the manifest declares.
if ($action === 'inspect' && data_submitted() && confirm_sesskey()) {
    $profileid = optional_param('profileid', 0, PARAM_INT);

    $addurl = new moodle_url('/mod/cmi5/library.php', ['action' => 'add']);

    if (empty($_FILES['packagezip']['tmp_name'])) {
        \core\notification::error(get_string('library:nofilechosen', 'cmi5'));
        redirect($addurl);
    }

    // The confirmation step reads the package. Parking it is all this step has to do, so a
    // large ZIP is not extracted twice before it is even shown.
    $draftitemid = cmi5_store_upload_in_draft('packagezip');

    redirect(new moodle_url('/mod/cmi5/library.php', [
        'action' => 'add',
        'step' => 'confirm',
        'draftitemid' => $draftitemid,
        'profileid' => $profileid,
    ]));
}

// Step two: the admin has seen the structure and confirmed it, so commit the draft.
if ($action === 'upload' && data_submitted() && confirm_sesskey()) {
    $title = optional_param('title', '', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    $profileid = optional_param('profileid', 0, PARAM_INT);
    $draftitemid = required_param('draftitemid', PARAM_INT);

    try {
        \mod_cmi5\content_library::upload_package_from_draft($draftitemid, $title, $description, $profileid);
        \core\notification::success(get_string('library:packageuploaded', 'cmi5'));
    } catch (\moodle_exception $e) {
        \core\notification::error($e->getMessage());
        redirect(new moodle_url('/mod/cmi5/library.php', ['action' => 'add']));
    }

    redirect(new moodle_url('/mod/cmi5/library.php'));
}

// Upload new version of existing package.
if ($action === 'uploadversion' && $packageid && data_submitted() && confirm_sesskey()) {
    $profileid = optional_param('profileid', 0, PARAM_INT);

    if (!empty($_FILES['packagezip']['tmp_name'])) {
        try {
            $draftitemid = cmi5_store_upload_in_draft('packagezip');
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

    $tab = optional_param('tab', 'structure', PARAM_ALPHA);
    if (!in_array($tab, ['structure', 'versions', 'usage'], true)) {
        $tab = 'structure';
    }

    $PAGE->set_url('/mod/cmi5/library.php', [
        'action' => 'view',
        'packageid' => $packageid,
        'versionid' => (int) $package->versionid,
        'tab' => $tab,
    ]);

    $sourcestrings = [
        \mod_cmi5\content_library::SOURCE_ZIP => get_string('library:source_zip', 'cmi5'),
        \mod_cmi5\content_library::SOURCE_EXTERNAL_URL => get_string('library:source_external', 'cmi5'),
        \mod_cmi5\content_library::SOURCE_API => get_string('library:source_api', 'cmi5'),
    ];

    $aucount = count($package->aus);
    $blockcount = count($package->blocks);

    // One ordered list of rows carrying the nesting from cmi5.xml.
    $structure = cmi5_build_structure_rows($package->blocks, $package->aus);

    // "assignable units in 2 blocks", sitting next to the AU count.
    if ($blockcount > 0) {
        $blocklabel = $blockcount === 1
            ? get_string('library:blockcountlabel_one', 'cmi5')
            : get_string('library:blockcountlabel', 'cmi5', $blockcount);
        $structuresummary = $aucount === 1
            ? get_string('library:structurecountblocks_one', 'cmi5', $blocklabel)
            : get_string('library:structurecountblocks', 'cmi5', $blocklabel);
    } else {
        $structuresummary = $aucount === 1
            ? get_string('library:structurecount_one', 'cmi5')
            : get_string('library:structurecount', 'cmi5');
    }

    // Look up profile name if set.
    $profilename = '';
    if (!empty($package->profileid)) {
        $profile = $DB->get_record('cmi5_launch_profiles', ['id' => $package->profileid]);
        if ($profile) {
            $profilename = format_string($profile->name);
        }
    }

    // Load all versions and their uploaders for the versions tab.
    $allversions = \mod_cmi5\content_library::get_package_versions($packageid);
    $uploaderids = array_values(array_unique(array_filter(array_map(static function($version) {
        return (int) $version->createdby;
    }, $allversions))));
    $uploaders = empty($uploaderids) ? [] : $DB->get_records_list('user', 'id', $uploaderids);

    $latestversionnumber = 0;
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

        $islatest = ((int) $ver->id === (int) $package->latestversion);
        if ($islatest) {
            $latestversionnumber = (int) $ver->versionnumber;
        }

        $versionusagecount = (int) $ver->usagecount;
        $candelete = !$islatest && $versionusagecount === 0;
        if ($islatest) {
            $deleteblockedreason = get_string('library:versionislatest', 'cmi5');
        } else if ($versionusagecount === 1) {
            $deleteblockedreason = get_string('library:versioninuse_one', 'cmi5');
        } else if ($versionusagecount > 1) {
            $deleteblockedreason = get_string('library:versioninuse', 'cmi5', $versionusagecount);
        } else {
            $deleteblockedreason = '';
        }

        $versionsdata[] = [
            'id' => (int) $ver->id,
            'versionnumber' => (int) $ver->versionnumber,
            'source' => $sourcestrings[(int) $ver->source] ?? '',
            'usagecount' => (int) $ver->usagecount,
            'hasusage' => (int) $ver->usagecount > 0,
            'usagelabel' => (int) $ver->usagecount === 1
                ? get_string('library:usagecount_activity', 'cmi5')
                : get_string('library:usagecount_activities', 'cmi5', $ver->usagecount),
            'sha256hash' => $ver->sha256hash ? substr($ver->sha256hash, 0, 12) . '...' : '',
            'timecreated' => userdate($ver->timecreated, get_string('strftimedatefullshort', 'core_langconfig')),
            'uploadername' => isset($uploaders[$ver->createdby])
                ? fullname($uploaders[$ver->createdby])
                : get_string('unknownuser'),
            'uploadedlabel' => get_string('library:uploadedon', 'cmi5', (object) [
                'date' => userdate($ver->timecreated, get_string('strftimedatefullshort', 'core_langconfig')),
                'user' => isset($uploaders[$ver->createdby])
                    ? fullname($uploaders[$ver->createdby])
                    : get_string('unknownuser'),
            ]),
            'haschangelog' => !empty($changelogentries),
            'changelog' => $changelogentries,
            'islatest' => $islatest,
            'isviewing' => ((int) $ver->id === (int) ($package->versionid ?? 0)),
            'viewurl' => (new moodle_url('/mod/cmi5/library.php', [
                'action' => 'view',
                'packageid' => $packageid,
                'versionid' => $ver->id,
            ]))->out(false),
            'versionsurl' => (new moodle_url('/mod/cmi5/library.php', [
                'action' => 'view',
                'packageid' => $packageid,
                'versionid' => $ver->id,
                'tab' => 'versions',
            ]))->out(false),
            'usageurl' => (new moodle_url('/mod/cmi5/library.php', [
                'action' => 'view',
                'packageid' => $packageid,
                'versionid' => $ver->id,
                'tab' => 'usage',
            ]))->out(false),
            'candownload' => ((int) $ver->source === \mod_cmi5\content_library::SOURCE_ZIP),
            'downloadurl' => (new moodle_url('/mod/cmi5/library.php', [
                'action' => 'download',
                'packageid' => $packageid,
                'versionid' => $ver->id,
            ]))->out(false),
            'candelete' => $candelete,
            'deleteblockedreason' => $deleteblockedreason,
            'deleteurl' => (new moodle_url('/mod/cmi5/library.php', [
                'action' => 'confirmdelete',
                'packageid' => $packageid,
                'versionid' => $ver->id,
            ]))->out(false),
        ];
    }

    $selecteduploader = isset($uploaders[$package->createdby])
        ? fullname($uploaders[$package->createdby])
        : get_string('unknownuser');

    $islatest = ((int) $package->versionid === (int) $package->latestversion);
    $latestversionurl = (new moodle_url('/mod/cmi5/library.php', [
        'action' => 'view',
        'packageid' => $packageid,
        'versionid' => (int) $package->latestversion,
        'tab' => $tab,
    ]))->out(false);

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

    $usagecount = (int) ($package->usagecount ?? 0);
    $versioncount = count($allversions);

    // Only the usage tab needs its rows, and only the page of them being looked at.
    $usagerows = [];
    if ($tab === 'usage') {
        $lastpage = max(0, (int) ceil($usagecount / $perpage) - 1);
        $page = max(0, min($page, $lastpage));
        $usagerows = cmi5_build_usage_rows((int) $package->versionid, $page, $perpage);
    }

    $templatedata = [
        'title' => format_string($package->title),
        'description' => format_text($package->description ?? '', FORMAT_PLAIN),
        'hasdescription' => trim((string) ($package->description ?? '')) !== '',
        'courseid_iri' => $package->courseid_iri ?? '',
        'source' => $sourcestrings[(int) ($package->source ?? 0)] ?? '',
        'usagecount' => $usagecount,
        'hasusage' => $usagecount > 0,
        'usagelabel' => $usagecount === 1
            ? get_string('library:usagecount_activity', 'cmi5')
            : get_string('library:usagecount_activities', 'cmi5', $usagecount),
        'timecreated' => userdate($package->versiontimecreated, get_string('strftimedatefullshort', 'core_langconfig')),
        'uploadername' => $selecteduploader,
        'islatest' => $islatest,
        'isolderversion' => !$islatest,
        'latestversionnumber' => $latestversionnumber,
        'latestversionurl' => $latestversionurl,
        'profilename' => $profilename,
        'hasprofile' => $profilename !== '',
        'versionnumber' => (int) ($package->versionnumber ?? 0),
        'versioncount' => $versioncount,
        'structure' => $structure,
        'hasstructure' => !empty($structure),
        'aucount' => $aucount,
        'blockcount' => $blockcount,
        'structuresummary' => $structuresummary,
        'uploadedlabel' => get_string('library:uploadedon', 'cmi5', (object) [
            'date' => userdate($package->versiontimecreated, get_string('strftimedatefullshort', 'core_langconfig')),
            'user' => $selecteduploader,
        ]),
        'olderversiontitle' => get_string('library:olderversiontitle', 'cmi5', (object) [
            'number' => (int) ($package->versionnumber ?? 0),
            'date' => userdate($package->versiontimecreated, get_string('strftimedatefullshort', 'core_langconfig')),
        ]),
        'hasblocks' => $blockcount > 0,
        'backurl' => (new moodle_url('/mod/cmi5/library.php'))->out(false),
        'versions' => $versionsdata,
        'hasversions' => !empty($versionsdata),
        'isstructuretab' => ($tab === 'structure'),
        'isversionstab' => ($tab === 'versions'),
        'isusagetab' => ($tab === 'usage'),
        'usages' => $usagerows,
        'structuretaburl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $packageid,
            'versionid' => (int) $package->versionid,
            'tab' => 'structure',
        ]))->out(false),
        'versionstaburl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $packageid,
            'versionid' => (int) $package->versionid,
            'tab' => 'versions',
        ]))->out(false),
        'usagetaburl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $packageid,
            'versionid' => (int) $package->versionid,
            'tab' => 'usage',
        ]))->out(false),
        'packageid' => $packageid,
        'iszip' => $iszip,
        'isexternal' => $isexternal,
        'canaddversion' => ($iszip || $isexternal),
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

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/library_package_detail', $templatedata);
    if ($tab === 'usage' && $usagecount > $perpage) {
        echo $OUTPUT->paging_bar($usagecount, $page, $perpage, new moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $packageid,
            'versionid' => (int) $package->versionid,
            'tab' => 'usage',
        ]));
    }
    echo $OUTPUT->footer();
    exit;
}

// Add a course: its own page, so the file chooser and the external AU form both have room,
// and so a ZIP can be read and shown before anything is committed.
if ($action === 'add') {
    $step = optional_param('step', '', PARAM_ALPHA);
    $source = optional_param('source', 'zip', PARAM_ALPHA);
    if ($source !== 'external') {
        $source = 'zip';
    }

    $backurl = new moodle_url('/mod/cmi5/library.php');
    $profiles = $DB->get_records('cmi5_launch_profiles', [], 'name ASC');
    $profilesdata = [];
    foreach ($profiles as $profile) {
        $profilesdata[] = [
            'id' => (int) $profile->id,
            'name' => format_string($profile->name),
        ];
    }

    // Step two: show what the manifest declares, straight off the draft file.
    if ($step === 'confirm') {
        $draftitemid = required_param('draftitemid', PARAM_INT);
        $profileid = optional_param('profileid', 0, PARAM_INT);

        $PAGE->set_url('/mod/cmi5/library.php', [
            'action' => 'add',
            'step' => 'confirm',
            'draftitemid' => $draftitemid,
        ]);
        $PAGE->set_title(get_string('library:confirmheading', 'cmi5'));

        try {
            $inspection = \mod_cmi5\content_library::inspect_draft_package($draftitemid);
        } catch (\moodle_exception $e) {
            // A missing draft means the upload is gone; anything else means the ZIP itself
            // is the problem, and saying which is the difference between "try again" and
            // "this package is broken".
            \core\notification::error($e->errorcode === 'packagenotfound'
                ? get_string('library:sessionexpired', 'cmi5')
                : get_string('library:parsefailed', 'cmi5', $e->getMessage()));
            redirect(new moodle_url('/mod/cmi5/library.php', ['action' => 'add']));
        }

        $structure = cmi5_build_structure_rows($inspection->blocks, $inspection->aus);

        // Units declared outside every block still launch; say so rather than look like a fault.
        $orphans = 0;
        foreach ($structure as $row) {
            if (!empty($row['isau']) && !empty($row['orphan'])) {
                $orphans++;
            }
        }

        $summary = cmi5_structure_summary($inspection->aucount, $inspection->blockcount);
        $existing = $inspection->existingpackage;

        $templatedata = [
            'backurl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'add']))->out(false),
            'cancelurl' => $backurl->out(false),
            'uploadurl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'upload']))->out(false),
            'sesskey' => sesskey(),
            'draftitemid' => $draftitemid,
            'profileid' => $profileid,
            'readoktitle' => get_string('library:readok', 'cmi5', $inspection->filename),
            'readmeta' => get_string('library:readokmeta', 'cmi5', (object) [
                'size' => display_size($inspection->filesize),
                'summary' => $inspection->aucount . ' ' . $summary,
            ]),
            'aucount' => $inspection->aucount,
            'structuresummary' => $summary,
            'courseid_iri' => $inspection->courseid,
            'title' => $inspection->coursetitle,
            'description' => $inspection->coursedescription,
            'structure' => $structure,
            'hasstructure' => !empty($structure),
            'hasorphans' => $orphans > 0,
            'orphannote' => $orphans === 1
                ? get_string('library:orphanunits_one', 'cmi5')
                : get_string('library:orphanunits', 'cmi5', $orphans),
            'isnewcourse' => ($existing === null),
            'existingversion' => $existing ? ((int) $existing->versionnumber + 1) : 0,
            'existinghelp' => $existing ? get_string('library:existingcoursehelp', 'cmi5', (object) [
                'title' => format_string($existing->title),
                'version' => (int) $existing->versionnumber + 1,
            ]) : '',
        ];

        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template('mod_cmi5/library_add_confirm', $templatedata);
        echo $OUTPUT->footer();
        exit;
    }

    $PAGE->set_url('/mod/cmi5/library.php', ['action' => 'add', 'source' => $source]);
    $PAGE->set_title(get_string('library:addcourse', 'cmi5'));

    $templatedata = [
        'backurl' => $backurl->out(false),
        'cancelurl' => $backurl->out(false),
        'isziptab' => ($source === 'zip'),
        'isexternaltab' => ($source === 'external'),
        'ziptaburl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'add',
            'source' => 'zip',
        ]))->out(false),
        'externaltaburl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'add',
            'source' => 'external',
        ]))->out(false),
        'inspecturl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'inspect']))->out(false),
        'registerauurl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'registerau']))->out(false),
        'sesskey' => sesskey(),
        'maxbytes' => display_size(get_max_upload_file_size($CFG->maxbytes)),
        'profiles' => $profilesdata,
        'hasprofiles' => !empty($profilesdata),
    ];

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/library_add', $templatedata);
    echo $OUTPUT->footer();
    exit;
}

// List courses.
$filter = optional_param('filter', 'all', PARAM_ALPHA);
if (!in_array($filter, ['all', 'inuse', 'unused'], true)) {
    $filter = 'all';
}

$PAGE->set_url('/mod/cmi5/library.php', [
    'search' => $search,
    'filter' => $filter,
    'page' => $page,
]);

// The filter counts and the summary totals all read from the same set, so it is fetched
// whole and sliced in PHP rather than queried once per tab.
$matching = \mod_cmi5\content_library::list_packages_with_meta($search, -1, 0, 0);

$sourcestrings = [
    \mod_cmi5\content_library::SOURCE_ZIP => get_string('library:source_zip', 'cmi5'),
    \mod_cmi5\content_library::SOURCE_EXTERNAL_URL => get_string('library:source_external', 'cmi5'),
    \mod_cmi5\content_library::SOURCE_API => get_string('library:source_api', 'cmi5'),
];

$inuse = [];
$unused = [];
$versionstotal = 0;
$activitiestotal = 0;
foreach ($matching as $pkg) {
    $versionstotal += (int) $pkg->versioncount;
    $activitiestotal += (int) $pkg->usagecount;
    if ((int) $pkg->usagecount > 0) {
        $inuse[] = $pkg;
    } else {
        $unused[] = $pkg;
    }
}

$allcount = count($matching);
$inusecount = count($inuse);
$unusedcount = count($unused);

switch ($filter) {
    case 'inuse':
        $filtered = $inuse;
        break;
    case 'unused':
        $filtered = $unused;
        break;
    default:
        $filtered = $matching;
}

$totalcount = count($filtered);
$lastpage = max(0, (int) ceil($totalcount / $perpage) - 1);
$page = max(0, min($page, $lastpage));
$rows = array_slice($filtered, $page * $perpage, $perpage);

// One lookup for every uploader named on this page.
$uploaderids = array_values(array_unique(array_filter(array_map(static function($pkg) {
    return (int) $pkg->createdby;
}, $rows))));
$uploaders = empty($uploaderids) ? [] : $DB->get_records_list('user', 'id', $uploaderids);

$packagesdata = [];
foreach ($rows as $pkg) {
    $usagecount = (int) $pkg->usagecount;
    $versioncount = (int) $pkg->versioncount;
    $uploader = $uploaders[(int) $pkg->createdby] ?? null;

    // A package whose latest version pointer is broken still lists, so fall back to its own
    // creation date rather than printing the epoch.
    $created = (int) ($pkg->versiontimecreated ?? 0) ?: (int) $pkg->timecreated;

    $metadata = (object) [
        'source' => $sourcestrings[(int) $pkg->source] ?? '',
        'versions' => $versioncount === 1
            ? get_string('library:versioncountlabel_one', 'cmi5')
            : get_string('library:versioncountlabel', 'cmi5', $versioncount),
        'date' => userdate($created, get_string('strftimedatefullshort')),
        'user' => $uploader ? fullname($uploader) : '',
    ];

    $packagesdata[] = [
        'id' => (int) $pkg->id,
        'title' => format_string($pkg->title),
        'versionnumber' => (int) $pkg->versionnumber,
        'statusactive' => ((int) $pkg->status === \mod_cmi5\content_library::STATUS_ACTIVE),
        'meta' => $uploader
            ? get_string('library:rowmeta', 'cmi5', $metadata)
            : get_string('library:rowmetanouser', 'cmi5', $metadata),
        'hasusage' => ($usagecount > 0),
        'usagelabel' => cmi5_usage_label($usagecount),
        'usageurl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $pkg->id,
            'versionid' => $pkg->versionid,
            'tab' => 'usage',
        ]))->out(false),
        'viewurl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $pkg->id,
        ]))->out(false),
        'deleteurl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'delete',
            'packageid' => $pkg->id,
            'sesskey' => sesskey(),
        ]))->out(false),
        'deleteconfirm' => get_string('library:deleteconfirm', 'cmi5', format_string($pkg->title)),
        'candelete' => ($usagecount === 0),
    ];
}

// With a search running, the headline counts matches; otherwise it counts the whole library.
$hassearch = ($search !== '');
$isempty = ($allcount === 0 && !$hassearch);

$filterurl = static function(string $name) use ($search): string {
    $params = ['filter' => $name];
    if ($search !== '') {
        $params['search'] = $search;
    }
    return (new moodle_url('/mod/cmi5/library.php', $params))->out(false);
};

$templatedata = [
    'addurl' => (new moodle_url('/mod/cmi5/library.php', ['action' => 'add']))->out(false),
    'addexternalurl' => (new moodle_url('/mod/cmi5/library.php', [
        'action' => 'add',
        'source' => 'external',
    ]))->out(false),
    'isempty' => $isempty,
    'packagecount' => $allcount,
    'packagecountlabel' => $hassearch
        ? ($allcount === 1
            ? get_string('library:searchcountlabel_one', 'cmi5', $search)
            : get_string('library:searchcountlabel', 'cmi5', $search))
        : ($allcount === 1
            ? get_string('library:librarycountlabel_one', 'cmi5')
            : get_string('library:librarycountlabel', 'cmi5')),
    'hastotals' => !$hassearch,
    'librarytotallabel' => \mod_cmi5\content_library::count_packages('', -1) . ' '
        . get_string('library:librarycountlabel', 'cmi5'),
    'versionstotal' => $versionstotal === 1
        ? get_string('library:totalversionsvalue_one', 'cmi5')
        : get_string('library:totalversionsvalue', 'cmi5', $versionstotal),
    'inuselabel' => $inusecount === 0
        ? get_string('library:inusenone', 'cmi5')
        : ($inusecount === 1
            ? get_string('library:inusevalue_onecourse', 'cmi5', (object) [
                'activities' => cmi5_usage_label($activitiestotal),
            ])
            : get_string('library:inusevalue', 'cmi5', (object) [
                'activities' => cmi5_usage_label($activitiestotal),
                'courses' => $inusecount,
            ])),
    'unusedlabel' => $unusedcount === 0
        ? get_string('library:unusednone', 'cmi5')
        : ($unusedcount === 1
            ? get_string('library:unusedvalue_one', 'cmi5')
            : get_string('library:unusedvalue', 'cmi5', $unusedcount)),
    'search' => $search,
    'hassearch' => $hassearch,
    'filter' => ($filter === 'all') ? '' : $filter,
    'searchurl' => (new moodle_url('/mod/cmi5/library.php'))->out(false),
    'clearurl' => (new moodle_url('/mod/cmi5/library.php', ['filter' => $filter]))->out(false),
    'isalltab' => ($filter === 'all'),
    'isinusetab' => ($filter === 'inuse'),
    'isunusedtab' => ($filter === 'unused'),
    'taballurl' => $filterurl('all'),
    'tabinuseurl' => $filterurl('inuse'),
    'tabunusedurl' => $filterurl('unused'),
    'allcount' => $allcount,
    'inusecount' => $inusecount,
    'unusedcount' => $unusedcount,
    'packages' => $packagesdata,
    'hasresults' => !empty($packagesdata),
    'nomatchestitle' => $hassearch
        ? get_string('library:nomatches', 'cmi5', $search)
        : get_string('library:emptyheading', 'cmi5'),
    'nomatcheshelp' => get_string('library:nomatcheshelp', 'cmi5'),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_cmi5/library', $templatedata);

// Pagination.
echo $OUTPUT->paging_bar($totalcount, $page, $perpage,
    new moodle_url('/mod/cmi5/library.php', ['search' => $search, 'filter' => $filter]));

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

/**
 * Build the ordered template rows for a course structure.
 *
 * Shared by the package detail page and the add flow's confirmation step, so what an admin
 * approves before a package is stored is drawn by the same code that shows it afterwards.
 *
 * @param array $blocks Block records, each with id, title, blockid, parentblockid, sortorder.
 * @param array $aus AU records, each with id, title, auid, url and the launch fields.
 * @return array Ordered rows, each flagged isblock or isau.
 */
function cmi5_build_structure_rows(array $blocks, array $aus): array {
    // Block titles, so an AU can name the block it belongs to.
    $blocktitles = [];
    foreach ($blocks as $block) {
        $blocktitles[(int) $block->id] = format_string($block->title);
    }

    $blockcount = count($blocks);
    $structure = [];

    foreach (\mod_cmi5\content_library::build_structure_tree($blocks, $aus) as $row) {
        $record = $row['record'];
        $depth = (int) $row['depth'];

        if ($row['type'] === 'block') {
            $blockaucount = (int) $row['aucount'];
            $structure[] = [
                'isblock' => true,
                'rowid' => 'cmi5-block-' . (int) $record->id,
                'title' => format_string($record->title),
                'blockid' => $record->blockid,
                // The course is level 0, so top-level blocks begin at level 1.
                'indent' => ($depth + 1) * 12,
                'aucountlabel' => $blockaucount === 1
                    ? get_string('library:blockunitcount_one', 'cmi5')
                    : get_string('library:blockunitcount', 'cmi5', $blockaucount),
            ];
            continue;
        }

        $parenttitle = $blocktitles[(int) ($record->parentblockid ?? 0)] ?? '';
        $hasmastery = $record->masteryscore !== null && $record->masteryscore !== '';
        $description = format_text($record->description ?? '', FORMAT_PLAIN);

        $structure[] = [
            'isau' => true,
            'rowid' => 'cmi5-au-' . (int) $record->id,
            'detailid' => 'cmi5-au-detail-' . (int) $record->id,
            'index' => (int) $row['index'],
            'title' => format_string($record->title),
            'hasdescription' => trim($description) !== '',
            'description' => $description,
            'auid' => $record->auid,
            'url' => $record->url,
            'launchmethod' => $record->launchmethod,
            'moveoncriteria' => $record->moveoncriteria,
            'hasmasteryscore' => $hasmastery,
            // The column holds 7 decimals; show them all rather than rounding a pass threshold.
            'masteryscore' => $hasmastery ? format_float((float) $record->masteryscore, 7, true, true) : '',
            'haslaunchparameters' => !empty($record->launchparameters),
            'launchparameters' => $record->launchparameters,
            'hasentitlementkey' => !empty($record->entitlementkey),
            'entitlementkey' => $record->entitlementkey,
            'isexternal' => !empty($record->isexternal),
            // Only worth flagging when the package has blocks at all.
            'orphan' => ($depth === 0 && $blockcount > 0),
            // A top-level AU belongs to the course; nested AUs sit one level below their block.
            'indent' => ($depth + 1) * 12,
            'hasparent' => $parenttitle !== '',
            'parenttitle' => $parenttitle,
        ];
    }

    return $structure;
}

/**
 * Phrase an AU count as "assignable units in 2 blocks".
 *
 * @param int $aucount Number of assignable units.
 * @param int $blockcount Number of blocks they sit in.
 * @return string The label shown beside the count.
 */
function cmi5_structure_summary(int $aucount, int $blockcount): string {
    if ($blockcount > 0) {
        $blocklabel = $blockcount === 1
            ? get_string('library:blockcountlabel_one', 'cmi5')
            : get_string('library:blockcountlabel', 'cmi5', $blockcount);
        return $aucount === 1
            ? get_string('library:structurecountblocks_one', 'cmi5', $blocklabel)
            : get_string('library:structurecountblocks', 'cmi5', $blocklabel);
    }

    return $aucount === 1
        ? get_string('library:structurecount_one', 'cmi5')
        : get_string('library:structurecount', 'cmi5');
}

/**
 * Phrase a usage count as "6 activities".
 *
 * @param int $count Number of activities.
 * @return string The label.
 */
function cmi5_usage_label(int $count): string {
    return $count === 1
        ? get_string('library:usagecount_activity', 'cmi5')
        : get_string('library:usagecount_activities', 'cmi5', $count);
}

/**
 * Build the rows for a version's usage tab: one per activity pointing at the version.
 *
 * A course or module can disappear underneath a usage record, and a link is only offered
 * where the viewer can actually follow it, so each row carries what it was able to resolve.
 *
 * @param int $versionid The package version.
 * @param int $page Zero-based page number.
 * @param int $perpage Rows per page.
 * @return array Template rows.
 */
function cmi5_build_usage_rows(int $versionid, int $page, int $perpage): array {
    $records = \mod_cmi5\content_library::get_version_usage($versionid, $page * $perpage, $perpage);
    $rows = [];

    foreach ($records as $usage) {
        $courseexists = !empty($usage->coursename);
        $cmexists = !empty($usage->cmid);
        $courseurl = '';
        $activityurl = '';

        if ($courseexists) {
            $coursecontext = context_course::instance((int) $usage->courseid, IGNORE_MISSING);
            if ($coursecontext && has_capability('moodle/course:view', $coursecontext)) {
                $courseurl = (new moodle_url('/course/view.php', [
                    'id' => $usage->courseid,
                ]))->out(false);
            }
        }

        if ($cmexists) {
            $modulecontext = context_module::instance((int) $usage->cmid, IGNORE_MISSING);
            if ($modulecontext && has_capability('mod/cmi5:view', $modulecontext)) {
                $activityurl = (new moodle_url('/mod/cmi5/view.php', [
                    'id' => $usage->cmid,
                ]))->out(false);
            }
        }

        $rows[] = [
            'coursename' => $courseexists ? format_string($usage->coursename) : '',
            'courseexists' => $courseexists,
            'courseurl' => $courseurl,
            'hascourseurl' => $courseurl !== '',
            'coursevisible' => $courseexists && !empty($usage->coursevisible),
            'coursehidden' => $courseexists && empty($usage->coursevisible),
            'activityname' => format_string($usage->activityname),
            'activityurl' => $activityurl,
            'hasactivityurl' => $activityurl !== '',
            'cmexists' => $cmexists,
            'activityvisible' => $cmexists && !empty($usage->activityvisible),
            'activityhidden' => $cmexists && empty($usage->activityvisible),
            'pendingdeletion' => $cmexists && !empty($usage->deletioninprogress),
        ];
    }

    return $rows;
}

/**
 * Move a posted file into the current user's draft area.
 *
 * The library API works from stored files, and the add flow needs the ZIP to outlive the
 * request that uploaded it, so every upload lands here first.
 *
 * @param string $field The name of the file input.
 * @return int The draft item ID holding the file.
 */
function cmi5_store_upload_in_draft(string $field): int {
    global $CFG, $USER;

    require_once($CFG->libdir . '/filelib.php');

    $usercontext = context_user::instance($USER->id);
    $draftitemid = file_get_unused_draft_itemid();
    $fs = get_file_storage();

    $fs->create_file_from_pathname([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => clean_filename($_FILES[$field]['name']),
    ], $_FILES[$field]['tmp_name']);

    return $draftitemid;
}
