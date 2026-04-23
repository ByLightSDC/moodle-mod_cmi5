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

// Redirect straight to the AU content. For iframe mode the overlay in launcher.js
// handles the session normally via AJAX; this page is only reached as a JS fallback.
redirect($launchurl);
