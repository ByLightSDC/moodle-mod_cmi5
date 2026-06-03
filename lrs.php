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
 * xAPI LRS endpoint for mod_cmi5.
 *
 * Full xAPI resource router with dual authentication:
 * - Bearer/Basic token: session-scoped AU authentication via token_manager
 * - Basic API key/secret: standalone LRS access via lrs_auth
 *
 * Also provides backward-compatible OPA auth validation when accessed
 * without PATH_INFO and with a verb query parameter.
 *
 * URL structure: lrs.php/{resource}
 *   /statements          - Statement Resource (GET, PUT, POST)
 *   /activities/state    - State Resource (all methods)
 *   /activities/profile  - Activity Profile Resource (all methods)
 *   /agents/profile      - Agent Profile Resource (all methods)
 *   /agents              - Agents Resource (GET)
 *   /activities          - Activities Resource (GET)
 *   /about               - About Resource (GET)
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// No Moodle session needed - authenticates via bearer token or API key.
define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../config.php');

// CORS headers.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Experience-API-Version, If-Match, If-None-Match');
header('Access-Control-Expose-Headers: X-Experience-API-Version, ETag');
header('X-Experience-API-Version: 1.0.3');
header('Content-Type: application/json');

// Handle CORS preflight.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Extract the resource path from PATH_INFO.
$pathinfo = $_SERVER['PATH_INFO'] ?? '';
$resource = trim($pathinfo, '/');

// Extract Authorization header.
$authheader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authheader = $_SERVER['HTTP_AUTHORIZATION'];
} else if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authheader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} else if (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        $authheader = $headers['Authorization'];
    }
}

// About resource is public - no auth required.
if ($resource === 'about') {
    echo json_encode([
        'version' => ['1.0.3', '1.0.2', '1.0.1', '1.0.0'],
    ]);
    exit;
}

