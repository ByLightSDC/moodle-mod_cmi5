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
 * xAPI proxy for cmi5 activity module.
 *
 * Handles incoming xAPI requests from the AU, validates them against
 * cmi5 session rules, stores statements locally, and manages State
 * and Agent Profile API requests.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Proxies xAPI requests from cmi5 AUs.
 *
 * Validates, processes, and stores xAPI statements received from AUs
 * during a cmi5 session. Enforces cmi5 session lifecycle rules and
 * updates AU status tracking based on cmi5-defined verbs.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xapi_proxy
{

    /** @var \stdClass The session record from cmi5_sessions. */
    private $session;

    /** @var \stdClass|null Cached registration record. */
    private $registration = null;

    /**
     * Map of cmi5-defined verb IRIs.
     *
     * @var array
     */
    private const CMI5_VERBS = [
        'http://adlnet.gov/expapi/verbs/initialized' => 'initialized',
        'http://adlnet.gov/expapi/verbs/terminated' => 'terminated',
        'http://adlnet.gov/expapi/verbs/passed' => 'passed',
        'http://adlnet.gov/expapi/verbs/failed' => 'failed',
        'http://adlnet.gov/expapi/verbs/completed' => 'completed',
        'https://w3id.org/xapi/adl/verbs/waived' => 'waived',
        'https://w3id.org/xapi/adl/verbs/satisfied' => 'satisfied',
        'https://w3id.org/xapi/adl/verbs/abandoned' => 'abandoned',
    ];

    /**
     * Constructor.
     *
     * @param \stdClass $sessionrecord The session record from the cmi5_sessions table.
     */
    public function __construct(\stdClass $sessionrecord)
    {
        $this->session = $sessionrecord;
    }

    /**
     * Validate and process incoming xAPI statements.
     *
     * Accepts a JSON string containing a single statement or an array
     * of statements. Validates each statement against cmi5 actor and
     * registration requirements, enforces session lifecycle rules, stores
     * each statement, and updates session and AU status flags.
     *
     * @param string $statementsjson JSON string of statement(s).
     * @return array Array of stored statement UUIDs.
     * @throws \moodle_exception On validation or lifecycle rule violations.
     */
    public function handle_statements(string $statementsjson): array
    {
        global $DB, $CFG;

        $decoded = json_decode($statementsjson);
        if ($decoded === null) {
            throw new \moodle_exception('invalidjson', 'mod_cmi5', '', 'Invalid JSON in statement body.');
        }

        // Normalize single statement to array.
        if (!is_array($decoded)) {
            $decoded = [$decoded];
        }

        $registration = $this->get_registration_record();
        $expectedactor = xapi_statement::get_actor($registration->userid);
        $expectedregistration = $registration->registrationid;

        // Retrieve record and check LRS mode.
        $cmi5record = $DB->get_record('cmi5', ['id' => $registration->cmi5id]);
        $lrsmode = $cmi5record ? (int)$cmi5record->lrsmode : 0;

        $statementids = [];

        // Build authority agent.
        $authority = xapi_statement::get_authority();

        foreach ($decoded as $statement) {
            // Validate actor and registration.
            xapi_statement::validate_statement($statement, $expectedactor, $expectedregistration);

            $verbid = $statement->verb->id ?? '';

            // Enforce cmi5 session lifecycle rules.
            $this->enforce_session_rules($verbid);

            // Determine if this is a cmi5-defined statement.
            $iscmi5defined = $this->is_cmi5_defined_verb($verbid) ? 1 : 0;

            // Generate a statement ID if not provided.
            $statementuuid = $statement->id ?? \core\uuid::generate();
            if (!isset($statement->id)) {
                $statement->id = $statementuuid;
            }

            // Inject stored timestamp and authority.
            $now = time();
            $storediso = gmdate('Y-m-d\TH:i:s.000\Z', $now);
            $statement->stored = $storediso;
            $statement->authority = $authority;

            // Compute denormalized fields.
            $actorhash = null;
            if (isset($statement->actor->account->homePage, $statement->actor->account->name)) {
                $actorhash = sha1($statement->actor->account->homePage . '|' . $statement->actor->account->name);
            }
            $activityid = null;
            if (isset($statement->object->id)) {
                $objectid = $statement->object->id;
                $activityid = (strlen($objectid) > 255) ? substr($objectid, 0, 255) : $objectid;
            }
            $stmtregistration = $statement->context->registration ?? null;

            // If LRS mode is not "LRS Only", save to local DB.
            if ($lrsmode !== 2) {
                $record = new \stdClass();
                $record->sessionid = $this->session->id;
                $record->statementid = $statementuuid;
                $record->verb = $verbid;
                $record->statement_json = json_encode($statement, JSON_UNESCAPED_SLASHES);
                $record->is_cmi5_defined = $iscmi5defined;
                $record->forwarded = 0;
                $record->stored = $storediso;
                $record->authority_json = json_encode($authority, JSON_UNESCAPED_SLASHES);
                $record->voided = 0;
                $record->actor_hash = $actorhash;
                $record->activity_id = $activityid;
                $record->registration = $stmtregistration;
                $record->timecreated = $now;
                $DB->insert_record('cmi5_statements', $record);
            }

            // Handle voiding: if this is a voiding statement, mark the target as voided.
            if ($verbid === 'http://adlnet.gov/expapi/verbs/voided') {
                $this->process_voiding($statement);
            }

            // Update session flags based on verb.
            $this->update_session_flags($verbid);

            if ($verbid === 'http://adlnet.gov/expapi/verbs/progressed') {
                $this->update_progress_score($statement);
            }

            // Update AU status based on cmi5-defined verbs.
            if ($iscmi5defined) {
                $this->update_au_status($verbid, $statement);
            }

            $statementids[] = $statementuuid;
        }

        return $statementids;
    }

    /**
     * Handle xAPI State API requests.
     *
     * For GET requests with stateId=LMS.LaunchData, returns the launch data
     * document on-the-fly. LMS.LaunchData is read-only per cmi5 spec.
     * Other state documents are persisted in cmi5_state_documents.
     *
     * @param string $method The HTTP method (GET, PUT, POST, DELETE).
     * @param array $params The request parameters (stateId, activityId, agent, registration).
     * @param string $body The request body content.
     * @return string|null The response body, or null for write operations.
     */
    public function handle_state_request(string $method, array $params, string $body = ''): ?string
    {
        global $DB;

        $stateid = $params['stateId'] ?? '';
        $activityid = $params['activityId'] ?? '';
        $registration = $this->get_registration_record();

        // LMS.LaunchData is read-only per cmi5 spec.
        if ($stateid === 'LMS.LaunchData') {
            if ($method === 'GET') {
                $cmi5 = $DB->get_record('cmi5', ['id' => $registration->cmi5id], '*', MUST_EXIST);
                $au = $DB->get_record('cmi5_aus', ['id' => $this->session->auid], '*', MUST_EXIST);
                return state_document::build_launch_data($cmi5, $au, $registration, $this->session);
            }
            http_response_code(403);
            return json_encode(['error' => 'LMS.LaunchData is read-only']);
        }

        $activityidhash = sha1($activityid);
        $lookupparams = [
            'registrationid' => $registration->id,
            'activityidhash' => $activityidhash,
            'stateid' => $stateid,
        ];

        if ($method === 'PUT') {
            $now = time();
            $existing = $DB->get_record('cmi5_state_documents', $lookupparams);
            etag_validator::validate_write($existing ? $existing->etag : null);
            if ($existing) {
                $existing->document = $body;
                $existing->etag = sha1($body);
                $existing->timemodified = $now;
                $DB->update_record('cmi5_state_documents', $existing);
            } else {
                $record = (object) array_merge($lookupparams, [
                    'activityid' => $activityid,
                    'document' => $body,
                    'etag' => sha1($body),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->insert_record('cmi5_state_documents', $record);
            }
            http_response_code(204);
            return null;
        }

        if ($method === 'POST') {
            // xAPI POST merges JSON documents.
            $now = time();
            $existing = $DB->get_record('cmi5_state_documents', $lookupparams);
            etag_validator::validate_write($existing ? $existing->etag : null);
            if ($existing) {
                $existingdecoded = json_decode($existing->document, true);
                $newdecoded = json_decode($body, true);
                if (is_array($existingdecoded) && is_array($newdecoded)) {
                    $merged = json_encode(
                        array_replace_recursive($existingdecoded, $newdecoded),
                        JSON_UNESCAPED_SLASHES
                    );
                } else {
                    // If either isn't valid JSON, replace entirely.
                    $merged = $body;
                }
                $existing->document = $merged;
                $existing->etag = sha1($merged);
                $existing->timemodified = $now;
                $DB->update_record('cmi5_state_documents', $existing);
            } else {
                $record = (object) array_merge($lookupparams, [
                    'activityid' => $activityid,
                    'document' => $body,
                    'etag' => sha1($body),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->insert_record('cmi5_state_documents', $record);
            }
            http_response_code(204);
            return null;
        }

        if ($method === 'DELETE') {
            $DB->delete_records('cmi5_state_documents', $lookupparams);
            http_response_code(204);
            return null;
        }

        if ($method === 'GET') {
            $record = $DB->get_record('cmi5_state_documents', $lookupparams);
            if ($record) {
                if (etag_validator::check_not_modified($record->etag)) {
                    http_response_code(304);
                    return null;
                }
                header('ETag: "' . $record->etag . '"');
                return $record->document;
            }
            http_response_code(404);
            return json_encode(['error' => 'State document not found']);
        }

        return null;
    }

    /**
     * Handle xAPI Agent Profile API requests.
     *
     * Profile documents are persisted in cmi5_agent_profiles per user.
     * For cmi5LearnerPreferences GET, falls back to the default builder
     * if no stored document exists.
     *
     * @param string $method The HTTP method (GET, PUT, POST, DELETE).
     * @param array $params The request parameters (profileId, agent).
     * @param string $body The request body content.
     * @return string|null The response body, or null for write operations.
     */
    public function handle_agent_profile_request(string $method, array $params, string $body = ''): ?string
    {
        global $DB;

        $profileid = $params['profileId'] ?? '';
        $registration = $this->get_registration_record();
        $userid = $registration->userid;

        $lookupparams = [
            'userid' => $userid,
            'profileid' => $profileid,
        ];

        if ($method === 'GET') {
            $record = $DB->get_record('cmi5_agent_profiles', $lookupparams);
            if ($record) {
                if (etag_validator::check_not_modified($record->etag)) {
                    http_response_code(304);
                    return null;
                }
                header('ETag: "' . $record->etag . '"');
                return $record->document;
            }
            // Fall back to default for cmi5LearnerPreferences.
            if ($profileid === 'cmi5LearnerPreferences') {
                return agent_profile::build_learner_preferences($userid);
            }
            http_response_code(404);
            return json_encode(['error' => 'Agent profile not found']);
        }

        if ($method === 'PUT') {
            $now = time();
            $existing = $DB->get_record('cmi5_agent_profiles', $lookupparams);
            etag_validator::validate_write($existing ? $existing->etag : null);
            if ($existing) {
                $existing->document = $body;
                $existing->etag = sha1($body);
                $existing->timemodified = $now;
                $DB->update_record('cmi5_agent_profiles', $existing);
            } else {
                $record = (object) array_merge($lookupparams, [
                    'document' => $body,
                    'etag' => sha1($body),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->insert_record('cmi5_agent_profiles', $record);
            }
            http_response_code(204);
            return null;
        }

        if ($method === 'POST') {
            // xAPI POST merges JSON documents.
            $now = time();
            $existing = $DB->get_record('cmi5_agent_profiles', $lookupparams);
            etag_validator::validate_write($existing ? $existing->etag : null);
            if ($existing) {
                $existingdecoded = json_decode($existing->document, true);
                $newdecoded = json_decode($body, true);
                if (is_array($existingdecoded) && is_array($newdecoded)) {
                    $merged = json_encode(
                        array_replace_recursive($existingdecoded, $newdecoded),
                        JSON_UNESCAPED_SLASHES
                    );
                } else {
                    $merged = $body;
                }
                $existing->document = $merged;
                $existing->etag = sha1($merged);
                $existing->timemodified = $now;
                $DB->update_record('cmi5_agent_profiles', $existing);
            } else {
                $record = (object) array_merge($lookupparams, [
                    'document' => $body,
                    'etag' => sha1($body),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->insert_record('cmi5_agent_profiles', $record);
            }
            http_response_code(204);
            return null;
        }

        if ($method === 'DELETE') {
            $DB->delete_records('cmi5_agent_profiles', $lookupparams);
            http_response_code(204);
            return null;
        }

        return null;
    }

    /**
     * Check if a verb IRI is one of the 9 cmi5-defined verbs.
     *
     * @param string $verbid The verb IRI to check.
     * @return bool True if the verb is cmi5-defined.
     */
    private function is_cmi5_defined_verb(string $verbid): bool
    {
        return array_key_exists($verbid, self::CMI5_VERBS);
    }

    /**
     * Enforce cmi5 session lifecycle rules.
     *
     * Ensures that: initialized must be the first cmi5-defined statement,
     * only one initialized is allowed per session, no statements are
     * accepted after terminated, and terminated ends the session.
     *
     * @param string $verbid The verb IRI of the current statement.
     * @throws \moodle_exception On lifecycle rule violations.
     */
    private function enforce_session_rules(string $verbid): void
    {
        // Reload session to get current state.
        global $DB;
        $this->session = $DB->get_record('cmi5_sessions', ['id' => $this->session->id], '*', MUST_EXIST);

        // No statements after terminated.
        if ($this->session->terminated) {
            throw new \moodle_exception(
                'sessionterminated',
                'mod_cmi5',
                '',
                'Session has been terminated. No further statements are accepted.'
            );
        }

        // Check cmi5-defined verb rules.
        if ($this->is_cmi5_defined_verb($verbid)) {
            $verbname = self::CMI5_VERBS[$verbid];

            // Initialized must come first (before any other cmi5-defined verb).
            if ($verbname !== 'initialized' && !$this->session->initialized) {
                throw new \moodle_exception(
                    'notinitialised',
                    'mod_cmi5',
                    '',
                    'Session must be initialized before sending other cmi5-defined statements.'
                );
            }

            // Only one initialized per session.
            if ($verbname === 'initialized' && $this->session->initialized) {
                throw new \moodle_exception(
                    'alreadyinitialised',
                    'mod_cmi5',
                    '',
                    'Session has already been initialized.'
                );
            }
        }
    }

    /**
     * Update session flags based on the verb.
     *
     * Sets the initialized or terminated flag on the session record
     * when the corresponding verb is received.
     *
     * @param string $verbid The verb IRI.
     */
    private function update_session_flags(string $verbid): void
    {
        if ($verbid === 'http://adlnet.gov/expapi/verbs/initialized') {
            session::mark_initialized($this->session->id);
            $this->session->initialized = 1;
        } else if ($verbid === 'http://adlnet.gov/expapi/verbs/terminated') {
            session::mark_terminated($this->session->id);
            $this->session->terminated = 1;
        }
    }

    /**
     * Update AU status based on the cmi5-defined verb.
     *
     * Sets completed, passed, failed, waived flags and extracts
     * score.scaled when present on passed/failed statements.
     *
     * @param string $verbid The verb IRI.
     * @param \stdClass $statement The decoded statement object.
     */
    private function update_au_status(string $verbid, \stdClass $statement): void
    {
        global $DB;

        $registration = $this->get_registration_record();

        // Get or create the au_status record.
        $austatus = $DB->get_record('cmi5_au_status', [
            'registrationid' => $registration->id,
            'auid' => $this->session->auid,
        ]);

        if (!$austatus) {
            $austatus = new \stdClass();
            $austatus->registrationid = $registration->id;
            $austatus->auid = $this->session->auid;
            $austatus->completed = 0;
            $austatus->passed = 0;
            $austatus->failed = 0;
            $austatus->satisfied = 0;
            $austatus->waived = 0;
            $austatus->score_scaled = null;
            $austatus->timecreated = time();
            $austatus->timemodified = time();
            $austatus->id = $DB->insert_record('cmi5_au_status', $austatus);
        }

        $verbname = self::CMI5_VERBS[$verbid] ?? '';
        $updated = false;

        switch ($verbname) {
            case 'completed':
                $austatus->completed = 1;
                $updated = true;
                break;

            case 'passed':
                $austatus->passed = 1;
                $updated = true;
                // Extract score.scaled if present.
                if (isset($statement->result->score->scaled)) {
                    $austatus->score_scaled = (float) $statement->result->score->scaled;
                }
                break;

            case 'failed':
                $austatus->failed = 1;
                $updated = true;
                // Extract score.scaled if present.
                if (isset($statement->result->score->scaled)) {
                    $austatus->score_scaled = (float) $statement->result->score->scaled;
                }
                break;

            case 'waived':
                $austatus->waived = 1;
                $updated = true;
                break;
        }

        if ($updated) {
            $austatus->timemodified = time();
            $DB->update_record('cmi5_au_status', $austatus);
        }
    }

    /**
     * Apply a score from a progress (non-cmi5-defined) statement to au_status.
     *
     * Only writes the score if no score from a passed/failed statement has been
     * recorded yet. This means passed/failed scores always take precedence.
     *
     * @param \stdClass $statement The decoded statement object.
     */
    private function update_progress_score(\stdClass $statement): void
    {
        global $DB;

        $registration = $this->get_registration_record();
        // Progress extension is 0–100 (integer percent); normalize to 0.0–1.0 to match score_scaled range.
        // Extensions are decoded as stdClass, so use object property access (not array syntax).
        $progressext = 'https://w3id.org/xapi/cmi5/result/extensions/progress';
        $rawprogress = $statement->result->extensions->{$progressext} ?? null;
        $score = $rawprogress !== null ? (float) $rawprogress / 100.0 : null;
        $austatus = $DB->get_record('cmi5_au_status', [
            'registrationid' => $registration->id,
            'auid' => $this->session->auid,
        ]);

        if ($austatus) {
            // Don't overwrite a score already set by a passed/failed statement.
            if ($austatus->score_scaled !== null && ($austatus->passed || $austatus->failed)) {
                return;
            }
            // Nothing to store if the extension was absent.
            if ($score === null) {
                return;
            }
            $austatus->score_scaled = $score;
            $austatus->timemodified = time();
            $DB->update_record('cmi5_au_status', $austatus);
        } else if ($score !== null) {
            $austatus = new \stdClass();
            $austatus->registrationid = $registration->id;
            $austatus->auid = $this->session->auid;
            $austatus->completed = 0;
            $austatus->passed = 0;
            $austatus->failed = 0;
            $austatus->satisfied = 0;
            $austatus->waived = 0;
            $austatus->score_scaled = $score;
            $austatus->timecreated = time();
            $austatus->timemodified = time();
            $DB->insert_record('cmi5_au_status', $austatus);
        }
    }

    /**
     * Process a voiding statement by marking the target statement as voided.
     *
     * @param \stdClass $statement The voiding statement (verb = voided).
     */
    private function process_voiding(\stdClass $statement): void
    {
        global $DB;

        // Voiding statement must reference a StatementRef.
        if (!isset($statement->object->objectType) || $statement->object->objectType !== 'StatementRef') {
            return;
        }
        if (empty($statement->object->id)) {
            return;
        }

        $targetid = $statement->object->id;
        $target = $DB->get_record('cmi5_statements', ['statementid' => $targetid]);
        if ($target && !$target->voided) {
            $DB->set_field('cmi5_statements', 'voided', 1, ['id' => $target->id]);
        }
    }

    /**
     * Get the registration record for this session.
     *
     * Loads and caches the registration record associated with the
     * current session.
     *
     * @return \stdClass The registration record from cmi5_registrations.
     */
    private function get_registration_record(): \stdClass
    {
        global $DB;

        if ($this->registration === null) {
            $this->registration = $DB->get_record(
                'cmi5_registrations',
                ['id' => $this->session->registrationid],
                '*',
                MUST_EXIST
            );
        }

        return $this->registration;
    }
}
