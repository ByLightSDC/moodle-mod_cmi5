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
 * External function to get learner progress for a cmi5 activity.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
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
 * External function for retrieving per-learner progress data.
 *
 * Teachers see a summary table of all learners or drill-down for one learner.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_learner_progress extends external_api {

    /**
     * Describe the parameters for the execute function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'userid' => new external_value(PARAM_INT, 'User ID (0 = all learners summary)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get learner progress for a cmi5 activity.
     *
     * @param int $cmid The course module ID.
     * @param int $userid User ID for drill-down, or 0 for summary.
     * @return array Learner progress data.
     */
    public static function execute(int $cmid, int $userid = 0): array {
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
        require_capability('mod/cmi5:viewreports', $context);

        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);

        if ($userid === 0) {
            return self::get_summary($cmi5, $context);
        }
        return self::get_drilldown($cmi5, $userid);
    }

    /**
     * Get summary of all learners.
     *
     * @param object $cmi5 The cmi5 instance record.
     * @param \context_module $context The module context.
     * @return array Summary data with 'learners' key.
     */
    private static function get_summary(object $cmi5, \context_module $context): array {
        global $DB;

        $registrations = $DB->get_records('cmi5_registrations', ['cmi5id' => $cmi5->id]);
        $learners = [];

        foreach ($registrations as $reg) {
            $user = \core_user::get_user($reg->userid, 'id,firstname,lastname,email');
            if (!$user) {
                continue;
            }

            // Count AU statuses.
            $completedcount = $DB->count_records_select('cmi5_au_status',
                'registrationid = :rid AND completed = 1',
                ['rid' => $reg->id]
            );
            $passedcount = $DB->count_records_select('cmi5_au_status',
                'registrationid = :rid AND passed = 1',
                ['rid' => $reg->id]
            );

            // Average score.
            $avgscore = $DB->get_field_sql(
                "SELECT AVG(score_scaled) FROM {cmi5_au_status}
                  WHERE registrationid = :rid AND score_scaled IS NOT NULL",
                ['rid' => $reg->id]
            );

            // Last active (most recent session timemodified).
            $lastactive = $DB->get_field_sql(
                "SELECT MAX(s.timemodified) FROM {cmi5_sessions} s
                  WHERE s.registrationid = :rid",
                ['rid' => $reg->id]
            );

            $learners[] = [
                'userid' => (int) $reg->userid,
                'fullname' => fullname($user),
                'email' => $user->email,
                'registrationdate' => (int) $reg->timecreated,
                'completedcount' => (int) $completedcount,
                'passedcount' => (int) $passedcount,
                'avgscore' => $avgscore !== false && $avgscore !== null ? round((float) $avgscore, 4) : null,
                'lastactive' => $lastactive ? (int) $lastactive : 0,
                'coursesatisfied' => (int) $reg->coursesatisfied,
            ];
        }

        return [
            'mode' => 'summary',
            'learners' => $learners,
            'austatuses' => [],
            'sessions' => [],
            'learnername' => '',
        ];
    }

    /**
     * Get drill-down data for a single learner.
     *
     * @param object $cmi5 The cmi5 instance record.
     * @param int $userid The user to drill down into.
     * @return array Drill-down data with AU statuses and sessions.
     */
    private static function get_drilldown(object $cmi5, int $userid): array {
        global $DB;

        $user = \core_user::get_user($userid, 'id,firstname,lastname');
        $learnername = $user ? fullname($user) : '';

        $registration = $DB->get_record('cmi5_registrations', [
            'cmi5id' => $cmi5->id,
            'userid' => $userid,
        ]);

        $austatuses = [];
        $sessions = [];

        if ($registration) {
            // AU statuses with titles.
            $rows = $DB->get_records_sql(
                "SELECT ast.id, a.title, ast.completed, ast.passed, ast.failed,
                        ast.satisfied, ast.score_scaled, ast.timemodified
                   FROM {cmi5_au_status} ast
                   JOIN {cmi5_aus} a ON a.id = ast.auid
                  WHERE ast.registrationid = :rid
                  ORDER BY a.sortorder ASC",
                ['rid' => $registration->id]
            );

            foreach ($rows as $row) {
                $austatuses[] = [
                    'title' => $row->title,
                    'completed' => (int) $row->completed,
                    'passed' => (int) $row->passed,
                    'failed' => (int) $row->failed,
                    'satisfied' => (int) $row->satisfied,
                    'score' => $row->score_scaled !== null ? round((float) $row->score_scaled, 4) : null,
                    'timemodified' => (int) $row->timemodified,
                ];
            }

            // Sessions with AU title and duration.
            $sessrows = $DB->get_records_sql(
                "SELECT s.id, a.title AS autitle, s.initialized, s.terminated,
                        s.abandoned, s.timecreated, s.timemodified
                   FROM {cmi5_sessions} s
                   JOIN {cmi5_aus} a ON a.id = s.auid
                  WHERE s.registrationid = :rid
                  ORDER BY s.timecreated DESC",
                ['rid' => $registration->id]
            );

            foreach ($sessrows as $sess) {
                $duration = 0;
                if ($sess->terminated || $sess->abandoned) {
                    $duration = max(0, (int) $sess->timemodified - (int) $sess->timecreated);
                }
                $sessions[] = [
                    'autitle' => $sess->autitle,
                    'initialized' => (int) $sess->initialized,
                    'terminated' => (int) $sess->terminated,
                    'abandoned' => (int) $sess->abandoned,
                    'duration' => $duration,
                    'timecreated' => (int) $sess->timecreated,
                ];
            }
        }

        return [
            'mode' => 'drilldown',
            'learners' => [],
            'austatuses' => $austatuses,
            'sessions' => $sessions,
            'learnername' => $learnername,
        ];
    }

    /**
     * Describe the return value of the execute function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'mode' => new external_value(PARAM_ALPHA, 'Response mode: summary or drilldown'),
            'learnername' => new external_value(PARAM_TEXT, 'Learner full name (drilldown only)'),
            'learners' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                    'registrationdate' => new external_value(PARAM_INT, 'Registration timestamp'),
                    'completedcount' => new external_value(PARAM_INT, 'AUs completed'),
                    'passedcount' => new external_value(PARAM_INT, 'AUs passed'),
                    'avgscore' => new external_value(PARAM_FLOAT, 'Average score', VALUE_OPTIONAL),
                    'lastactive' => new external_value(PARAM_INT, 'Last active timestamp'),
                    'coursesatisfied' => new external_value(PARAM_INT, 'Course satisfied flag'),
                ])
            ),
            'austatuses' => new external_multiple_structure(
                new external_single_structure([
                    'title' => new external_value(PARAM_TEXT, 'AU title'),
                    'completed' => new external_value(PARAM_INT, 'Completed flag'),
                    'passed' => new external_value(PARAM_INT, 'Passed flag'),
                    'failed' => new external_value(PARAM_INT, 'Failed flag'),
                    'satisfied' => new external_value(PARAM_INT, 'Satisfied flag'),
                    'score' => new external_value(PARAM_FLOAT, 'Scaled score', VALUE_OPTIONAL),
                    'timemodified' => new external_value(PARAM_INT, 'Last modified timestamp'),
                ])
            ),
            'sessions' => new external_multiple_structure(
                new external_single_structure([
                    'autitle' => new external_value(PARAM_TEXT, 'AU title'),
                    'initialized' => new external_value(PARAM_INT, 'Initialized flag'),
                    'terminated' => new external_value(PARAM_INT, 'Terminated flag'),
                    'abandoned' => new external_value(PARAM_INT, 'Abandoned flag'),
                    'duration' => new external_value(PARAM_INT, 'Duration in seconds'),
                    'timecreated' => new external_value(PARAM_INT, 'Session start timestamp'),
                ])
            ),
        ]);
    }
}
