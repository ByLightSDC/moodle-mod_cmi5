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
 * Find and apply cmi5 content upgrades, for the whole site or one Moodle course.
 *
 * The same page serves a library manager looking across every course and a teacher looking
 * at their own, because the rules about what may be upgraded do not change with the
 * audience; only the scope and what the viewer is allowed to act on do.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$packageid = optional_param('packageid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$perpage = 20;

$pageparams = array_filter([
    'packageid' => $packageid,
    'courseid' => $courseid,
    'search' => $search,
], static fn($value) => $value !== 0 && $value !== '');

// Scope decides where the page lives and who may open it at all. A course-scoped page is
// reachable by a teacher with no library access; the site-wide page is not.
$iscoursescope = ($courseid > 0);
if ($iscoursescope) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_heading(format_string($course->fullname));
} else {
    require_login();
    $context = context_system::instance();
    require_capability('mod/cmi5:managelibrary', $context);
    $PAGE->set_pagelayout('admin');
    $PAGE->set_heading(get_string('upgrade:heading', 'cmi5'));
}

$PAGE->set_context($context);
$PAGE->set_url('/mod/cmi5/upgrades.php', $pageparams);
$PAGE->set_title(get_string('upgrade:heading', 'cmi5'));

$baseurl = new moodle_url('/mod/cmi5/upgrades.php', $pageparams);

/**
 * Resolve one activity into everything needed to show or apply an upgrade for it.
 *
 * Nothing the browser sent is taken on trust: the activity is looked up by its own ID and
 * its package, version and course are read back from the database, so a forged package or
 * course parameter changes nothing about what is returned.
 *
 * @param int $cmi5id Activity instance ID.
 * @return \stdClass|null The resolved row, or null when the activity cannot be upgraded by this user.
 */
function cmi5_upgrades_resolve_row(int $cmi5id): ?stdClass {
    global $DB;

    $cmi5 = $DB->get_record('cmi5', ['id' => $cmi5id]);
    if (!$cmi5 || empty($cmi5->packageid) || empty($cmi5->packageversionid)) {
        return null;
    }

    $cm = get_coursemodule_from_instance('cmi5', $cmi5id, 0, false, IGNORE_MISSING);
    if (!$cm || !empty($cm->deletioninprogress)) {
        return null;
    }

    $modulecontext = context_module::instance((int) $cm->id, IGNORE_MISSING);
    if (!$modulecontext || !has_capability('mod/cmi5:managecontent', $modulecontext)) {
        return null;
    }

    $current = $DB->get_record('cmi5_package_versions', [
        'id' => $cmi5->packageversionid,
        'packageid' => $cmi5->packageid,
    ]);
    $package = $DB->get_record('cmi5_packages', ['id' => $cmi5->packageid]);
    if (!$current || !$package) {
        return null;
    }

    $targets = \mod_cmi5\content_library::get_valid_targets(
        (int) $package->id, (int) $current->versionnumber);
    if (empty($targets)) {
        return null;
    }

    $course = $DB->get_record('course', ['id' => $cmi5->course]);

    return (object) [
        'cmi5id' => $cmi5id,
        'activityname' => format_string($cmi5->name),
        'activityvisible' => !empty($cm->visible),
        'coursename' => $course ? format_string($course->fullname) : '',
        'coursevisible' => $course ? !empty($course->visible) : true,
        'packageid' => (int) $package->id,
        'packagetitle' => format_string($package->title),
        'currentversionid' => (int) $current->id,
        'currentversionnumber' => (int) $current->versionnumber,
        'targets' => $targets,
    ];
}

/**
 * Phrase a version number the way every screen in this flow says it.
 *
 * @param int $number The version number.
 * @return string The label, such as "v4".
 */
function cmi5_upgrades_version_label(int $number): string {
    return get_string('upgrade:versionlabel', 'cmi5', $number);
}

/**
 * Phrase a unit count, singular or plural.
 *
 * @param string $kind One of 'added', 'changed' or 'removed'.
 * @param int $count How many units.
 * @return string The phrase, such as "1 unit added" or "3 units updated".
 */
