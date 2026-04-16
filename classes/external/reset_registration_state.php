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
 * External function to reset a learner's registration state.
 *
 * Clears sessions, tokens, statements, state documents, and AU/block status
 * but keeps the registration record itself so the learner can re-launch
 * with a fresh session.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function to reset a learner's registration state without deleting the registration.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reset_registration_state extends external_api {

    /**
     * Describe the parameters for the execute function.
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'userid' => new external_value(PARAM_INT, 'The user ID whose registration state to reset'),
        ]);
    }

    /**
     * Reset a learner's registration state.
     *
     * Clears tokens, statements, sessions, au_status, block_status, and
     * state_documents but preserves the registration record.
     *
     * @param int $cmid The course module ID.
     * @param int $userid The user whose registration state to reset.
     * @return array ['success' => true]
     */
    public static function execute(int $cmid, int $userid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'userid' => $userid,
        ]);
        $cmid = $params['cmid'];
        $userid = $params['userid'];

        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'cmi5');
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/cmi5:managecontent', $context);

        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance]);
        if (!$cmi5) {
            throw new \moodle_exception('registrationnotfound', 'cmi5');
        }

        $registration = $DB->get_record('cmi5_registrations', [
            'cmi5id' => $cmi5->id,
            'userid' => $userid,
        ]);

        if (!$registration) {
            throw new \moodle_exception('registrationnotfound', 'cmi5');
        }

        // Clear all session-related data.
        $sessions = $DB->get_records('cmi5_sessions', ['registrationid' => $registration->id]);
        foreach ($sessions as $session) {
            $DB->delete_records('cmi5_tokens', ['sessionid' => $session->id]);
            $DB->delete_records('cmi5_statements', ['sessionid' => $session->id]);
        }
        $DB->delete_records('cmi5_sessions', ['registrationid' => $registration->id]);
        $DB->delete_records('cmi5_au_status', ['registrationid' => $registration->id]);
        $DB->delete_records('cmi5_block_status', ['registrationid' => $registration->id]);
        $DB->delete_records('cmi5_state_documents', ['registrationid' => $registration->id]);

        return ['success' => true];
    }

    /**
     * Describe the return value of the execute function.
     *
     * @return external_single_structure The return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the reset was successful'),
        ]);
    }
}
