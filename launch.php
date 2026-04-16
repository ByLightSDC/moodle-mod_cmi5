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
 * Launch page - builds launch URL and redirects to AU content.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);     // Course module ID.
$auid = required_param('auid', PARAM_INT); // AU DB id.

$cm = get_coursemodule_from_id('cmi5', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/cmi5:launch', $context);

// Validate AU belongs to this activity.
$au = $DB->get_record('cmi5_aus', ['id' => $auid, 'cmi5id' => $cmi5->id], '*', MUST_EXIST);

// Build launch URL.
$launcher = new \mod_cmi5\launch_manager($cmi5, $context, $cm);
$launchurl = $launcher->launch($au, $USER->id);

// Trigger AU launched event.
$event = \mod_cmi5\event\au_launched::create([
    'objectid' => $au->id,
    'context' => $context,
    'userid' => $USER->id,
    'other' => ['auid' => $au->auid, 'title' => $au->title],
]);
$event->trigger();

// Determine the "back" URL: course page for single-AU, view page for multi-AU.
$aucount = $DB->count_records('cmi5_aus', ['cmi5id' => $cmi5->id]);
if ($aucount <= 1) {
    $backurl = new moodle_url('/course/view.php', ['id' => $course->id]);
} else {
    $backurl = new moodle_url('/mod/cmi5/view.php', ['id' => $cm->id]);
}

$PAGE->set_url('/mod/cmi5/launch.php', ['id' => $id, 'auid' => $auid]);
$PAGE->set_title(format_string($au->title));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($cmi5->launchmethod == 1) {
    // Iframe - show launch frame template.
    $PAGE->set_pagelayout('embedded');

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_cmi5/launch_frame', [
        'launchurl' => $launchurl,
        'title' => format_string($au->title),
        'returnurl' => $backurl->out(false),
    ]);
    echo $OUTPUT->footer();
} else {
    // New window - redirect straight to AU content.
    redirect($launchurl);
}
