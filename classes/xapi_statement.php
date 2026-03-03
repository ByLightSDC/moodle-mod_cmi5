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
 * xAPI statement builder and validator for cmi5 activity module.
 *
 * Provides static methods for constructing cmi5-defined xAPI statements
 * (Satisfied, Abandoned) and for validating incoming statements.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds and validates xAPI statements for cmi5 sessions.
 *
 * Handles construction of LMS-originated statements (Satisfied, Abandoned)
 * and validation of AU-originated statements against cmi5 rules.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xapi_statement {

    /**
     * Build a Satisfied statement as per the cmi5 specification.
     *
     * The Satisfied statement is issued by the LMS when an AU, block,
     * or the course meets its satisfaction criteria.
     *
     * @param \stdClass $cmi5 The cmi5 activity instance record.
     * @param \stdClass $auorblock The AU or block record (must have auid/blockid and title).
     * @param string $registration The registration UUID string.
     * @param int $userid The Moodle user ID.
     * @param string $type Either 'au' or 'block' to indicate the object type.
     * @return string JSON-encoded xAPI statement.
     */
    public static function build_satisfied_statement(\stdClass $cmi5, \stdClass $auorblock,
            string $registration, int $userid, string $type = 'au'): string {

        $actor = self::get_actor($userid);

        // Determine the object IRI and title based on type.
        if ($type === 'au') {
            $objectid = $auorblock->auid;
            $objecttitle = $auorblock->title;
        } else {
            $objectid = $auorblock->blockid;
            $objecttitle = $auorblock->title;
        }

        $storediso = gmdate('Y-m-d\TH:i:s.000\Z');

        $statement = [
            'id' => \core\uuid::generate(),
            'actor' => $actor,
            'verb' => [
                'id' => 'https://w3id.org/xapi/adl/verbs/satisfied',
                'display' => [
                    'en-US' => 'satisfied',
                ],
            ],
            'object' => [
                'id' => $objectid,
                'definition' => [
                    'name' => [
                        'en-US' => $objecttitle,
                    ],
                ],
                'objectType' => 'Activity',
            ],
            'context' => [
                'registration' => $registration,
                'contextActivities' => [
                    'grouping' => [
                        [
                            'id' => $cmi5->courseid_iri,
                        ],
                    ],
                ],
                'extensions' => [
                    'https://w3id.org/xapi/cmi5/context/extensions/sessionid' => 'LMS-generated',
                ],
            ],
            'timestamp' => date('c'),
            'stored' => $storediso,
            'authority' => self::get_authority(),
        ];

        return json_encode($statement, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build an Abandoned statement as per the cmi5 specification.
     *
     * The Abandoned statement is issued by the LMS when a session
     * is determined to have been abandoned (initialized but not
     * terminated within the timeout period).
     *
     * @param \stdClass $cmi5 The cmi5 activity instance record.
     * @param \stdClass $au The AU record from cmi5_aus.
     * @param string $registration The registration UUID string.
     * @param \stdClass $session The session record from cmi5_sessions.
     * @param int $userid The Moodle user ID.
     * @return string JSON-encoded xAPI statement.
     */
    public static function build_abandoned_statement(\stdClass $cmi5, \stdClass $au,
            string $registration, \stdClass $session, int $userid): string {

        $actor = self::get_actor($userid);

        // Calculate duration from session creation to now.
        $duration = time() - $session->timecreated;
        $isoduration = 'PT' . $duration . 'S';

        $storediso = gmdate('Y-m-d\TH:i:s.000\Z');

        $statement = [
            'id' => \core\uuid::generate(),
            'actor' => $actor,
            'verb' => [
                'id' => 'https://w3id.org/xapi/adl/verbs/abandoned',
                'display' => [
                    'en-US' => 'abandoned',
                ],
            ],
            'object' => [
                'id' => $au->auid,
                'definition' => [
                    'name' => [
                        'en-US' => $au->title,
                    ],
                ],
                'objectType' => 'Activity',
            ],
            'result' => [
                'duration' => $isoduration,
            ],
            'context' => [
                'registration' => $registration,
                'contextActivities' => [
                    'grouping' => [
                        [
                            'id' => $cmi5->courseid_iri,
                        ],
                    ],
                ],
                'extensions' => [
                    'https://w3id.org/xapi/cmi5/context/extensions/sessionid' => $session->sessionid,
                ],
            ],
            'timestamp' => date('c'),
            'stored' => $storediso,
            'authority' => self::get_authority(),
        ];

        return json_encode($statement, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Validate an incoming xAPI statement against cmi5 requirements.
     *
     * Checks that the statement actor matches the expected account-based
     * actor and that the context registration matches the expected
     * registration UUID.
     *
     * @param \stdClass $statementobj The decoded statement object.
     * @param \stdClass $expectedactor The expected actor object (account-based).
     * @param string $expectedregistration The expected registration UUID.
     * @return bool True if validation passes.
     * @throws \moodle_exception If validation fails.
     */
    public static function validate_statement(\stdClass $statementobj, \stdClass $expectedactor,
            string $expectedregistration): bool {

        // Validate actor.
        if (!isset($statementobj->actor->account->homePage) ||
                !isset($statementobj->actor->account->name)) {
            throw new \moodle_exception('invalidstatement', 'mod_cmi5', '',
                'Statement actor must use account-based identification.');
        }

        if ($statementobj->actor->account->homePage !== $expectedactor->account->homePage) {
            throw new \moodle_exception('invalidstatement', 'mod_cmi5', '',
                'Statement actor homePage does not match expected value.');
        }

        if ($statementobj->actor->account->name !== $expectedactor->account->name) {
            throw new \moodle_exception('invalidstatement', 'mod_cmi5', '',
                'Statement actor name does not match expected value.');
        }

        // Validate registration.
        if (!isset($statementobj->context->registration)) {
            throw new \moodle_exception('invalidstatement', 'mod_cmi5', '',
                'Statement must include context.registration.');
        }

        if ($statementobj->context->registration !== $expectedregistration) {
            throw new \moodle_exception('invalidstatement', 'mod_cmi5', '',
                'Statement registration does not match expected value.');
        }

        return true;
    }

    /**
     * Build the account-based actor object for a given user.
     *
     * Uses the Moodle site wwwroot as the account homePage and the
     * user's email as the account name for human-readable identification
     * in downstream systems.
     *
     * @param int $userid The Moodle user ID.
     * @return \stdClass The actor object with account-based identification.
     */
    public static function get_actor(int $userid): \stdClass {
        global $CFG;

        $user = \core_user::get_user($userid, 'id,email', MUST_EXIST);
        $actor = new \stdClass();
        $actor->objectType = 'Agent';
        $actor->account = new \stdClass();
        $actor->account->homePage = $CFG->wwwroot;
        $actor->account->name = $user->email;

        return $actor;
    }

    /**
     * Build the authority Agent for LRS-stored statements.
     *
     * Uses the Moodle site wwwroot as the account homePage and
     * 'mod_cmi5' as the account name.
     *
     * @return \stdClass The authority Agent object.
     */
    public static function get_authority(): \stdClass {
        global $CFG;

        $authority = new \stdClass();
        $authority->objectType = 'Agent';
        $authority->account = new \stdClass();
        $authority->account->homePage = $CFG->wwwroot;
        $authority->account->name = 'mod_cmi5';

        return $authority;
    }
}
