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
 * OPA backward-compatible LRS endpoint.
 *
 * Included from lrs.php when PATH_INFO is empty and a verb query param is present.
 * Returns a synthetic xAPI statement for OPA/devops-api auth validation.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('NO_MOODLE_COOKIES') || die();

// Override version header for OPA compat.
header('X-Experience-API-Version: 2.0.0');

// $authheader is set by the including lrs.php.
if (empty($authheader)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing Authorization header']);
    exit;
}

// Extract the token value.
$token = '';
$rawvalue = '';
if (preg_match('/^Basic\s+(.+)$/i', $authheader, $matches)) {
    $rawvalue = $matches[1];
    $token = $rawvalue;
} else if (preg_match('/^Bearer\s+(.+)$/i', $authheader, $matches)) {
    $token = $matches[1];
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid Authorization header format']);
    exit;
}

// Validate the token.
$sessiondbid = null;
try {
    $sessiondbid = \mod_cmi5\token_manager::validate_bearer_token($token);
} catch (\Exception $e) {
    // Try base64 decode fallback.
    $decoded = base64_decode($rawvalue ?: '', true);
    if ($decoded !== false) {
        $parts = explode(':', $decoded, 2);
        $decodedtoken = $parts[0];
        if (!empty($decodedtoken) && $decodedtoken !== $token) {
            try {
                $sessiondbid = \mod_cmi5\token_manager::validate_bearer_token($decodedtoken);
            } catch (\Exception $e2) {
                // Both attempts failed.
            }
        }
    }
}

if ($sessiondbid === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token', 'statements' => []]);
    exit;
}

// Load session -> registration -> user -> AU.
$session = $DB->get_record('cmi5_sessions', ['id' => $sessiondbid]);
if (!$session) {
    http_response_code(404);
    echo json_encode(['error' => 'Session not found', 'statements' => []]);
    exit;
}

$registration = $DB->get_record('cmi5_registrations', ['id' => $session->registrationid]);
if (!$registration) {
    http_response_code(404);
    echo json_encode(['error' => 'Registration not found', 'statements' => []]);
    exit;
}

$user = $DB->get_record('user', ['id' => $registration->userid]);
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found', 'statements' => []]);
    exit;
}

$au = $DB->get_record('cmi5_aus', ['id' => $session->auid]);
if (!$au) {
    http_response_code(404);
    echo json_encode(['error' => 'AU not found', 'statements' => []]);
    exit;
}

$cmi5 = $DB->get_record('cmi5', ['id' => $registration->cmi5id]);
if (!$cmi5) {
    http_response_code(404);
    echo json_encode(['error' => 'Activity not found', 'statements' => []]);
    exit;
}

// Build the synthetic xAPI statement.
$wwwroot = $CFG->wwwroot;

$statement = [
    'id' => $session->sessionid,
    'actor' => [
        'objectType' => 'Agent',
        'account' => [
            'homePage' => $wwwroot,
            'name' => $user->username,
        ],
    ],
    'verb' => [
        'id' => 'https://rangeos.engineering/auth/' . hash('sha256', $token),
        'display' => [
            'en-US' => 'authenticated',
        ],
    ],
    'object' => [
        'objectType' => 'Activity',
        'id' => $au->auid,
    ],
    'context' => [
        'registration' => $registration->registrationid,
        'contextActivities' => [
            'grouping' => [
                [
                    'objectType' => 'Activity',
                    'id' => $au->auid,
                ],
            ],
        ],
    ],
    'timestamp' => date('c'),
];

if (!empty($cmi5->courseid_iri)) {
    $statement['object']['id'] = $cmi5->courseid_iri;
}

echo json_encode([
    'statements' => [$statement],
    'more' => '',
]);
