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

// Show the activities which currently use a package version.
if ($action === 'usage') {
    if (!$packageid || !$versionid) {
        throw new invalid_parameter_exception('A package and version are required');
    }

    $package = \mod_cmi5\content_library::get_package_details($packageid, $versionid);
    $usagecount = \mod_cmi5\content_library::count_version_usage($versionid);
    $lastpage = max(0, (int) ceil($usagecount / $perpage) - 1);
    $page = max(0, min($page, $lastpage));
    $usagerecords = \mod_cmi5\content_library::get_version_usage(
        $versionid, $page * $perpage, $perpage
    );
    $headingdata = (object) [
        'title' => format_string($package->title),
        'version' => (int) $package->versionnumber,
    ];

    $PAGE->set_url('/mod/cmi5/library.php', [
        'action' => 'usage',
        'packageid' => $packageid,
        'versionid' => $versionid,
        'page' => $page,
    ]);
    $PAGE->set_title(get_string('library:usageheading', 'cmi5', $headingdata));

    $usagedata = [];
    foreach ($usagerecords as $usage) {
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

        $usagedata[] = [
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

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/library_version_usage', [
        'heading' => get_string('library:usageheading', 'cmi5', $headingdata),
        'usagecount' => $usagecount,
        'usageintro' => $usagecount === 1
            ? get_string('library:usageintro_one', 'cmi5')
            : get_string('library:usageintro', 'cmi5', $usagecount),
        'hasusage' => !empty($usagedata),
        'usages' => $usagedata,
        'backurl' => (new moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $packageid,
            'versionid' => $versionid,
        ]))->out(false),
    ]);
    echo $OUTPUT->paging_bar($usagecount, $page, $perpage, new moodle_url('/mod/cmi5/library.php', [
        'action' => 'usage',
        'packageid' => $packageid,
        'versionid' => $versionid,
    ]));
    echo $OUTPUT->footer();
    exit;
}

// View package details.
if ($action === 'view' && $packageid) {
    $package = \mod_cmi5\content_library::get_package_details($packageid, $versionid);

    $tab = optional_param('tab', 'structure', PARAM_ALPHA);
    if ($tab !== 'versions') {
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

    // Block titles, so an AU can name the block it belongs to.
    $blocktitles = [];
    foreach ($package->blocks as $block) {
        $blocktitles[(int) $block->id] = format_string($block->title);
    }

    $aucount = count($package->aus);
    $blockcount = count($package->blocks);

    // One ordered list of rows carrying the nesting from cmi5.xml.
    $structure = [];
    foreach (\mod_cmi5\content_library::build_structure_tree($package->blocks, $package->aus) as $row) {
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
                'indent' => ($depth + 1) * 20,
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
            'nested' => $depth > 0,
            // Only worth flagging when the package has blocks at all.
            'orphan' => ($depth === 0 && $blockcount > 0),
            // A top-level AU belongs to the course; nested AUs sit one level below their block.
            'indent' => ($depth + 1) * 20,
            'hasparent' => $parenttitle !== '',
            'parenttitle' => $parenttitle,
        ];
    }

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
                'action' => 'usage',
                'packageid' => $packageid,
                'versionid' => $ver->id,
            ]))->out(false),
            'candownload' => ((int) $ver->source === \mod_cmi5\content_library::SOURCE_ZIP),
            'downloadurl' => (new moodle_url('/mod/cmi5/library.php', [
                'action' => 'download',
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
            'action' => 'usage',
            'packageid' => $packageid,
            'versionid' => (int) $package->versionid,
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
    $usagecount = \mod_cmi5\content_library::count_package_usage((int) $pkg->id);

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
