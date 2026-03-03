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
 * External function to get AU status for the current user.
 *
 * Provides a web service endpoint that returns the progress status
 * of one or all Assignable Units in a cmi5 activity.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function for retrieving AU status data.
 *
 * Returns completion, pass/fail, satisfaction, and score information
 * for one or all AUs in a cmi5 activity, for the current user.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_au_status extends external_api {

    /**
     * Describe the parameters for the execute function.
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'auid' => new external_value(PARAM_INT, 'The AU database ID (0 = all AUs)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get AU status for the current user.
     *
     * Retrieves the user's registration for the activity, then returns
     * the status of the requested AU or all AUs. Each AU entry includes
     * completion, pass/fail, satisfaction, score, and a human-readable
     * status text string.
     *
     * @param int $cmid The course module ID.
     * @param int $auid The AU database ID, or 0 for all AUs.
     * @return array Array of AU status records.
     * @throws \invalid_parameter_exception If parameters are invalid.
     * @throws \required_capability_exception If user lacks view capability.
     */
    public static function execute(int $cmid, int $auid = 0): array {
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
        require_capability('mod/cmi5:view', $context);

        // Get the cmi5 instance.
        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);

        // Get the user's registration.
        $registration = $DB->get_record('cmi5_registrations', [
            'cmi5id' => $cmi5->id,
            'userid' => $USER->id,
        ]);

        // Get the AU records.
        if ($auid) {
            $aus = [$DB->get_record('cmi5_aus', ['id' => $auid, 'cmi5id' => $cmi5->id], '*', MUST_EXIST)];
        } else {
            $aus = $DB->get_records('cmi5_aus', ['cmi5id' => $cmi5->id], 'sortorder ASC');
        }

        $result = [];
        foreach ($aus as $au) {
            $austatus = null;
            if ($registration) {
                $austatus = $DB->get_record('cmi5_au_status', [
                    'registrationid' => $registration->id,
                    'auid' => $au->id,
                ]);
            }

            $completed = $austatus ? (int) $austatus->completed : 0;
            $passed = $austatus ? (int) $austatus->passed : 0;
            $failed = $austatus ? (int) $austatus->failed : 0;
            $satisfied = $austatus ? (int) $austatus->satisfied : 0;
            $scorescaled = ($austatus && $austatus->score_scaled !== null) ? (float) $austatus->score_scaled : null;

            // Build a human-readable status text.
            $statustext = self::build_status_text($completed, $passed, $failed, $satisfied);

            $result[] = [
                'id' => (int) $au->id,
                'title' => $au->title,
                'completed' => $completed,
                'passed' => $passed,
                'failed' => $failed,
                'satisfied' => $satisfied,
                'score_scaled' => $scorescaled,
                'statustext' => $statustext,
            ];
        }

        return $result;
    }

    /**
     * Build a human-readable status text from AU status flags.
     *
     * @param int $completed Whether the AU is completed.
     * @param int $passed Whether the AU is passed.
     * @param int $failed Whether the AU is failed.
     * @param int $satisfied Whether the AU is satisfied.
     * @return string A human-readable status string.
     */
    private static function build_status_text(int $completed, int $passed, int $failed, int $satisfied): string {
        if ($satisfied) {
            return get_string('ausatisfied', 'cmi5');
        }
        if ($failed) {
            return get_string('aufailed', 'cmi5');
        }
        if ($passed && $completed) {
            return get_string('aupassed', 'cmi5') . ', ' . get_string('aucompleted', 'cmi5');
        }
        if ($passed) {
            return get_string('aupassed', 'cmi5');
        }
        if ($completed) {
            return get_string('aucompleted', 'cmi5');
        }
        return get_string('aunotstarted', 'cmi5');
    }

    /**
     * Describe the return value of the execute function.
     *
     * @return external_multiple_structure The return value definition.
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'The AU database ID'),
                'title' => new external_value(PARAM_TEXT, 'The AU title'),
                'completed' => new external_value(PARAM_INT, '1 if AU is completed, 0 otherwise'),
                'passed' => new external_value(PARAM_INT, '1 if AU is passed, 0 otherwise'),
                'failed' => new external_value(PARAM_INT, '1 if AU is failed, 0 otherwise'),
                'satisfied' => new external_value(PARAM_INT, '1 if AU is satisfied, 0 otherwise'),
                'score_scaled' => new external_value(PARAM_FLOAT, 'Scaled score (-1 to 1), or null', VALUE_OPTIONAL),
                'statustext' => new external_value(PARAM_TEXT, 'Human-readable status text'),
            ])
        );
    }
}
