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
 * External function to reset or delete several learner registrations at once.
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
 * External function to bulk reset or delete learner registrations for an activity.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_registration_action extends external_api {

    /**
     * Describe the parameters for the execute function.
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'A user ID whose registration to act on'),
                'The users to act on'
            ),
            'action' => new external_value(PARAM_ALPHA, 'Either "reset" or "delete"'),
        ]);
    }

    /**
     * Reset or delete the registrations of several learners in one transaction.
     *
     * @param int $cmid The course module ID.
     * @param int[] $userids The users whose registrations to act on.
     * @param string $action Either "reset" (keep the registration) or "delete".
     * @return array Summary with per-user results.
     */
    public static function execute(int $cmid, array $userids, string $action): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/cmi5/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'userids' => $userids,
            'action' => $action,
        ]);
        $cmid = $params['cmid'];
        $action = $params['action'];
        $userids = array_values(array_unique(array_map('intval', $params['userids'])));

        if ($action !== 'reset' && $action !== 'delete') {
            throw new \invalid_parameter_exception('action must be "reset" or "delete"');
        }

        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'cmi5');
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/cmi5:managecontent', $context);

        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);

        $results = [];
        if (empty($userids)) {
            return ['action' => $action, 'requested' => 0, 'processed' => 0, 'results' => $results];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $registrations = $DB->get_records_select('cmi5_registrations',
            "cmi5id = :cmi5id AND userid $insql",
            array_merge(['cmi5id' => $cmi5->id], $inparams));

        $found = [];
        foreach ($registrations as $reg) {
            $found[(int) $reg->userid] = $reg;
        }

        $transaction = $DB->start_delegated_transaction();
        foreach ($registrations as $reg) {
            if ($action === 'delete') {
                \mod_cmi5\registration::delete($reg);
            } else {
                \mod_cmi5\registration::purge_state($reg);
            }
        }
        $transaction->allow_commit();

        // Refresh the gradebook and log an event per affected learner.
        foreach ($registrations as $reg) {
            $userid = (int) $reg->userid;
            cmi5_update_grades($cmi5, $userid, true);

            $eventclass = $action === 'delete'
                ? \mod_cmi5\event\registration_deleted::class
                : \mod_cmi5\event\registration_reset::class;
            $eventclass::create([
                'context' => $context,
                'objectid' => $reg->id,
                'relateduserid' => $userid,
                'other' => ['cmi5id' => $cmi5->id],
            ])->trigger();
        }

        foreach ($userids as $userid) {
            $results[] = [
                'userid' => $userid,
                'status' => isset($found[$userid]) ? $action : 'notfound',
            ];
        }

        return [
            'action' => $action,
            'requested' => count($userids),
            'processed' => count($registrations),
            'results' => $results,
        ];
    }

    /**
     * Describe the return value of the execute function.
     *
     * @return external_single_structure The return value definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'action' => new external_value(PARAM_ALPHA, 'The action performed'),
            'requested' => new external_value(PARAM_INT, 'How many distinct users were requested'),
            'processed' => new external_value(PARAM_INT, 'How many registrations were reset or deleted'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'The user ID'),
                    'status' => new external_value(PARAM_ALPHA, 'reset, delete, or notfound'),
                ])
            ),
        ]);
    }
}
