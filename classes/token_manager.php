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
 * Token management for cmi5 activity module.
 *
 * Handles creation and validation of fetch and bearer tokens used
 * in the cmi5 launch sequence.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages authentication tokens for cmi5 fetch and bearer flows.
 *
 * The cmi5 launch sequence uses a two-step token exchange: the LMS provides
 * a single-use fetch URL token, which the AU exchanges for a bearer token
 * used to authenticate xAPI requests.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_manager {

    /**
     * Create a single-use fetch token for a session.
     *
     * The fetch token is included in the launch URL and is exchanged once
     * by the AU content to obtain a bearer token.
     *
     * @param int $sessiondbid The database ID of the session (cmi5_sessions.id).
     * @return string The 64-character hex token string.
     */
    public static function create_fetch_token(int $sessiondbid): string {
        global $DB;

        $tokenexpiry = get_config('mod_cmi5', 'tokenexpiry');
        if (empty($tokenexpiry)) {
            $tokenexpiry = 3600;
        }

        $tokenstring = bin2hex(random_bytes(32));

        $record = new \stdClass();
        $record->sessionid = $sessiondbid;
        $record->token = $tokenstring;
        $record->fetched = 0;
        $record->expiry = time() + (int) $tokenexpiry;
        $record->timecreated = time();

        $DB->insert_record('cmi5_tokens', $record);

        return $tokenstring;
    }

    /**
     * Validate and consume a fetch token.
     *
     * Checks that the token exists, has not already been fetched, and has
     * not expired. On success, marks the token as fetched.
     *
     * @param string $token The fetch token string to validate.
     * @return int The database ID of the associated session.
     * @throws \moodle_exception If the token is invalid, already used, or expired.
     */
    public static function validate_and_consume_fetch_token(string $token): int {
        global $DB;

        $record = $DB->get_record('cmi5_tokens', ['token' => $token]);
        if (!$record) {
            throw new \moodle_exception('error:invalidtoken', 'mod_cmi5');
        }

        if ($record->fetched) {
            throw new \moodle_exception('error:tokenalreadyused', 'mod_cmi5');
        }

        if ($record->expiry < time()) {
            throw new \moodle_exception('error:tokenexpired', 'mod_cmi5');
        }

        $DB->update_record('cmi5_tokens', (object) [
            'id' => $record->id,
            'fetched' => 1,
        ]);

        return (int) $record->sessionid;
    }

    /**
     * Create a bearer token for a session.
     *
     * The bearer token is returned to the AU via the fetch endpoint and
     * is used to authenticate subsequent xAPI requests.
     *
     * @param int $sessiondbid The database ID of the session (cmi5_sessions.id).
     * @return string The 64-character hex bearer token string.
     */
    public static function create_bearer_token(int $sessiondbid): string {
        global $DB;

        $tokenexpiry = get_config('mod_cmi5', 'tokenexpiry');
        if (empty($tokenexpiry)) {
            $tokenexpiry = 3600;
        }

        $tokenstring = bin2hex(random_bytes(32));

        $record = new \stdClass();
        $record->sessionid = $sessiondbid;
        $record->token = $tokenstring;
        $record->fetched = 1;
        $record->expiry = time() + (int) $tokenexpiry;
        $record->timecreated = time();

        $DB->insert_record('cmi5_tokens', $record);

        return $tokenstring;
    }

    /**
     * Validate a bearer token.
     *
     * Checks that the token exists and has not expired.
     *
     * @param string $token The bearer token string to validate.
     * @return int The database ID of the associated session.
     * @throws \moodle_exception If the token is invalid or expired.
     */
    public static function validate_bearer_token(string $token): int {
        global $DB;

        $record = $DB->get_record('cmi5_tokens', ['token' => $token]);
        if (!$record) {
            throw new \moodle_exception('error:invalidtoken', 'mod_cmi5');
        }

        if ($record->expiry < time()) {
            throw new \moodle_exception('error:tokenexpired', 'mod_cmi5');
        }

        return (int) $record->sessionid;
    }
}
