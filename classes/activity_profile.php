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
 * xAPI Activity Profile API handler for mod_cmi5.
 *
 * CRUD operations for activity profile documents, following the same
 * pattern as agent profiles and state documents.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

class activity_profile {

    /**
     * Handle an xAPI Activity Profile API request.
     *
     * @param string $method HTTP method (GET, PUT, POST, DELETE).
     * @param array $params Request parameters (activityId, profileId).
     * @param string $body Request body content.
     * @return string|null Response body, or null for write operations.
     */
    public static function handle_request(string $method, array $params, string $body = ''): ?string {
        global $DB;

        $activityid = $params['activityId'] ?? '';
        $profileid = $params['profileId'] ?? '';

        if (empty($activityid)) {
            http_response_code(400);
            return json_encode(['error' => 'Missing activityId parameter']);
        }

        $activityidhash = sha1($activityid);

        // GET without profileId returns list of profileId strings.
        if ($method === 'GET' && empty($profileid)) {
            $since = $params['since'] ?? '';
            $conditions = ['activityidhash' => $activityidhash];
            $records = $DB->get_records('cmi5_activity_profiles', $conditions, '', 'profileid, timemodified');
            $ids = [];
            foreach ($records as $rec) {
                if (!empty($since)) {
                    $sinceiso = $since;
                    $sincetime = strtotime($sinceiso);
                    if ($sincetime !== false && $rec->timemodified <= $sincetime) {
                        continue;
                    }
                }
                $ids[] = $rec->profileid;
            }
            return json_encode($ids);
        }

        if (empty($profileid)) {
            http_response_code(400);
            return json_encode(['error' => 'Missing profileId parameter']);
        }

        $lookupparams = [
            'activityidhash' => $activityidhash,
            'profileid' => $profileid,
        ];

        if ($method === 'GET') {
            $record = $DB->get_record('cmi5_activity_profiles', $lookupparams);
            if (!$record) {
                http_response_code(404);
                return json_encode(['error' => 'Activity profile not found']);
            }

            if (etag_validator::check_not_modified($record->etag)) {
                http_response_code(304);
                return null;
            }

            header('ETag: "' . $record->etag . '"');
            return $record->document;
        }

        if ($method === 'PUT') {
            $existing = $DB->get_record('cmi5_activity_profiles', $lookupparams);
            etag_validator::validate_write($existing ? $existing->etag : null);

            $now = time();
            if ($existing) {
                $existing->document = $body;
                $existing->etag = sha1($body);
                $existing->timemodified = $now;
                $DB->update_record('cmi5_activity_profiles', $existing);
            } else {
                $record = (object) array_merge($lookupparams, [
                    'activityid' => $activityid,
                    'document' => $body,
                    'etag' => sha1($body),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->insert_record('cmi5_activity_profiles', $record);
            }
            http_response_code(204);
            return null;
        }

        if ($method === 'POST') {
            $existing = $DB->get_record('cmi5_activity_profiles', $lookupparams);
            etag_validator::validate_write($existing ? $existing->etag : null);

            $now = time();
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
                $DB->update_record('cmi5_activity_profiles', $existing);
            } else {
                $record = (object) array_merge($lookupparams, [
                    'activityid' => $activityid,
                    'document' => $body,
                    'etag' => sha1($body),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->insert_record('cmi5_activity_profiles', $record);
            }
            http_response_code(204);
            return null;
        }

        if ($method === 'DELETE') {
            $existing = $DB->get_record('cmi5_activity_profiles', $lookupparams);
            if ($existing) {
                etag_validator::validate_write($existing->etag);
                $DB->delete_records('cmi5_activity_profiles', $lookupparams);
            }
            http_response_code(204);
            return null;
        }

        return null;
    }
}
