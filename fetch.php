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
 * Fetch URL endpoint - exchanges a fetch token for a bearer token.
 *
 * Called by the AU content (via @xapi/cmi5 library) to obtain an auth
 * token for xAPI requests. The library POSTs to this URL with the fetch
 * token in the query string and an empty body.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// No Moodle session needed - AU content calls this endpoint.
define('NO_MOODLE_COOKIES', true);
// Prevent Moodle from redirecting to login or outputting HTML.
define('AJAX_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../config.php');

// CORS headers.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// The @xapi/cmi5 library POSTs to the full fetch URL. The fetch token
// is in the query string as ?token=xxx and the POST body is empty.
$fetchtoken = isset($_GET['token']) ? $_GET['token'] : '';

// Also check POST body for token (other cmi5 clients may send it in the body).
if (empty($fetchtoken)) {
    $rawpost = file_get_contents('php://input');
    if (!empty($rawpost)) {
        // Could be form-encoded or JSON.
        $decoded = json_decode($rawpost);
        if ($decoded && isset($decoded->token)) {
            $fetchtoken = $decoded->token;
        } else {
            parse_str($rawpost, $postparams);
            if (isset($postparams['token'])) {
                $fetchtoken = $postparams['token'];
            }
        }
    }
}

// Sanitize: fetch tokens are 64-char hex strings.
$fetchtoken = preg_replace('/[^a-f0-9]/i', '', $fetchtoken);

if (empty($fetchtoken)) {
    http_response_code(400);
    echo json_encode(['error-code' => '1', 'error-text' => 'Missing fetch token']);
    exit;
}

try {
    // Validate and consume the fetch token.
    $sessionid = \mod_cmi5\token_manager::validate_and_consume_fetch_token($fetchtoken);

    // Create a bearer token for the AU to use.
    $bearertoken = \mod_cmi5\token_manager::create_bearer_token($sessionid);

    // Return the auth token per cmi5 spec.
    http_response_code(200);
    echo json_encode([
        'auth-token' => $bearertoken,
    ]);
} catch (\Exception $e) {
    http_response_code(401);
    echo json_encode([
        'error-code' => '1',
        'error-text' => $e->getMessage(),
    ]);
}
