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
 * LRS client for cmi5 activity module.
 *
 * Handles forwarding xAPI statements to an external Learning Record
 * Store via HTTP with Basic authentication.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Client for communicating with an external LRS.
 *
 * Sends xAPI statements to a configured LRS endpoint using Basic
 * authentication and the xAPI 1.0.3 protocol.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lrs_client {

    /** @var string The LRS endpoint URL. */
    private $endpoint;

    /** @var string The LRS authentication key (username). */
    private $key;

    /** @var string The LRS authentication secret (password). */
    private $secret;

    /**
     * Constructor.
     *
     * @param string $endpoint The LRS endpoint URL (e.g. https://lrs.example.com/xapi/).
     * @param string $key The Basic auth key/username.
     * @param string $secret The Basic auth secret/password.
     */
    public function __construct(string $endpoint, string $key, string $secret) {
        // Ensure endpoint has trailing slash.
        $this->endpoint = rtrim($endpoint, '/') . '/';
        $this->key = $key;
        $this->secret = $secret;
    }

    /**
     * Send a single xAPI statement to the LRS.
     *
     * Posts the statement JSON to the LRS statements endpoint using
     * Basic authentication and xAPI 1.0.3 headers.
     *
     * @param string $statementjson JSON-encoded xAPI statement.
     * @return bool True on successful submission (2xx response).
     * @throws \moodle_exception On HTTP errors or connection failures.
     */
    public function send_statement(string $statementjson): bool {
        return $this->post_to_lrs($statementjson);
    }

    /**
     * Send multiple xAPI statements to the LRS as an array.
     *
     * Posts the statements as a JSON array to the LRS statements
     * endpoint in a single request.
     *
     * @param array $statementsarray Array of JSON-encoded statement strings.
     * @return bool True on successful submission (2xx response).
     * @throws \moodle_exception On HTTP errors or connection failures.
     */
    public function send_statements(array $statementsarray): bool {
        if (empty($statementsarray)) {
            return true;
        }

        // Decode each statement string and re-encode as a JSON array.
        $decoded = [];
        foreach ($statementsarray as $json) {
            $stmt = json_decode($json);
            if ($stmt === null) {
                throw new \moodle_exception('invalidjson', 'mod_cmi5', '',
                    'Invalid JSON in statement array element.');
            }
            $decoded[] = $stmt;
        }

        $payload = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        return $this->post_to_lrs($payload);
    }

    /**
     * Forward all un-forwarded statements for a cmi5 activity instance.
     *
     * Retrieves all statements with forwarded=0 that belong to sessions
     * under registrations of the given cmi5 instance, sends them to
     * the LRS, and marks them as forwarded.
     *
     * @param int $cmi5id The cmi5 activity instance ID.
     * @return int The number of statements successfully forwarded.
     * @throws \moodle_exception On LRS communication errors.
     */
    public function forward_pending(int $cmi5id): int {
        global $DB;

        // Get all un-forwarded statements for this cmi5 instance.
        $sql = "SELECT s.*
                  FROM {cmi5_statements} s
                  JOIN {cmi5_sessions} sess ON sess.id = s.sessionid
                  JOIN {cmi5_registrations} r ON r.id = sess.registrationid
                 WHERE r.cmi5id = :cmi5id
                   AND s.forwarded = 0
              ORDER BY s.timecreated ASC";

        $statements = $DB->get_records_sql($sql, ['cmi5id' => $cmi5id]);

        if (empty($statements)) {
            return 0;
        }

        // Collect statement JSON strings.
        $jsons = [];
        foreach ($statements as $stmt) {
            $jsons[] = $stmt->statement_json;
        }

        // Send all statements in a single batch.
        $this->send_statements($jsons);

        // Mark all as forwarded.
        $ids = array_keys($statements);
        foreach ($ids as $id) {
            $DB->set_field('cmi5_statements', 'forwarded', 1, ['id' => $id]);
        }

        return count($ids);
    }

    /**
     * Post a JSON payload to the LRS statements endpoint.
     *
     * @param string $payload The JSON payload (single statement or array).
     * @return bool True on success.
     * @throws \moodle_exception On HTTP errors or connection failures.
     */
    private function post_to_lrs(string $payload): bool {
        $url = $this->endpoint . 'statements';
        $auth = base64_encode($this->key . ':' . $this->secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . $auth,
                'X-Experience-API-Version: 1.0.3',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \moodle_exception('lrsconnectionerror', 'mod_cmi5', '',
                'Failed to connect to LRS: ' . $error);
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \moodle_exception('lrserror', 'mod_cmi5', '',
                'LRS returned HTTP ' . $httpcode . ': ' . $response);
        }

        return true;
    }
}
