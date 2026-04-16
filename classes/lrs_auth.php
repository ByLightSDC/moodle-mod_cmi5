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
 * API key/secret authentication for the standalone LRS endpoint.
 *
 * Validates Basic auth credentials against plugin config settings.
 * The secret is stored as a SHA256 hash.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

class lrs_auth {

    /**
     * Validate Basic auth credentials against the configured API key/secret.
     *
     * @param string $authheader The raw Authorization header value.
     * @return bool True if credentials are valid.
     */
    public static function validate(string $authheader): bool {
        if (!preg_match('/^Basic\s+(.+)$/i', $authheader, $matches)) {
            return false;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return false;
        }

        [$key, $secret] = explode(':', $decoded, 2);

        $configkey = get_config('mod_cmi5', 'lrs_api_key');
        $configsecret = get_config('mod_cmi5', 'lrs_api_secret');

        if (empty($configkey) || empty($configsecret)) {
            return false;
        }

        if ($key !== $configkey) {
            return false;
        }

        // Secret is stored as SHA256 hash.
        return hash_equals($configsecret, hash('sha256', $secret));
    }

    /**
     * Generate a new API key (32 hex chars).
     *
     * @return string
     */
    public static function generate_key(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a new API secret and return both plaintext and hash.
     *
     * @return array ['plaintext' => string, 'hash' => string]
     */
    public static function generate_secret(): array {
        $plaintext = bin2hex(random_bytes(24));
        return [
            'plaintext' => $plaintext,
            'hash' => hash('sha256', $plaintext),
        ];
    }
}