function cmi5_upgrades_unit_label(string $kind, int $count): string {
    $key = 'upgrade:units' . $kind . ($count === 1 ? '_one' : '');
    return get_string($key, 'cmi5', $count);
}

/**
 * Find a target version by ID.
 *
 * @param array $targets Version records from content_library::get_valid_targets().
 * @param int $targetversionid Target version ID.
 * @return \stdClass|null The matching version, if it is a valid target.
 */
function cmi5_upgrades_find_target(array $targets, int $targetversionid): ?stdClass {
    foreach ($targets as $target) {
        if ((int) $target->id === $targetversionid) {
            return $target;
        }
    }

    return null;
}

/**
 * Build the target-version options for one row.
 *
 * @param array $targets Version records from content_library::get_valid_targets().
 * @return array Template options.
 */
function cmi5_upgrades_target_options(array $targets): array {
    $options = [];
    foreach ($targets as $index => $target) {
        // Targets arrive newest first, so the latest is the default when nothing was chosen.
        $islatest = ($index === 0);
        $options[] = [
            'versionid' => (int) $target->id,
            'label' => $islatest
                ? get_string('upgrade:versionlatest', 'cmi5', (int) $target->versionnumber)
                : cmi5_upgrades_version_label((int) $target->versionnumber),
            'selected' => $islatest,
        ];
    }
    return $options;
}

/**
 * Convert URL parameters to the name/value rows expected by the templates.
 *
 * @param array $params URL parameters.
 * @return array Template rows.
 */
function cmi5_upgrades_hidden_params(array $params): array {
    return array_map(
        static fn($name, $value) => ['name' => $name, 'value' => $value],
        array_keys($params),
        array_values($params)
    );
}

/**
 * Read the posted (activity, expected version, target version) tuples.
 *
 * Only ticked rows count, and each one carries the version it was selected from, so a
 * review built against stale data cannot be applied to an activity that has since moved.
 *
 * @return array List of objects with cmi5id, expectedversionid and targetversionid.
 */
function cmi5_upgrades_posted_rows(): array {
    $selected = optional_param_array('selected', [], PARAM_INT);
    $targets = optional_param_array('target', [], PARAM_INT);
    $expected = optional_param_array('expected', [], PARAM_INT);

    $rows = [];
    foreach ($selected as $cmi5id) {
        $cmi5id = (int) $cmi5id;
        if ($cmi5id <= 0 || !isset($targets[$cmi5id])) {
            continue;
        }
        $rows[] = (object) [
            'cmi5id' => $cmi5id,
            'targetversionid' => (int) $targets[$cmi5id],
            'expectedversionid' => (int) ($expected[$cmi5id] ?? 0),
        ];
    }

    return $rows;
}

// Show everything that changed between the version an activity is on and a target version.
if ($action === 'changes') {
    $cmi5id = required_param('cmi5id', PARAM_INT);
    $targetversionid = required_param('targetversionid', PARAM_INT);

    $row = cmi5_upgrades_resolve_row($cmi5id);
    if (!$row) {
        throw new moodle_exception('upgrade:rowunavailable', 'cmi5');
    }

    $target = cmi5_upgrades_find_target($row->targets, $targetversionid);
    if (!$target) {
        throw new moodle_exception('upgrade:reason_invalidtarget', 'cmi5');
    }

    $changes = \mod_cmi5\content_library::get_cumulative_changelog(
        $row->packageid, $row->currentversionnumber, (int) $target->versionnumber);

    // Newest first: what the target adds is what the reader came for.
    $versionrows = [];
    foreach (array_reverse($changes->versions) as $entry) {
        $entries = [];
        foreach ($entry->entries as $change) {
            $entries[] = [
                'description' => \mod_cmi5\content_library::describe_change($change),
            ];
        }
        $versionrows[] = [
            'versionlabel' => cmi5_upgrades_version_label((int) $entry->version->versionnumber),
            'istarget' => ((int) $entry->version->id === $targetversionid),
            'meta' => userdate((int) $entry->version->timecreated,
                get_string('strftimedatefullshort', 'core_langconfig')),
            'entries' => $entries,
            'hasentries' => !empty($entries),
        ];
    }

    $PAGE->set_url('/mod/cmi5/upgrades.php', [
        'action' => 'changes',
        'cmi5id' => $cmi5id,
        'targetversionid' => $targetversionid,
    ]);
    $PAGE->set_title(get_string('upgrade:changesheading', 'cmi5', $row->activityname));

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/upgrades_changes', [
        'heading' => get_string('upgrade:changesheading', 'cmi5', $row->activityname),
        'packagetitle' => $row->packagetitle,
        'fromlabel' => cmi5_upgrades_version_label($row->currentversionnumber),
        'tolabel' => cmi5_upgrades_version_label((int) $target->versionnumber),
        'rangelabel' => get_string('upgrade:changerange', 'cmi5', (object) [
            'versions' => count($changes->versions),
            'changes' => count($changes->entries),
        ]),
        'hasunits' => $changes->available,
        'hasadded' => $changes->added > 0,
        'addedlabel' => cmi5_upgrades_unit_label('added', (int) $changes->added),
        'haschanged' => $changes->changed > 0,
        'changedlabel' => cmi5_upgrades_unit_label('changed', (int) $changes->changed),
        'versions' => $versionrows,
        'hasversions' => !empty($versionrows),
        'backurl' => $baseurl->out(false),
    ]);
    echo $OUTPUT->footer();
    exit;
}

