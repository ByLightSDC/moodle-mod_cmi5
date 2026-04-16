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
 * Session management for cmi5 activity module.
 *
 * Handles creation, retrieval, and lifecycle state transitions for
 * cmi5 launch sessions.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages cmi5 session records in the cmi5_sessions table.
 *
 * Each session represents a single AU launch and tracks its lifecycle
 * through initialized, terminated, and abandoned states.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session {

    /**
     * Create a new session record.
     *
     * Generates a UUID for the session identifier and inserts a new
     * record into the cmi5_sessions table.
     *
     * @param int $registrationid The database ID of the registration.
     * @param int $auid The database ID of the AU (cmi5_aus.id).
     * @param string $launchmode The launch mode: Normal, Browse, or Review.
     * @return \stdClass The newly created session record.
     */
    public static function create(int $registrationid, int $auid,
            string $launchmode = 'Normal'): \stdClass {
        global $DB;

        $now = time();
        $record = new \stdClass();
        $record->registrationid = $registrationid;
        $record->auid = $auid;
        $record->sessionid = \core\uuid::generate();
        $record->launchmode = $launchmode;
        $record->initialized = 0;
        $record->terminated = 0;
        $record->abandoned = 0;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $record->id = $DB->insert_record('cmi5_sessions', $record);

        return $record;
    }

    /**
     * Find a session by its UUID session identifier.
     *
     * @param string $sessioniduuid The UUID sessionid field value.
     * @return \stdClass|null The session record, or null if not found.
     */
    public static function get_session_by_id(string $sessioniduuid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('cmi5_sessions', ['sessionid' => $sessioniduuid]);

        return $record ?: null;
    }

    /**
     * Mark a session as initialized.
     *
     * @param int $sessionid The database ID of the session record.
     * @return void
     */
    public static function mark_initialized(int $sessionid): void {
        global $DB;

        $DB->update_record('cmi5_sessions', (object) [
            'id' => $sessionid,
            'initialized' => 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * Mark a session as terminated.
     *
     * @param int $sessionid The database ID of the session record.
     * @return void
     */
    public static function mark_terminated(int $sessionid): void {
        global $DB;

        $DB->update_record('cmi5_sessions', (object) [
            'id' => $sessionid,
            'terminated' => 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * Mark a session as abandoned.
     *
     * @param int $sessionid The database ID of the session record.
     * @return void
     */
    public static function mark_abandoned(int $sessionid): void {
        global $DB;

        $DB->update_record('cmi5_sessions', (object) [
            'id' => $sessionid,
            'abandoned' => 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * Get the most recent active (non-terminated, non-abandoned) session.
     *
     * Returns the most recently created session for the given registration
     * and AU that has not been terminated or abandoned.
     *
     * @param int $registrationid The database ID of the registration.
     * @param int $auid The database ID of the AU.
     * @return \stdClass|null The active session record, or null if none found.
     */
    public static function get_active_session(int $registrationid, int $auid): ?\stdClass {
        global $DB;

        $records = $DB->get_records_select(
            'cmi5_sessions',
            'registrationid = :regid AND auid = :auid AND terminated = 0 AND abandoned = 0',
            ['regid' => $registrationid, 'auid' => $auid],
            'timecreated DESC',
            '*',
            0,
            1
        );

        return !empty($records) ? reset($records) : null;
    }

    /**
     * Get stale sessions that have been initialized but not completed.
     *
     * Returns sessions that were initialized but neither terminated nor
     * abandoned, and whose timemodified is older than the given timeout.
     *
     * @param int $timeout The timeout in seconds. Sessions older than this are stale.
     * @return array Array of stale session records.
     */
    public static function get_stale_sessions(int $timeout): array {
        global $DB;

        $cutoff = time() - $timeout;

        return array_values($DB->get_records_select(
            'cmi5_sessions',
            'initialized = 1 AND terminated = 0 AND abandoned = 0 AND timemodified < :cutoff',
            ['cutoff' => $cutoff]
        ));
    }
}
