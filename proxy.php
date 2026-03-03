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
 * xAPI proxy endpoint - receives and processes xAPI requests from AU content.
 *
 * URL format: proxy.php/{session-uuid}/statements
 *             proxy.php/{session-uuid}/activities/state
 *             proxy.php/{session-uuid}/agents/profile
 *
 * The @xapi/cmi5 library uses the endpoint URL as a base and appends
 * xAPI resource paths to it (e.g. /statements, /activities/state).
 *
 * Auth: Bearer token in Authorization header.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// No Moodle session needed - AU content authenticates via bearer token.
define('NO_MOODLE_COOKIES', true);
// Prevent Moodle from redirecting to login or outputting HTML.
define('AJAX_SCRIPT', true);
// Prevent Moodle from buffering output and adding headers.
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../config.php');

// CORS headers - must be sent before any output.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Experience-API-Version');
header('Access-Control-Expose-Headers: X-Experience-API-Version');
header('X-Experience-API-Version: 1.0.3');

// Handle CORS preflight.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Extract bearer token from Authorization header.
$authheader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authheader = $_SERVER['HTTP_AUTHORIZATION'];
} else if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    // Some servers (e.g. Apache with CGI) put it here.
    $authheader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} else if (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        $authheader = $headers['Authorization'];
    }
}

$bearertoken = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authheader, $matches)) {
    $bearertoken = $matches[1];
} else if (preg_match('/^Basic\s+(.+)$/i', $authheader, $matches)) {
    // The @xapi/cmi5 library wraps the auth-token as "Basic {token}".
    // The token is the raw bearer token, not a user:pass pair.
    $bearertoken = $matches[1];
}

if (empty($bearertoken)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing or invalid Authorization header']);
    exit;
}

// Validate the bearer token.
try {
    $sessiondbid = \mod_cmi5\token_manager::validate_bearer_token($bearertoken);
} catch (\Exception $e) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Load the session.
$session = $DB->get_record('cmi5_sessions', ['id' => $sessiondbid]);
if (!$session) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Session not found']);
    exit;
}

$proxy = new \mod_cmi5\xapi_proxy($session);

// Determine request method and which xAPI sub-API is being called.
$method = $_SERVER['REQUEST_METHOD'];

// Extract the xAPI resource path from PATH_INFO.
// URL: proxy.php/{session-uuid}/statements → PATH_INFO = /{session-uuid}/statements
// URL: proxy.php/{session-uuid}/activities/state?stateId=... → PATH_INFO = /{session-uuid}/activities/state
$pathinfo = $_SERVER['PATH_INFO'] ?? '';
$pathinfo = trim($pathinfo, '/');

// The path format is: {session-uuid}/{xapi-resource-path}
// Split off the session UUID (first segment).
$pathparts = explode('/', $pathinfo, 2);
$xapipath = $pathparts[1] ?? '';

// Determine which xAPI sub-API is being called based on the path and query params.
$stateid = isset($_GET['stateId']) ? $_GET['stateId'] : '';
$profileid = isset($_GET['profileId']) ? $_GET['profileId'] : '';

try {
    if ($xapipath === 'activities/state' || !empty($stateid)) {
        // State API.
        $params = $_GET;
        if (!empty($stateid)) {
            $params['stateId'] = $stateid;
        }
        $body = file_get_contents('php://input');
        $result = $proxy->handle_state_request($method, $params, $body);
        header('Content-Type: application/json');
        if ($result !== null) {
            echo $result;
        }

    } else if ($xapipath === 'agents/profile' || !empty($profileid)) {
        // Agent Profile API.
        $params = $_GET;
        if (!empty($profileid)) {
            $params['profileId'] = $profileid;
        }
        $body = file_get_contents('php://input');
        $result = $proxy->handle_agent_profile_request($method, $params, $body);
        header('Content-Type: application/json');
        if ($result !== null) {
            echo $result;
        }

    } else if ($xapipath === 'activities/profile') {
        // Activity Profile API.
        $body = file_get_contents('php://input');
        $result = \mod_cmi5\activity_profile::handle_request($method, $_GET, $body);
        header('Content-Type: application/json');
        if ($result !== null) {
            echo $result;
        }

    } else if ($xapipath === 'statements' && ($method === 'POST' || $method === 'PUT')) {
        // Statements API - store.
        $body = file_get_contents('php://input');
        $statementids = $proxy->handle_statements($body);

        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode($statementids);

        // Forward to external LRS if configured.
        $registration = $DB->get_record('cmi5_registrations', ['id' => $session->registrationid]);
        if ($registration) {
            $cmi5 = $DB->get_record('cmi5', ['id' => $registration->cmi5id]);
            if ($cmi5 && $cmi5->lrsmode > 0 && !empty($cmi5->lrsendpoint)) {
                try {
                    $lrs = new \mod_cmi5\lrs_client($cmi5->lrsendpoint, $cmi5->lrskey, $cmi5->lrssecret);
                    $lrs->send_statement($body);
                    // Mark statements as forwarded.
                    foreach ($statementids as $sid) {
                        $DB->set_field('cmi5_statements', 'forwarded', 1, ['statementid' => $sid]);
                    }
                } catch (\Exception $e) {
                    debugging('LRS forwarding failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // Evaluate satisfaction after processing statements.
            $evaluator = new \mod_cmi5\satisfaction_evaluator($cmi5);
            $coursesatisfied = $evaluator->evaluate($registration->id);

            // Update grades.
            $grademanager = new \mod_cmi5\grade_manager($cmi5);
            $grademanager->update_grade($registration->userid);
        }

    } else if ($xapipath === 'statements' && $method === 'GET') {
        // GET statements - delegate to query engine, scoped to this session.
        $params = $_GET;
        $moreurl = $CFG->wwwroot . '/mod/cmi5/proxy.php/' . $session->sessionid . '/statements';
        $result = \mod_cmi5\statement_query::query($params, $session->id, $moreurl);
        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_SLASHES);

    } else {
        // Fallback: check query params for backward compat.
        if (!empty($stateid)) {
            $body = file_get_contents('php://input');
            $result = $proxy->handle_state_request($method, $_GET, $body);
            header('Content-Type: application/json');
            if ($result !== null) {
                echo $result;
            }
        } else if (!empty($profileid)) {
            $body = file_get_contents('php://input');
            $result = $proxy->handle_agent_profile_request($method, $_GET, $body);
            header('Content-Type: application/json');
            if ($result !== null) {
                echo $result;
            }
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unknown xAPI resource path: ' . $xapipath]);
        }
    }
} catch (\Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
