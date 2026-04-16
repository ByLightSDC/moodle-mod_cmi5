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
 * Launch Parameter Profiles management page for mod_cmi5.
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
$profileid = optional_param('profileid', 0, PARAM_INT);

$PAGE->set_url('/mod/cmi5/launch_profiles.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('launchprofiles', 'cmi5'));
$PAGE->set_heading(get_string('launchprofiles', 'cmi5'));

// Handle delete.
if ($action === 'delete' && $profileid && confirm_sesskey()) {
    $usagecount = $DB->count_records('cmi5', ['profileid' => $profileid]);
    if ($usagecount > 0) {
        \core\notification::error(get_string('profile:inuse', 'cmi5', $usagecount));
    } else {
        $DB->delete_records('cmi5_launch_profiles', ['id' => $profileid]);
        \core\notification::success(get_string('profile:deleted', 'cmi5'));
    }
    redirect(new moodle_url('/mod/cmi5/launch_profiles.php'));
}

// Handle create.
if ($action === 'create' && data_submitted() && confirm_sesskey()) {
    $name = required_param('name', PARAM_TEXT);
    $parameters = optional_param('parameters', '', PARAM_RAW);

    $record = new stdClass();
    $record->name = $name;
    $record->parameters = $parameters;
    $record->timecreated = time();
    $record->timemodified = time();
    $DB->insert_record('cmi5_launch_profiles', $record);

    \core\notification::success(get_string('profile:created', 'cmi5'));
    redirect(new moodle_url('/mod/cmi5/launch_profiles.php'));
}

// Handle edit (save).
if ($action === 'edit' && $profileid && data_submitted() && confirm_sesskey()) {
    $name = required_param('name', PARAM_TEXT);
    $parameters = optional_param('parameters', '', PARAM_RAW);

    $record = new stdClass();
    $record->id = $profileid;
    $record->name = $name;
    $record->parameters = $parameters;
    $record->timemodified = time();
    $DB->update_record('cmi5_launch_profiles', $record);

    \core\notification::success(get_string('profile:updated', 'cmi5'));
    redirect(new moodle_url('/mod/cmi5/launch_profiles.php'));
}

// Show edit form.
if ($action === 'editform' && $profileid) {
    $profile = $DB->get_record('cmi5_launch_profiles', ['id' => $profileid], '*', MUST_EXIST);

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/launch_profiles', [
        'showform' => true,
        'isedit' => true,
        'profileid' => $profile->id,
        'formname' => $profile->name,
        'formparameters' => $profile->parameters,
        'formaction' => (new moodle_url('/mod/cmi5/launch_profiles.php', ['action' => 'edit', 'profileid' => $profile->id]))->out(false),
        'backurl' => (new moodle_url('/mod/cmi5/launch_profiles.php'))->out(false),
        'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->footer();
    exit;
}

// List profiles.
echo $OUTPUT->header();

$profiles = $DB->get_records('cmi5_launch_profiles', null, 'name ASC');

$profilesdata = [];
foreach ($profiles as $p) {
    $usagecount = $DB->count_records('cmi5', ['profileid' => $p->id]);
    $preview = $p->parameters ?? '';
    if (strlen($preview) > 80) {
        $preview = substr($preview, 0, 80) . '...';
    }
    $profilesdata[] = [
        'id' => (int) $p->id,
        'name' => format_string($p->name),
        'parameterspreview' => $preview,
        'usagecount' => $usagecount,
        'editurl' => (new moodle_url('/mod/cmi5/launch_profiles.php', ['action' => 'editform', 'profileid' => $p->id]))->out(false),
        'deleteurl' => (new moodle_url('/mod/cmi5/launch_profiles.php', [
            'action' => 'delete',
            'profileid' => $p->id,
            'sesskey' => sesskey(),
        ]))->out(false),
        'candelete' => ($usagecount === 0),
    ];
}

echo $OUTPUT->render_from_template('mod_cmi5/launch_profiles', [
    'showform' => true,
    'isedit' => false,
    'formaction' => (new moodle_url('/mod/cmi5/launch_profiles.php', ['action' => 'create']))->out(false),
    'sesskey' => sesskey(),
    'hasprofiles' => !empty($profilesdata),
    'profiles' => $profilesdata,
    'showlist' => true,
]);

echo $OUTPUT->footer();