// Step two: show exactly what will happen, then take an explicit confirmation.
if ($action === 'review') {
    require_sesskey();
    if (!data_submitted()) {
        redirect($baseurl);
    }

    $posted = cmi5_upgrades_posted_rows();
    $reviewrows = [];

    foreach ($posted as $entry) {
        $row = cmi5_upgrades_resolve_row($entry->cmi5id);
        if (!$row || $row->currentversionid !== $entry->expectedversionid) {
            continue;
        }

        $target = cmi5_upgrades_find_target($row->targets, $entry->targetversionid);
        if (!$target) {
            continue;
        }

        $changes = \mod_cmi5\content_library::get_cumulative_changelog(
            $row->packageid, $row->currentversionnumber, (int) $target->versionnumber);

        $reviewrows[] = [
            'cmi5id' => $row->cmi5id,
            'expectedversionid' => $row->currentversionid,
            'targetversionid' => (int) $target->id,
            'activityname' => $row->activityname,
            'coursename' => $row->coursename,
            'packagetitle' => $row->packagetitle,
            'ishidden' => (!$row->activityvisible || !$row->coursevisible),
            'hiddenlabel' => !$row->activityvisible
                ? get_string('upgrade:activityhidden', 'cmi5')
                : get_string('upgrade:coursehidden', 'cmi5'),
            'fromlabel' => cmi5_upgrades_version_label($row->currentversionnumber),
            'tolabel' => cmi5_upgrades_version_label((int) $target->versionnumber),
            'haschanges' => $changes->available,
            'hasadded' => $changes->added > 0,
            'addedlabel' => cmi5_upgrades_unit_label('added', (int) $changes->added),
            'haschanged' => $changes->changed > 0,
            'changedlabel' => cmi5_upgrades_unit_label('changed', (int) $changes->changed),
            'hasremoved' => $changes->removed > 0,
            'removedlabel' => cmi5_upgrades_unit_label('removed', (int) $changes->removed),
            'changesurl' => (new moodle_url('/mod/cmi5/upgrades.php', $pageparams + [
                'action' => 'changes',
                'cmi5id' => $row->cmi5id,
                'targetversionid' => (int) $target->id,
            ]))->out(false),
        ];
    }

    if (empty($reviewrows)) {
        \core\notification::warning(get_string('upgrade:nothingtoreview', 'cmi5'));
        redirect($baseurl);
    }

    $PAGE->set_title(get_string('upgrade:reviewheading', 'cmi5', count($reviewrows)));

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/upgrades_review', [
        'heading' => count($reviewrows) === 1
            ? get_string('upgrade:reviewheading_one', 'cmi5')
            : get_string('upgrade:reviewheading', 'cmi5', count($reviewrows)),
        'confirmlabel' => count($reviewrows) === 1
            ? get_string('upgrade:confirm_one', 'cmi5')
            : get_string('upgrade:confirm', 'cmi5', count($reviewrows)),
        'countlabel' => count($reviewrows) === 1
            ? get_string('upgrade:willupgrade_one', 'cmi5')
            : get_string('upgrade:willupgrade', 'cmi5', count($reviewrows)),
        'rows' => $reviewrows,
        'formurl' => (new moodle_url('/mod/cmi5/upgrades.php'))->out(false),
        'hiddenparams' => cmi5_upgrades_hidden_params($pageparams),
        'backurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->footer();
    exit;
}

