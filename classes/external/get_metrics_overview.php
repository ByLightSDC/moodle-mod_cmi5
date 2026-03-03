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
 * External function to get metrics overview for a cmi5 activity.
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
 * External function for retrieving activity-wide metrics overview.
 *
 * Returns KPIs (registrations, active sessions, completion/pass/fail rates),
 * statements-over-time data with verb breakdown, and verb distribution.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_metrics_overview extends external_api {

    /** @var array Known verb IRI to display name mapping. */
    private const VERB_LABELS = [
        'http://adlnet.gov/expapi/verbs/initialized' => 'initialized',
        'http://adlnet.gov/expapi/verbs/terminated' => 'terminated',
        'http://adlnet.gov/expapi/verbs/passed' => 'passed',
        'http://adlnet.gov/expapi/verbs/failed' => 'failed',
        'http://adlnet.gov/expapi/verbs/completed' => 'completed',
        'http://adlnet.gov/expapi/verbs/experienced' => 'experienced',
        'http://adlnet.gov/expapi/verbs/attempted' => 'attempted',
        'http://adlnet.gov/expapi/verbs/launched' => 'launched',
        'https://w3id.org/xapi/adl/verbs/waived' => 'waived',
        'https://w3id.org/xapi/adl/verbs/satisfied' => 'satisfied',
        'https://w3id.org/xapi/adl/verbs/abandoned' => 'abandoned',
    ];

    /**
     * Describe the parameters for the execute function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'days' => new external_value(PARAM_INT, 'Number of days to look back (0 = all time)', VALUE_DEFAULT, 30),
            'userid' => new external_value(PARAM_INT, 'Filter by user (0 = all)', VALUE_DEFAULT, 0),
            'groupby' => new external_value(PARAM_ALPHA, 'Timeline grouping: verb or user', VALUE_DEFAULT, 'verb'),
        ]);
    }

    /**
     * Resolve a verb IRI to a human-readable label.
     *
     * @param string $verbiri The full verb IRI.
     * @return string Human-readable verb label.
     */
    private static function resolve_verb_label(string $verbiri): string {
        // Check known mapping first.
        if (isset(self::VERB_LABELS[$verbiri])) {
            return self::VERB_LABELS[$verbiri];
        }

        // Extract last path segment.
        $parts = explode('/', rtrim($verbiri, '/'));
        $segment = end($parts);

        // If it looks like a hex hash (16+ hex chars), show "other".
        if (preg_match('/^[0-9a-f]{16,}$/i', $segment)) {
            return 'other';
        }

        return $segment;
    }

    /**
     * Get metrics overview for a cmi5 activity.
     *
     * @param int $cmid The course module ID.
     * @param int $days Number of days to look back (0 = all time).
     * @param int $userid Filter by user (0 = all).
     * @param string $groupby Timeline grouping: 'verb' or 'user'.
     * @return array Metrics data including KPIs, timeline, and verb distribution.
     */
    public static function execute(int $cmid, int $days = 30, int $userid = 0, string $groupby = 'verb'): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'days' => $days,
            'userid' => $userid,
            'groupby' => $groupby,
        ]);
        $cmid = $params['cmid'];
        $days = $params['days'];
        $userid = $params['userid'];
        $groupby = $params['groupby'];
        if (!in_array($groupby, ['verb', 'user'])) {
            $groupby = 'verb';
        }

        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'cmi5');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/cmi5:view', $context);

        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $isteacher = has_capability('mod/cmi5:viewreports', $context);

        // Build user scope filter for student view.
        $userwhere = '';
        $userparams = [];
        if (!$isteacher) {
            $userwhere = ' AND r.userid = :userid';
            $userparams['userid'] = $USER->id;
        } else if ($userid > 0) {
            // Teacher filtering by specific learner.
            $userwhere = ' AND r.userid = :filteruserid';
            $userparams['filteruserid'] = $userid;
        }

        // KPI: Total registrations.
        $totalregistrations = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {cmi5_registrations} r WHERE r.cmi5id = :cmi5id" . $userwhere,
            ['cmi5id' => $cmi5->id] + $userparams
        );

        // KPI: Active sessions (created in last 24h, not terminated/abandoned).
        $activecutoff = time() - 86400;
        $activesessions = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {cmi5_sessions} s
               JOIN {cmi5_registrations} r ON r.id = s.registrationid
              WHERE r.cmi5id = :cmi5id
                AND s.timecreated > :cutoff
                AND s.terminated = 0
                AND s.abandoned = 0" . $userwhere,
            ['cmi5id' => $cmi5->id, 'cutoff' => $activecutoff] + $userparams
        );

        // KPI: Completion rate (registrations with coursesatisfied).
        $satisfiedcount = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {cmi5_registrations} r
              WHERE r.cmi5id = :cmi5id AND r.coursesatisfied = 1" . $userwhere,
            ['cmi5id' => $cmi5->id] + $userparams
        );
        $completionrate = $totalregistrations > 0
            ? round(($satisfiedcount / $totalregistrations) * 100, 1)
            : 0;

        // KPI: Pass/fail counts from au_status.
        $passcount = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ast.id)
               FROM {cmi5_au_status} ast
               JOIN {cmi5_registrations} r ON r.id = ast.registrationid
              WHERE r.cmi5id = :cmi5id AND ast.passed = 1" . $userwhere,
            ['cmi5id' => $cmi5->id] + $userparams
        );
        $failcount = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ast.id)
               FROM {cmi5_au_status} ast
               JOIN {cmi5_registrations} r ON r.id = ast.registrationid
              WHERE r.cmi5id = :cmi5id AND ast.failed = 1" . $userwhere,
            ['cmi5id' => $cmi5->id] + $userparams
        );

        // Date range filter.
        $datewhere = '';
        $dateparams = [];
        if ($days > 0) {
            $datewhere = ' AND st.timecreated >= :since';
            $dateparams['since'] = time() - ($days * 86400);
        }

        // Statements over time — fetch with verb and userid for flexible grouping.
        $selectfields = 'st.id, st.timecreated, st.verb, r.userid';
        $stmtrows = $DB->get_records_sql(
            "SELECT {$selectfields}
               FROM {cmi5_statements} st
               JOIN {cmi5_sessions} s ON s.id = st.sessionid
               JOIN {cmi5_registrations} r ON r.id = s.registrationid
              WHERE r.cmi5id = :cmi5id" . $datewhere . $userwhere . "
              ORDER BY st.timecreated ASC",
            ['cmi5id' => $cmi5->id] + $dateparams + $userparams
        );

        // Build a userid->fullname map if grouping by user (teacher only).
        $usernames = [];
        if ($groupby === 'user' && $isteacher) {
            $userids = [];
            foreach ($stmtrows as $row) {
                $userids[$row->userid] = true;
            }
            if ($userids) {
                $useridlist = array_keys($userids);
                list($insql, $inparams) = $DB->get_in_or_equal($useridlist, SQL_PARAMS_NAMED);
                $users = $DB->get_records_sql(
                    "SELECT id, firstname, lastname FROM {user} WHERE id {$insql}",
                    $inparams
                );
                foreach ($users as $u) {
                    $usernames[$u->id] = fullname($u);
                }
            }
        }

        // Bucket by (day, series) where series is verb label or username.
        $daybuckets = [];
        $seriesset = [];
        foreach ($stmtrows as $row) {
            $daykey = gmdate('Y-m-d', $row->timecreated);
            if ($groupby === 'user' && $isteacher) {
                $series = $usernames[$row->userid] ?? 'User ' . $row->userid;
            } else {
                $series = self::resolve_verb_label($row->verb);
            }
            if (!isset($daybuckets[$daykey])) {
                $daybuckets[$daykey] = [];
            }
            if (!isset($daybuckets[$daykey][$series])) {
                $daybuckets[$daykey][$series] = 0;
            }
            $daybuckets[$daykey][$series]++;
            $seriesset[$series] = true;
        }

        // Build timeline.
        $timeline = [];
        $timelineverbs = array_keys($seriesset);
        sort($timelineverbs);

        if ($days > 0) {
            for ($i = $days - 1; $i >= 0; $i--) {
                $day = gmdate('Y-m-d', time() - ($i * 86400));
                if (isset($daybuckets[$day])) {
                    foreach ($daybuckets[$day] as $series => $count) {
                        $timeline[] = [
                            'date' => $day,
                            'verb' => $series,
                            'count' => $count,
                        ];
                    }
                }
            }
        } else {
            // All time — emit all days with data.
            ksort($daybuckets);
            foreach ($daybuckets as $day => $seriesdata) {
                foreach ($seriesdata as $series => $count) {
                    $timeline[] = [
                        'date' => $day,
                        'verb' => $series,
                        'count' => $count,
                    ];
                }
            }
        }

        // Verb distribution (top 10) with resolved labels.
        $verbs = $DB->get_records_sql(
            "SELECT st.verb, COUNT(*) AS cnt
               FROM {cmi5_statements} st
               JOIN {cmi5_sessions} s ON s.id = st.sessionid
               JOIN {cmi5_registrations} r ON r.id = s.registrationid
              WHERE r.cmi5id = :cmi5id" . $datewhere . $userwhere . "
              GROUP BY st.verb
              ORDER BY cnt DESC",
            ['cmi5id' => $cmi5->id] + $dateparams + $userparams,
            0, 10
        );

        $verbdist = [];
        foreach ($verbs as $v) {
            $verbdist[] = [
                'verb' => self::resolve_verb_label($v->verb),
                'count' => (int) $v->cnt,
            ];
        }

        return [
            'totalregistrations' => (int) $totalregistrations,
            'activesessions' => (int) $activesessions,
            'completionrate' => (float) $completionrate,
            'passcount' => (int) $passcount,
            'failcount' => (int) $failcount,
            'timeline' => $timeline,
            'timelineverbs' => $timelineverbs,
            'verbdistribution' => $verbdist,
        ];
    }

    /**
     * Describe the return value of the execute function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'totalregistrations' => new external_value(PARAM_INT, 'Total registrations'),
            'activesessions' => new external_value(PARAM_INT, 'Active sessions in last 24h'),
            'completionrate' => new external_value(PARAM_FLOAT, 'Completion rate percentage'),
            'passcount' => new external_value(PARAM_INT, 'Total AU passes'),
            'failcount' => new external_value(PARAM_INT, 'Total AU failures'),
            'timeline' => new external_multiple_structure(
                new external_single_structure([
                    'date' => new external_value(PARAM_TEXT, 'Date (Y-m-d)'),
                    'verb' => new external_value(PARAM_TEXT, 'Verb label'),
                    'count' => new external_value(PARAM_INT, 'Statement count'),
                ])
            ),
            'timelineverbs' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Verb label')
            ),
            'verbdistribution' => new external_multiple_structure(
                new external_single_structure([
                    'verb' => new external_value(PARAM_TEXT, 'Verb label'),
                    'count' => new external_value(PARAM_INT, 'Usage count'),
                ])
            ),
        ]);
    }
}
