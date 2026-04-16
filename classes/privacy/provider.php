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
 * Privacy subsystem implementation for mod_cmi5.
 *
 * Documents the personal data stored by the plugin and provides
 * methods for exporting and deleting that data per GDPR requirements.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_cmi5.
 *
 * Describes the personal data stored by the cmi5 activity module
 * (registrations, AU status, sessions, xAPI statements) and provides
 * export and deletion handlers.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Describe the personal data stored by this plugin.
     *
     * Documents the cmi5_registrations, cmi5_au_status, cmi5_sessions,
     * and cmi5_statements tables, plus the external LRS link.
     *
     * @param collection $collection The metadata collection to add items to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('cmi5_registrations', [
            'userid' => 'privacy:metadata:cmi5_registrations:userid',
        ], 'privacy:metadata:cmi5_registrations');

        $collection->add_database_table('cmi5_au_status', [
            'registrationid' => 'privacy:metadata:cmi5_au_status',
        ], 'privacy:metadata:cmi5_au_status');

        $collection->add_database_table('cmi5_sessions', [
            'registrationid' => 'privacy:metadata:cmi5_sessions',
        ], 'privacy:metadata:cmi5_sessions');

        $collection->add_database_table('cmi5_statements', [
            'sessionid' => 'privacy:metadata:cmi5_statements',
        ], 'privacy:metadata:cmi5_statements');

        $collection->add_external_location_link('externallrs', [
            'actor' => 'privacy:metadata:externallrs:actor',
            'statements' => 'privacy:metadata:externallrs:statements',
        ], 'privacy:metadata:externallrs');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user data for the given user.
     *
     * Returns module contexts where the user has a cmi5 registration.
     *
     * @param int $userid The Moodle user ID.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {cmi5} c ON c.id = cm.instance
                  JOIN {cmi5_registrations} r ON r.cmi5id = c.id
                 WHERE r.userid = :userid";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'cmi5',
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export all user data for the given approved contexts.
     *
     * Exports registration records, AU status, sessions, and xAPI
     * statements for each context in the list.
     *
     * @param approved_contextlist $contextlist The approved contexts for the user.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('cmi5', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance]);
            if (!$cmi5) {
                continue;
            }

            $registration = $DB->get_record('cmi5_registrations', [
                'cmi5id' => $cmi5->id,
                'userid' => $userid,
            ]);

            if (!$registration) {
                continue;
            }

            // Export the registration data.
            $registrationdata = (object) [
                'registrationid' => $registration->registrationid,
                'coursesatisfied' => $registration->coursesatisfied,
                'timecreated' => \core_privacy\local\request\transform::datetime($registration->timecreated),
                'timemodified' => \core_privacy\local\request\transform::datetime($registration->timemodified),
            ];

            // Export AU status records.
            $austatuses = $DB->get_records('cmi5_au_status', [
                'registrationid' => $registration->id,
            ]);

            $austatusdata = [];
            foreach ($austatuses as $austatus) {
                $au = $DB->get_record('cmi5_aus', ['id' => $austatus->auid]);
                $austatusdata[] = (object) [
                    'au_title' => $au ? $au->title : "AU {$austatus->auid}",
                    'completed' => $austatus->completed,
                    'passed' => $austatus->passed,
                    'failed' => $austatus->failed,
                    'satisfied' => $austatus->satisfied,
                    'waived' => $austatus->waived,
                    'score_scaled' => $austatus->score_scaled,
                    'timecreated' => \core_privacy\local\request\transform::datetime($austatus->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($austatus->timemodified),
                ];
            }

            // Export session records.
            $sessions = $DB->get_records('cmi5_sessions', [
                'registrationid' => $registration->id,
            ]);

            $sessiondata = [];
            $sessionids = [];
            foreach ($sessions as $session) {
                $sessionids[] = $session->id;
                $sessiondata[] = (object) [
                    'sessionid' => $session->sessionid,
                    'launchmode' => $session->launchmode,
                    'initialized' => $session->initialized,
                    'terminated' => $session->terminated,
                    'abandoned' => $session->abandoned,
                    'timecreated' => \core_privacy\local\request\transform::datetime($session->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($session->timemodified),
                ];
            }

            // Export statement records.
            $statementdata = [];
            if (!empty($sessionids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED);
                $statements = $DB->get_records_select('cmi5_statements', "sessionid {$insql}", $inparams);

                foreach ($statements as $statement) {
                    $statementdata[] = (object) [
                        'statementid' => $statement->statementid,
                        'verb' => $statement->verb,
                        'is_cmi5_defined' => $statement->is_cmi5_defined,
                        'timecreated' => \core_privacy\local\request\transform::datetime($statement->timecreated),
                    ];
                }
            }

            $data = (object) [
                'registration' => $registrationdata,
                'au_statuses' => $austatusdata,
                'sessions' => $sessiondata,
                'statements' => $statementdata,
            ];

            writer::with_context($context)->export_data([], $data);
        }
    }

    /**
     * Delete all user data in the given context.
     *
     * Removes all registrations, AU statuses, sessions, statements, and
     * tokens for the cmi5 activity in the given module context.
     *
     * @param \context $context The context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('cmi5', $context->instanceid);
        if (!$cm) {
            return;
        }

        $cmi5id = $cm->instance;

        // Get all registration IDs for this activity.
        $registrations = $DB->get_records('cmi5_registrations', ['cmi5id' => $cmi5id]);

        foreach ($registrations as $registration) {
            self::delete_registration_data($registration->id);
        }

        $DB->delete_records('cmi5_registrations', ['cmi5id' => $cmi5id]);
    }

    /**
     * Delete all user data for the given user in the given contexts.
     *
     * Removes the user's registrations, AU statuses, sessions, statements,
     * and tokens from each cmi5 activity context in the list.
     *
     * @param approved_contextlist $contextlist The approved contexts for the user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('cmi5', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $registration = $DB->get_record('cmi5_registrations', [
                'cmi5id' => $cm->instance,
                'userid' => $userid,
            ]);

            if (!$registration) {
                continue;
            }

            self::delete_registration_data($registration->id);

            $DB->delete_records('cmi5_registrations', ['id' => $registration->id]);
        }
    }

    /**
     * Delete all dependent data for a given registration.
     *
     * Removes AU statuses, sessions (and their tokens and statements)
     * that belong to the specified registration.
     *
     * @param int $registrationid The registration database ID.
     */
    private static function delete_registration_data(int $registrationid): void {
        global $DB;

        // Delete AU status records.
        $DB->delete_records('cmi5_au_status', ['registrationid' => $registrationid]);

        // Delete block status records.
        $DB->delete_records('cmi5_block_status', ['registrationid' => $registrationid]);

        // Get session IDs for cascading deletes.
        $sessions = $DB->get_records('cmi5_sessions', ['registrationid' => $registrationid]);

        foreach ($sessions as $session) {
            // Delete tokens for this session.
            $DB->delete_records('cmi5_tokens', ['sessionid' => $session->id]);

            // Delete statements for this session.
            $DB->delete_records('cmi5_statements', ['sessionid' => $session->id]);
        }

        // Delete sessions.
        $DB->delete_records('cmi5_sessions', ['registrationid' => $registrationid]);
    }
}
