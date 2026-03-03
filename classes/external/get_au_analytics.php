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
 * External function to get per-AU analytics for a cmi5 activity.
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
 * External function for retrieving per-AU analytics.
 *
 * Returns completion rates, average scores, session counts, and average
 * durations for each AU in the activity.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_au_analytics extends external_api {

    /**
     * Describe the parameters for the execute function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
        ]);
    }

    /**
     * Get per-AU analytics for a cmi5 activity.
     *
     * @param int $cmid The course module ID.
     * @return array Per-AU analytics data.
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cmid = $params['cmid'];

        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'cmi5');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/cmi5:view', $context);

        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $isteacher = has_capability('mod/cmi5:viewreports', $context);

        // User scope filter.
        $userwhere = '';
        $userparams = [];
        if (!$isteacher) {
            $userwhere = ' AND r.userid = :userid';
            $userparams['userid'] = $USER->id;
        }

        $aus = $DB->get_records('cmi5_aus', ['cmi5id' => $cmi5->id], 'sortorder ASC');
        $result = [];

        foreach ($aus as $au) {
            $baseparams = ['auid' => $au->id, 'cmi5id' => $cmi5->id] + $userparams;

            // Learner count (distinct users with au_status for this AU).
            $learnercount = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT r.userid)
                   FROM {cmi5_au_status} ast
                   JOIN {cmi5_registrations} r ON r.id = ast.registrationid
                  WHERE ast.auid = :auid AND r.cmi5id = :cmi5id" . $userwhere,
                $baseparams
            );

            // Status counts.
            $completedcount = $DB->count_records_sql(
                "SELECT COUNT(*)
                   FROM {cmi5_au_status} ast
                   JOIN {cmi5_registrations} r ON r.id = ast.registrationid
                  WHERE ast.auid = :auid AND r.cmi5id = :cmi5id AND ast.completed = 1" . $userwhere,
                $baseparams
            );
            $passedcount = $DB->count_records_sql(
                "SELECT COUNT(*)
                   FROM {cmi5_au_status} ast
                   JOIN {cmi5_registrations} r ON r.id = ast.registrationid
                  WHERE ast.auid = :auid AND r.cmi5id = :cmi5id AND ast.passed = 1" . $userwhere,
                $baseparams
            );
            $failedcount = $DB->count_records_sql(
                "SELECT COUNT(*)
                   FROM {cmi5_au_status} ast
                   JOIN {cmi5_registrations} r ON r.id = ast.registrationid
                  WHERE ast.auid = :auid AND r.cmi5id = :cmi5id AND ast.failed = 1" . $userwhere,
                $baseparams
            );

            // Total registrations for this activity (for completion rate denominator).
            $totalregs = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {cmi5_registrations} r WHERE r.cmi5id = :cmi5id" . $userwhere,
                ['cmi5id' => $cmi5->id] + $userparams
            );
            $completionrate = $totalregs > 0
                ? round(($completedcount / $totalregs) * 100, 1)
                : 0;

            // Average score.
            $avgscore = $DB->get_field_sql(
                "SELECT AVG(ast.score_scaled)
                   FROM {cmi5_au_status} ast
                   JOIN {cmi5_registrations} r ON r.id = ast.registrationid
                  WHERE ast.auid = :auid AND r.cmi5id = :cmi5id
                    AND ast.score_scaled IS NOT NULL" . $userwhere,
                $baseparams
            );

            // Session count.
            $sessioncount = $DB->count_records_sql(
                "SELECT COUNT(*)
                   FROM {cmi5_sessions} s
                   JOIN {cmi5_registrations} r ON r.id = s.registrationid
                  WHERE s.auid = :auid AND r.cmi5id = :cmi5id" . $userwhere,
                $baseparams
            );

            // Average duration (from terminated sessions only).
            $avgduration = $DB->get_field_sql(
                "SELECT AVG(s.timemodified - s.timecreated)
                   FROM {cmi5_sessions} s
                   JOIN {cmi5_registrations} r ON r.id = s.registrationid
                  WHERE s.auid = :auid AND r.cmi5id = :cmi5id
                    AND s.terminated = 1" . $userwhere,
                $baseparams
            );

            $result[] = [
                'auid' => (int) $au->id,
                'title' => format_string($au->title),
                'learnercount' => (int) $learnercount,
                'completedcount' => (int) $completedcount,
                'passedcount' => (int) $passedcount,
                'failedcount' => (int) $failedcount,
                'completionrate' => (float) $completionrate,
                'avgscore' => $avgscore !== false && $avgscore !== null ? round((float) $avgscore, 4) : null,
                'sessioncount' => (int) $sessioncount,
                'avgduration' => $avgduration !== false && $avgduration !== null ? (int) round((float) $avgduration) : 0,
            ];
        }

        return $result;
    }

    /**
     * Describe the return value of the execute function.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'auid' => new external_value(PARAM_INT, 'AU database ID'),
                'title' => new external_value(PARAM_TEXT, 'AU title'),
                'learnercount' => new external_value(PARAM_INT, 'Distinct learner count'),
                'completedcount' => new external_value(PARAM_INT, 'Completed count'),
                'passedcount' => new external_value(PARAM_INT, 'Passed count'),
                'failedcount' => new external_value(PARAM_INT, 'Failed count'),
                'completionrate' => new external_value(PARAM_FLOAT, 'Completion rate percentage'),
                'avgscore' => new external_value(PARAM_FLOAT, 'Average scaled score', VALUE_OPTIONAL),
                'sessioncount' => new external_value(PARAM_INT, 'Total sessions'),
                'avgduration' => new external_value(PARAM_INT, 'Average session duration in seconds'),
            ])
        );
    }
}
