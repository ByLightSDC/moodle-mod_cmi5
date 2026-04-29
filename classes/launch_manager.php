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
 * Launch manager for cmi5 activity module.
 *
 * Orchestrates the cmi5 launch sequence: registration, session creation,
 * token generation, and launch URL construction.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates the full cmi5 AU launch workflow.
 *
 * Coordinates registration lookup/creation, session creation, fetch token
 * generation, and assembly of the cmi5-compliant launch URL with all
 * required query parameters.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class launch_manager {

    /** @var \stdClass The cmi5 activity instance record. */
    protected $cmi5;

    /** @var \context_module The module context. */
    protected $context;

    /** @var \stdClass The course module record. */
    protected $cm;

    /**
     * Constructor.
     *
     * @param \stdClass $cmi5 The cmi5 activity instance record.
     * @param \context_module $context The module context.
     * @param \stdClass $cm The course module record.
     */
    public function __construct(\stdClass $cmi5, \context_module $context, \stdClass $cm) {
        $this->cmi5 = $cmi5;
        $this->context = $context;
        $this->cm = $cm;
    }

    /**
     * Launch an AU for a user.
     *
     * Creates or retrieves a registration, creates a new session, generates
     * a fetch token, and builds the complete launch URL with the five
     * required cmi5 query parameters.
     *
     * @param \stdClass $au The AU record from cmi5_aus.
     * @param int $userid The Moodle user ID.
     * @return string The complete launch URL with cmi5 query parameters.
     */
    public function launch(\stdClass $au, int $userid): string {
        global $CFG;

        // Get or create the registration for this user and activity.
        $registration = registration::get_or_create($this->cmi5->id, $userid);

        // Create a new session for this launch.
        $session = session::create($registration->id, $au->id);

        // Create the single-use fetch token.
        $fetchtoken = token_manager::create_fetch_token($session->id);

        // Build the AU content base URL.
        $contenturl = content_server::get_launch_content_url($this->context, $this->cmi5, $au);
        $launchurl = $contenturl->out(false);

        // Build the actor JSON as per cmi5 spec (Agent with account).
        // Use email as the account name so downstream systems (e.g. RangeOS) display
        // a human-readable identifier instead of a UUID username from SSO.
        $user = \core_user::get_user($userid, 'id,email', MUST_EXIST);
        $actor = json_encode([
            'account' => [
                'homePage' => $CFG->wwwroot,
                'name' => $user->email,
            ],
        ], JSON_UNESCAPED_SLASHES);

        // Assemble the cmi5 launch parameters.
        // The endpoint must be a clean base URL without query params because
        // the @xapi/cmi5 library appends paths like /statements, /activities/state etc.
        // If this is inframe, pass the returnurl so it can navigate back, if this is not (a popup) pass blank string
        // so the player knows not to navigate but to close.
        $params = [
            'endpoint' => $CFG->wwwroot . '/mod/cmi5/proxy.php/' . $session->sessionid . '/',
            'fetch' => $CFG->wwwroot . '/mod/cmi5/fetch.php?token=' . $fetchtoken,
            'actor' => $actor,
            'activityId' => $au->auid,
            'registration' => $registration->registrationid,
            'returnURL' => $this->cmi5->launchmethod == 1
                ? $CFG->wwwroot . '/mod/cmi5/return.php?sessionid=' . $session->sessionid
                : '',
        ];
        // Append parameters to the launch URL.
        $separator = (strpos($launchurl, '?') !== false) ? '&' : '?';
        $launchurl .= $separator . http_build_query($params, '', '&');

        // Store the launch URL on the session record.
        global $DB;
        $DB->update_record('cmi5_sessions', (object) [
            'id' => $session->id,
            'launchurl' => $launchurl,
        ]);

        return $launchurl;
    }
}