// OPA backward compat: no PATH_INFO + verb param = legacy synthetic statement flow.
if (empty($resource) && isset($_GET['verb']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once(__DIR__ . '/lrs_opa_compat.php');
    exit;
}

if (empty($authheader)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing Authorization header']);
    exit;
}

// Dual auth: try token-based first, then API key.
$authmode = null; // 'session' or 'apikey'.
$session = null;
$sessiondbid = null;

// Try Bearer token.
if (preg_match('/^Bearer\s+(.+)$/i', $authheader, $matches)) {
    try {
        $sessiondbid = \mod_cmi5\token_manager::validate_bearer_token($matches[1]);
        $authmode = 'session';
    } catch (\Exception $e) {
        // Token invalid.
    }
}

// Try Basic auth: first as AU token, then as API key.
if ($authmode === null && preg_match('/^Basic\s+(.+)$/i', $authheader, $matches)) {
    $rawvalue = $matches[1];

    // Try raw value as AU bearer token (how @xapi/cmi5 library sends it).
    try {
        $sessiondbid = \mod_cmi5\token_manager::validate_bearer_token($rawvalue);
        $authmode = 'session';
    } catch (\Exception $e) {
        // Try base64-decoded value as AU bearer token.
        $decoded = base64_decode($rawvalue, true);
        if ($decoded !== false) {
            $parts = explode(':', $decoded, 2);
            $decodedtoken = $parts[0];
            if (!empty($decodedtoken) && $decodedtoken !== $rawvalue) {
                try {
                    $sessiondbid = \mod_cmi5\token_manager::validate_bearer_token($decodedtoken);
                    $authmode = 'session';
                } catch (\Exception $e2) {
                    // Not an AU token.
                }
            }
        }
    }

    // Try as API key/secret.
    if ($authmode === null) {
        $lrsenabled = get_config('mod_cmi5', 'lrs_enabled');
        if ($lrsenabled && \mod_cmi5\lrs_auth::validate($authheader)) {
            $authmode = 'apikey';
        }
    }
}

if ($authmode === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired credentials']);
    exit;
}

// Load session context if session-scoped.
if ($authmode === 'session') {
    $session = $DB->get_record('cmi5_sessions', ['id' => $sessiondbid]);
    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Session not found']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($resource) {
        case 'statements':
            handle_statements($method, $authmode, $session);
            break;

        case 'activities/state':
            handle_state($method, $authmode, $session);
            break;

        case 'activities/profile':
            handle_activity_profile($method);
            break;

        case 'agents/profile':
            handle_agent_profile($method, $authmode, $session);
            break;

        case 'agents':
            handle_agents($method);
            break;

        case 'activities':
            handle_activities($method);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown resource: ' . $resource]);
            break;
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle Statement Resource requests.
 */
function handle_statements(string $method, string $authmode, ?\stdClass $session): void {
    global $CFG;

    if ($method === 'GET') {
        $params = $_GET;

        // Handle 'more' continuation token.
        if (!empty($params['more'])) {
            $decoded = \mod_cmi5\statement_query::decode_more_token($params['more']);
            if ($decoded === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid more token']);
                return;
            }
            $params = $decoded;
        }

        $sessionid = ($authmode === 'session' && $session) ? $session->id : null;
        $moreurl = $CFG->wwwroot . '/mod/cmi5/lrs.php/statements';

        $result = \mod_cmi5\statement_query::query($params, $sessionid, $moreurl);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        return;
    }

    // PUT/POST require session-scoped auth.
    if ($authmode !== 'session' || !$session) {
        http_response_code(403);
        echo json_encode(['error' => 'Statement storage requires session-scoped authentication']);
        return;
    }

    if ($method === 'POST' || $method === 'PUT') {
        $proxy = new \mod_cmi5\xapi_proxy($session);
        $body = file_get_contents('php://input');
        $statementids = $proxy->handle_statements($body);

        http_response_code(200);
        echo json_encode($statementids);

        // Forward to external LRS if configured.
        forward_to_external_lrs($session, $body, $statementids);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

/**
 * Handle State Resource requests.
 */
function handle_state(string $method, string $authmode, ?\stdClass $session): void {
    if ($authmode !== 'session' || !$session) {
        http_response_code(403);
        echo json_encode(['error' => 'State API requires session-scoped authentication']);
        return;
    }

    $proxy = new \mod_cmi5\xapi_proxy($session);
    $body = file_get_contents('php://input');
    $result = $proxy->handle_state_request($method, $_GET, $body);
    if ($result !== null) {
        echo $result;
    }
}

/**
 * Handle Activity Profile Resource requests.
 */
function handle_activity_profile(string $method): void {
    $body = file_get_contents('php://input');
    $result = \mod_cmi5\activity_profile::handle_request($method, $_GET, $body);
    if ($result !== null) {
        echo $result;
    }
}

/**
 * Handle Agent Profile Resource requests.
 */
function handle_agent_profile(string $method, string $authmode, ?\stdClass $session): void {
    if ($authmode !== 'session' || !$session) {
        http_response_code(403);
        echo json_encode(['error' => 'Agent Profile API requires session-scoped authentication']);
        return;
    }

    $proxy = new \mod_cmi5\xapi_proxy($session);
    $body = file_get_contents('php://input');
    $result = $proxy->handle_agent_profile_request($method, $_GET, $body);
    if ($result !== null) {
        echo $result;
    }
}

/**
 * Handle Agents Resource (GET only).
 */
function handle_agents(string $method): void {
    global $DB, $CFG;

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $agentparam = $_GET['agent'] ?? '';
    if (empty($agentparam)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing agent parameter']);
        return;
    }

    $agent = json_decode($agentparam);
    if (!$agent || !isset($agent->account->name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid agent parameter']);
        return;
    }

    // Return Person object.
    $person = [
        'objectType' => 'Person',
        'account' => [
            [
                'homePage' => $agent->account->homePage ?? $CFG->wwwroot,
                'name' => $agent->account->name,
            ],
        ],
    ];

    echo json_encode($person, JSON_UNESCAPED_SLASHES);
}

/**
 * Handle Activities Resource (GET only).
 */
function handle_activities(string $method): void {
    global $DB;

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $activityid = $_GET['activityId'] ?? '';
    if (empty($activityid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing activityId parameter']);
        return;
    }

    // Look up AU by auid IRI.
    $au = $DB->get_record('cmi5_aus', ['auid' => $activityid]);
    if (!$au) {
        // Return minimal activity.
        echo json_encode([
            'objectType' => 'Activity',
            'id' => $activityid,
        ]);
        return;
    }

    $activity = [
        'objectType' => 'Activity',
        'id' => $activityid,
        'definition' => [
            'name' => [
                'en-US' => $au->title,
            ],
        ],
    ];

    if (!empty($au->description)) {
        $activity['definition']['description'] = ['en-US' => $au->description];
    }

    echo json_encode($activity, JSON_UNESCAPED_SLASHES);
}

/**
 * Forward statements to external LRS if configured and evaluate satisfaction.
 */
function forward_to_external_lrs(\stdClass $session, string $body, array $statementids): void {
    global $DB;

    $registration = $DB->get_record('cmi5_registrations', ['id' => $session->registrationid]);
    if (!$registration) {
        return;
    }

    $cmi5 = $DB->get_record('cmi5', ['id' => $registration->cmi5id]);
    if (!$cmi5) {
        return;
    }

    // Forward to external LRS if configured.
    if ($cmi5->lrsmode > 0 && !empty($cmi5->lrsendpoint)) {
        try {
            $lrs = new \mod_cmi5\lrs_client($cmi5->lrsendpoint, $cmi5->lrskey, $cmi5->lrssecret);
            $lrs->send_statement($body);
            // Only mark forwarded if not in "LRS Only" mode.
            if ($cmi5->lrsmode !== 2) {
                foreach ($statementids as $sid) {
                    $DB->set_field('cmi5_statements', 'forwarded', 1, ['statementid' => $sid]);
                }
            }
        } catch (\Exception $e) {
            debugging('LRS forwarding failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    // Evaluate satisfaction after processing statements.
    $evaluator = new \mod_cmi5\satisfaction_evaluator($cmi5);
    $evaluator->evaluate($registration->id);

    $grademanager = new \mod_cmi5\grade_manager($cmi5);
    $grademanager->update_grade($registration->userid);
}
