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
 * State document builder for cmi5 activity module.
 *
 * Constructs the LMS.LaunchData state document as defined by the
 * cmi5 specification.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the LMS.LaunchData xAPI state document for cmi5 sessions.
 *
 * The LMS.LaunchData document is stored in the xAPI state API and provides
 * the AU with context about the launch including the context template,
 * launch mode, moveOn criteria, and optional mastery score.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_document {

    /**
     * Build the LMS.LaunchData state document.
     *
     * Constructs the JSON document that the AU retrieves from the State API
     * at the start of a session. Contains the context template, launch
     * parameters, and other cmi5-defined launch data.
     *
     * @param \stdClass $cmi5 The cmi5 activity instance record.
     * @param \stdClass $au The AU record from cmi5_aus.
     * @param \stdClass $registration The registration record from cmi5_registrations.
     * @param \stdClass $session The session record from cmi5_sessions.
     * @return string JSON-encoded LMS.LaunchData document.
     */
    public static function build_launch_data(\stdClass $cmi5, \stdClass $au,
            \stdClass $registration, \stdClass $session): string {
        global $CFG, $DB;

        // Build the context template with registration, grouping activity,
        // and the required cmi5 session extension.
        $contexttemplate = [
            'registration' => $registration->registrationid,
            'contextActivities' => [
                'grouping' => [
                    [
                        'id' => $au->auid ?? '',
                    ],
                ],
            ],
            'extensions' => [
                'https://w3id.org/xapi/cmi5/context/extensions/sessionid' => $session->sessionid,
            ],
        ];

        // Build the launch data document.
        $launchdata = [
            'contextTemplate' => $contexttemplate,
            'launchMode' => $session->launchmode,
            'launchMethod' => $au->launchmethod ?? 'AnyWindow',
            'moveOn' => $au->moveoncriteria ?? 'NotApplicable',
        ];

        // Add optional mastery score if defined on the AU.
        if (isset($au->masteryscore) && $au->masteryscore !== null) {
            $launchdata['masteryScore'] = (float) $au->masteryscore;
        }

        // Build launch parameters using 3-layer merge: AU-level → Profile → Activity override.
        $params = $au->launchparameters ?? '';

        // Merge profile params (if activity has a profile).
        if (!empty($cmi5->profileid)) {
            $profile = $DB->get_record('cmi5_launch_profiles', ['id' => $cmi5->profileid]);
            if ($profile && !empty($profile->parameters)) {
                $params = self::merge_launch_params($params, $profile->parameters);
            }
        }

        // Merge activity-level override (highest priority).
        if (!empty($cmi5->launchparameters)) {
            $params = self::merge_launch_params($params, $cmi5->launchparameters);
        }

        if ($params !== '') {
            // Normalize: if the value is valid JSON, re-encode to strip stray
            // whitespace/\r\n that may have come from textarea input.
            $decoded = json_decode($params);
            if (json_last_error() === JSON_ERROR_NONE) {
                $params = json_encode($decoded, JSON_UNESCAPED_SLASHES);
            }
            $launchdata['launchParameters'] = $params;
        }

        // Add optional entitlement key if defined on the AU.
        if (!empty($au->entitlementkey)) {
            $launchdata['entitlementKey'] = [
                'courseStructure' => $au->entitlementkey,
            ];
        }

        // Previously this always set returnUrl regardless of what we tried to pass back as flag,
        // Now it checks launch method.
        // Iframe: AU navigates to return.php on exit so the session can be resolved.
        // New window: omit returnURL so the AU doesn't navigate away from the popup.
        $launchdata['returnURL'] = $cmi5->launchmethod == 1
            ? $CFG->wwwroot . '/mod/cmi5/return.php?sessionid=' . $session->sessionid
            : '';

        return json_encode($launchdata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Merge two launch parameter strings with smart JSON merge.
     *
     * If both values are valid JSON objects, performs a deep merge (right wins
     * on key conflicts). If either is not a valid JSON object, the override
     * replaces the base entirely.
     *
     * @param string $base Base launch parameters (lower priority).
     * @param string $override Override launch parameters (higher priority).
     * @return string Merged launch parameters.
     */
    public static function merge_launch_params(string $base, string $override): string {
        if ($base === '') {
            return $override;
        }
        if ($override === '') {
            return $base;
        }

        $basedecoded = json_decode($base, true);
        $baseok = (json_last_error() === JSON_ERROR_NONE) && is_array($basedecoded);

        $overridedecoded = json_decode($override, true);
        $overrideok = (json_last_error() === JSON_ERROR_NONE) && is_array($overridedecoded);

        // Both must decode as JSON objects (associative arrays) for deep merge.
        if ($baseok && $overrideok) {
            $merged = array_replace_recursive($basedecoded, $overridedecoded);
            return json_encode($merged, JSON_UNESCAPED_SLASHES);
        }

        // Fallback: complete replacement.
        return $override;
    }
}
