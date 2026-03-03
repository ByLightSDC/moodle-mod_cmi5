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
 * External function to get a cmi5 AU launch URL.
 *
 * Provides a web service endpoint that generates a launch URL for a
 * specific Assignable Unit in a cmi5 activity.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_cmi5\launch_manager;

/**
 * External function for generating a cmi5 AU launch URL.
 *
 * Validates parameters, checks the user's capability to launch the
 * activity, then delegates to the launch manager to build the URL.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_launch_url extends external_api {

    /**
     * Describe the parameters for the execute function.
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'auid' => new external_value(PARAM_INT, 'The AU database ID'),
        ]);
    }

    /**
     * Generate a launch URL for a cmi5 AU.
     *
     * Validates the parameters, checks that the user has the mod/cmi5:launch
     * capability, retrieves the AU record, and uses the launch manager to
     * construct the full cmi5-compliant launch URL.
     *
     * @param int $cmid The course module ID.
     * @param int $auid The AU database ID.
     * @return array Array containing launchurl and launchmethod.
     * @throws \invalid_parameter_exception If parameters are invalid.
     * @throws \required_capability_exception If user lacks launch capability.
     * @throws \moodle_exception If the AU is not found.
     */
    public static function execute(int $cmid, int $auid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'auid' => $auid,
        ]);
        $cmid = $params['cmid'];
        $auid = $params['auid'];

        // Get the course module and context.
        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'cmi5');
        $context = \context_module::instance($cm->id);

        // Validate context and check capability.
        self::validate_context($context);
        require_capability('mod/cmi5:launch', $context);

        // Get the cmi5 instance record.
        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);

        // Get the AU record and verify it belongs to this activity.
        $au = $DB->get_record('cmi5_aus', ['id' => $auid, 'cmi5id' => $cmi5->id], '*', MUST_EXIST);

        // Build the launch URL.
        $launchmanager = new launch_manager($cmi5, $context, $cm);
        $launchurl = $launchmanager->launch($au, $USER->id);

        // Determine the launch method: activity-level setting, or AU override.
        $launchmethod = (int) $cmi5->launchmethod;

        return [
            'launchurl' => $launchurl,
            'launchmethod' => $launchmethod,
        ];
    }

    /**
     * Describe the return value of the execute function.
     *
     * @return external_single_structure The return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'launchurl' => new external_value(PARAM_URL, 'The full cmi5 launch URL'),
            'launchmethod' => new external_value(PARAM_INT, 'Launch method: 0=new window, 1=iframe'),
        ]);
    }
}
