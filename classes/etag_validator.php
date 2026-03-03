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
 * ETag concurrency control for xAPI document resources.
 *
 * Implements If-Match / If-None-Match validation per xAPI spec.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

class etag_validator {

    /**
     * Validate ETag preconditions for write operations (PUT/POST).
     *
     * @param string|null $storedetag The current stored ETag, or null if document doesn't exist.
     * @return void Sends 409 or 412 and exits on failure.
     */
    public static function validate_write(?string $storedetag): void {
        $ifmatch = self::get_header('If-Match');
        $ifnonematch = self::get_header('If-None-Match');

        if ($ifnonematch === '*' && $storedetag !== null) {
            // Client expects no existing document, but one exists.
            http_response_code(412);
            echo json_encode(['error' => 'Resource already exists (If-None-Match: *)']);
            exit;
        }

        if ($ifmatch !== null && $storedetag !== null) {
            $expected = trim($ifmatch, '"');
            if ($expected !== $storedetag) {
                http_response_code(409);
                echo json_encode(['error' => 'ETag mismatch (If-Match)']);
                exit;
            }
        }
    }

    /**
     * Check If-None-Match for GET requests. Returns true if 304 should be sent.
     *
     * @param string|null $storedetag The current stored ETag.
     * @return bool True if the client's cached version is current.
     */
    public static function check_not_modified(?string $storedetag): bool {
        if ($storedetag === null) {
            return false;
        }

        $ifnonematch = self::get_header('If-None-Match');
        if ($ifnonematch === null) {
            return false;
        }

        $expected = trim($ifnonematch, '"');
        return $expected === $storedetag;
    }

    /**
     * Get a request header value.
     *
     * @param string $name The header name (case-insensitive).
     * @return string|null The header value, or null if not present.
     */
    private static function get_header(string $name): ?string {
        // Try the standard PHP approach first.
        $serverkey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverkey])) {
            return $_SERVER[$serverkey];
        }

        // Fallback for Apache.
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
            }
        }

        return null;
    }
}
