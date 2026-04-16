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
 * Registration management for cmi5 activity module.
 *
 * Handles creation and retrieval of per-user registration records
 * that link a learner to a cmi5 activity instance.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages cmi5 registration records in the cmi5_registrations table.
 *
 * Each registration represents a unique learner-activity pairing and
 * contains a UUID used in xAPI statements and launch parameters.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registration {

    /**
     * Get an existing registration or create a new one.
     *
     * If a registration already exists for the given cmi5 activity and user,
     * it is returned. Otherwise a new registration is created with a
     * generated UUID.
     *
     * @param int $cmi5id The cmi5 activity instance ID.
     * @param int $userid The Moodle user ID.
     * @return \stdClass The registration record from cmi5_registrations.
     */
    public static function get_or_create(int $cmi5id, int $userid): \stdClass {
        global $DB;

        $record = self::get_registration($cmi5id, $userid);
        if ($record) {
            return $record;
        }

        $now = time();
        $record = new \stdClass();
        $record->cmi5id = $cmi5id;
        $record->userid = $userid;
        $record->registrationid = \core\uuid::generate();
        $record->coursesatisfied = 0;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $record->id = $DB->insert_record('cmi5_registrations', $record);

        return $record;
    }

    /**
     * Get an existing registration for a user and activity.
     *
     * @param int $cmi5id The cmi5 activity instance ID.
     * @param int $userid The Moodle user ID.
     * @return \stdClass|null The registration record, or null if not found.
     */
    public static function get_registration(int $cmi5id, int $userid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('cmi5_registrations', [
            'cmi5id' => $cmi5id,
            'userid' => $userid,
        ]);

        return $record ?: null;
    }

    /**
     * Mark a registration's course as satisfied.
     *
     * Sets the coursesatisfied flag to 1 and updates the timemodified timestamp.
     *
     * @param int $registrationid The database ID of the registration record.
     * @return void
     */
    public static function mark_course_satisfied(int $registrationid): void {
        global $DB;

        $DB->update_record('cmi5_registrations', (object) [
            'id' => $registrationid,
            'coursesatisfied' => 1,
            'timemodified' => time(),
        ]);
    }
}