// Step three: apply each row on its own and report what actually happened to each.
if ($action === 'execute') {
    require_sesskey();
    if (!data_submitted()) {
        redirect($baseurl);
    }

    $posted = cmi5_upgrades_posted_rows();
    if (empty($posted)) {
        redirect($baseurl);
    }

    $batchid = uniqid('cmi5', true);
    $outcomes = [];
    $counts = ['upgraded' => 0, 'skipped' => 0, 'failed' => 0];

    foreach ($posted as $entry) {
        // Deliberately not wrapped in a shared transaction: one row failing must not undo
        // the rows that already succeeded.
        $outcome = \mod_cmi5\content_library::upgrade_activity(
            $entry->cmi5id, $entry->expectedversionid, $entry->targetversionid, $batchid);
        $counts[$outcome->status]++;

        $activityurl = '';
        if ($outcome->cmid) {
            $modulecontext = context_module::instance($outcome->cmid, IGNORE_MISSING);
            if ($modulecontext && has_capability('mod/cmi5:view', $modulecontext)) {
                $activityurl = (new moodle_url('/mod/cmi5/view.php', ['id' => $outcome->cmid]))->out(false);
            }
        }

        $outcomes[] = [
            'isupgraded' => ($outcome->status === \mod_cmi5\content_library::UPGRADE_UPGRADED),
            'isskipped' => ($outcome->status === \mod_cmi5\content_library::UPGRADE_SKIPPED),
            'isfailed' => ($outcome->status === \mod_cmi5\content_library::UPGRADE_FAILED),
            'statuslabel' => get_string('upgrade:status_' . $outcome->status, 'cmi5'),
            'activityname' => format_string($outcome->activityname),
            'coursename' => format_string($outcome->coursename),
            'activityurl' => $activityurl,
            'hasactivityurl' => ($activityurl !== ''),
            'detail' => $outcome->status === \mod_cmi5\content_library::UPGRADE_UPGRADED
                ? get_string('upgrade:movedto', 'cmi5', (object) [
                    'from' => cmi5_upgrades_version_label($outcome->fromnumber),
                    'to' => cmi5_upgrades_version_label($outcome->tonumber),
                ])
                : get_string($outcome->reason, 'cmi5'),
        ];
    }

    $PAGE->set_title(get_string('upgrade:resultsheading', 'cmi5'));

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/upgrades_results', [
        'upgradedcount' => $counts['upgraded'],
        'skippedcount' => $counts['skipped'],
        'failedcount' => $counts['failed'],
        'hasfailures' => ($counts['failed'] > 0),
        'summary' => get_string('upgrade:resultsummary', 'cmi5', (object) $counts),
        'rows' => $outcomes,
        'backurl' => $baseurl->out(false),
        'islibraryscope' => !$iscoursescope,
        'libraryurl' => (new moodle_url('/mod/cmi5/library.php'))->out(false),
        'courseurl' => $iscoursescope
            ? (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false)
            : '',
    ]);
    echo $OUTPUT->footer();
    exit;
}

// The list itself.
$filters = ['search' => $search];
if ($packageid) {
    $filters['packageid'] = $packageid;
}
if ($courseid) {
    $filters['courseid'] = $courseid;
}

$candidates = \mod_cmi5\content_library::get_upgrade_candidates($filters);

// Authorization is applied per activity, not per page: a library manager may see an
// activity they cannot edit, and is told so rather than being offered a control that
// would fail. A teacher's own page only lists what they can act on.
$visible = [];
$manageable = 0;
foreach ($candidates as $candidate) {
    $modulecontext = $candidate->cmid
        ? context_module::instance((int) $candidate->cmid, IGNORE_MISSING)
        : null;
    $candidate->canmanage = ($modulecontext
        && empty($candidate->deletioninprogress)
        && has_capability('mod/cmi5:managecontent', $modulecontext));

    if ($candidate->canmanage) {
        $manageable++;
    } else if ($iscoursescope) {
        continue;
    }

    $visible[] = $candidate;
}

