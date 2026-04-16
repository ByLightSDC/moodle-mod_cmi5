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
 * Return URL handler - AU redirects here when the learner exits.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$sessionid = required_param('sessionid', PARAM_ALPHANUMEXT);

// Find the session by UUID.
$session = $DB->get_record('cmi5_sessions', ['sessionid' => $sessionid]);
if (!$session) {
    throw new moodle_exception('invalidsession', 'mod_cmi5');
}

// Load registration and activity.
$registration = $DB->get_record('cmi5_registrations', ['id' => $session->registrationid], '*', MUST_EXIST);
$cmi5 = $DB->get_record('cmi5', ['id' => $registration->cmi5id], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('cmi5', $cmi5->id, $cmi5->course, false, MUST_EXIST);

require_login($cmi5->course, true, $cm);

// If session was initialized but not terminated, the AU exited without sending Terminated.
// The scheduled task will handle abandonment after timeout.

// Redirect back to the activity view.
redirect(new moodle_url('/mod/cmi5/view.php', ['id' => $cm->id]));