// A teacher reaches this page through their own course, so the page is theirs only if
// there is something on it they may change, or they hold the capability course-wide.
if ($iscoursescope && $manageable === 0) {
    require_capability('mod/cmi5:managecontent', $context);
}

$totalcount = count($visible);
$lastpage = max(0, (int) ceil($totalcount / $perpage) - 1);
$page = max(0, min($page, $lastpage));
$pagerows = array_slice($visible, $page * $perpage, $perpage);

// Grouped by package and by the version its activities are on, because one target choice
// is only meaningful for rows that share a starting point.
$groups = [];
foreach ($pagerows as $row) {
    $key = $row->packageid . ':' . $row->currentversionid;
    if (!isset($groups[$key])) {
        $targets = \mod_cmi5\content_library::get_valid_targets(
            (int) $row->packageid, (int) $row->currentversionnumber);
        $groups[$key] = (object) [
            'packagetitle' => format_string($row->packagetitle),
            'currentlabel' => cmi5_upgrades_version_label((int) $row->currentversionnumber),
            'targets' => $targets,
            'rows' => [],
        ];
    }
    $groups[$key]->rows[] = $row;
}

$groupdata = [];
$selectablecount = 0;
foreach ($groups as $key => $group) {
    $rowdata = [];
    foreach ($group->rows as $row) {
        $activityname = format_string($row->activityname);
        $coursename = format_string($row->coursename ?? '');
        $activityurl = '';
        if ($row->cmid) {
            $modulecontext = context_module::instance((int) $row->cmid, IGNORE_MISSING);
            if ($modulecontext && has_capability('mod/cmi5:view', $modulecontext)) {
                $activityurl = (new moodle_url('/mod/cmi5/view.php', ['id' => $row->cmid]))->out(false);
            }
        }

        $ishidden = empty($row->activityvisible) || empty($row->coursevisible);
        if ($row->canmanage) {
            $selectablecount++;
        }

        $rowdata[] = [
            'cmi5id' => (int) $row->cmi5id,
            'checkid' => 'cmi5-upgrade-' . (int) $row->cmi5id,
            'expectedversionid' => (int) $row->currentversionid,
            'activityname' => $activityname,
            'activityurl' => $activityurl,
            'hasactivityurl' => ($activityurl !== ''),
            'coursename' => $coursename,
            'ishidden' => $ishidden,
            'hiddenlabel' => empty($row->activityvisible)
                ? get_string('upgrade:activityhidden', 'cmi5')
                : get_string('upgrade:coursehidden', 'cmi5'),
            'currentlabel' => cmi5_upgrades_version_label((int) $row->currentversionnumber),
            'canmanage' => (bool) $row->canmanage,
            'blockedreason' => get_string('upgrade:reason_nopermission', 'cmi5'),
            'targets' => cmi5_upgrades_target_options($group->targets),
            'selectlabel' => get_string('upgrade:selectrow', 'cmi5', (object) [
                'activity' => $activityname,
                'course' => $coursename,
            ]),
            'targetlabel' => get_string('upgrade:targetrow', 'cmi5', (object) [
                'activity' => $activityname,
                'course' => $coursename,
            ]),
            'changesurl' => (new moodle_url('/mod/cmi5/upgrades.php', $pageparams + [
                'action' => 'changes',
                'cmi5id' => (int) $row->cmi5id,
                'targetversionid' => (int) $row->latestversionid,
            ]))->out(false),
        ];
    }

    $count = count($rowdata);
    $groupdata[] = [
        'groupid' => 'cmi5-group-' . str_replace(':', '-', $key),
        'packagetitle' => $group->packagetitle,
        'currentlabel' => $group->currentlabel,
        'countlabel' => $count === 1
            ? get_string('upgrade:groupcount_one', 'cmi5')
            : get_string('upgrade:groupcount', 'cmi5', $count),
        'grouptargets' => cmi5_upgrades_target_options($group->targets),
        'groupselectlabel' => get_string('upgrade:selectgroup', 'cmi5', (object) [
            'package' => $group->packagetitle,
            'version' => $group->currentlabel,
        ]),
        'grouptargetlabel' => get_string('upgrade:targetgroup', 'cmi5', (object) [
            'package' => $group->packagetitle,
            'version' => $group->currentlabel,
        ]),
        'rows' => $rowdata,
    ];
}

// Repair cases are listed, never counted as upgradeable: without a version to upgrade from
// there is no baseline, and guessing one would change content nobody approved.
$repairrows = [];
foreach (\mod_cmi5\content_library::get_repair_candidates($filters) as $row) {
    $modulecontext = $row->cmid ? context_module::instance((int) $row->cmid, IGNORE_MISSING) : null;
    $canmanage = ($modulecontext
        && empty($row->deletioninprogress)
        && has_capability('mod/cmi5:managecontent', $modulecontext));
    if ($iscoursescope && !$canmanage) {
        continue;
    }

    $activityurl = '';
    $coursecontext = context_course::instance((int) $row->courseid, IGNORE_MISSING);
    if ($canmanage && $coursecontext && has_capability('moodle/course:manageactivities', $coursecontext)) {
        $activityurl = (new moodle_url('/course/modedit.php', ['update' => $row->cmid]))->out(false);
    }

    $repairrows[] = [
        'activityname' => format_string($row->activityname),
        'coursename' => format_string($row->coursename ?? ''),
        'packagetitle' => format_string($row->packagetitle),
        'activityurl' => $activityurl,
        'hasactivityurl' => ($activityurl !== ''),
    ];
}

$templatedata = [
    'heading' => get_string('upgrade:heading', 'cmi5'),
    'intro' => $totalcount === 1
        ? get_string('upgrade:intro_one', 'cmi5')
        : get_string('upgrade:intro', 'cmi5', $totalcount),
    'iscoursescope' => $iscoursescope,
    'scopelabel' => $iscoursescope ? get_string('upgrade:scopecourse', 'cmi5') : '',
    'backurl' => $iscoursescope
        ? (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false)
        : (new moodle_url('/mod/cmi5/library.php'))->out(false),
    'backlabel' => $iscoursescope
        ? get_string('upgrade:backtocourse', 'cmi5')
        : get_string('contentlibrary', 'cmi5'),
    'search' => $search,
    'hassearch' => ($search !== ''),
    'searchurl' => (new moodle_url('/mod/cmi5/upgrades.php'))->out(false),
    'clearurl' => (new moodle_url('/mod/cmi5/upgrades.php', array_diff_key($pageparams, ['search' => ''])))
        ->out(false),
    'hiddenparams' => cmi5_upgrades_hidden_params(array_diff_key($pageparams, ['search' => ''])),
    'formurl' => (new moodle_url('/mod/cmi5/upgrades.php'))->out(false),
    'sesskey' => sesskey(),
    'groups' => $groupdata,
    'hasgroups' => !empty($groupdata),
    'selectalllabel' => $selectablecount === 1
        ? get_string('upgrade:selectall_one', 'cmi5')
        : get_string('upgrade:selectall', 'cmi5', $selectablecount),
    'pagescopelabel' => $totalcount > $perpage
        ? get_string('upgrade:selectionpagescope', 'cmi5', (object) [
            'page' => $page + 1,
            'pages' => $lastpage + 1,
        ])
        : get_string('upgrade:selectionsinglepage', 'cmi5'),
    'hasrepair' => !empty($repairrows),
    'repairrows' => $repairrows,
    'repairheading' => count($repairrows) === 1
        ? get_string('upgrade:repairheading_one', 'cmi5')
        : get_string('upgrade:repairheading', 'cmi5', count($repairrows)),
    'emptytitle' => ($search !== '')
        ? get_string('upgrade:nomatches', 'cmi5', $search)
        : get_string('upgrade:emptyheading', 'cmi5'),
    'emptyhelp' => get_string('upgrade:emptyhelp', 'cmi5'),
];

$PAGE->requires->js_call_amd('mod_cmi5/upgrades', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_cmi5/upgrades', $templatedata);

if ($totalcount > $perpage) {
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);
}

echo $OUTPUT->footer();
