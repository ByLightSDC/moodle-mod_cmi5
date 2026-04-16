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
 * Agent profile builder for cmi5 activity module.
 *
 * Constructs the cmi5LearnerPreferences agent profile document as
 * defined by the cmi5 specification.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the cmi5LearnerPreferences agent profile document.
 *
 * The cmi5LearnerPreferences document is stored in the xAPI Agent Profile
 * API and provides the AU with the learner's language and audio preferences.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_profile {

    /**
     * Build the cmi5LearnerPreferences agent profile document.
     *
     * Retrieves the user's language preference from their Moodle profile
     * and constructs the JSON document.
     *
     * @param int $userid The Moodle user ID.
     * @return string JSON-encoded cmi5LearnerPreferences document.
     */
    public static function build_learner_preferences(int $userid): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, lang', MUST_EXIST);

        $preferences = [
            'languagePreference' => $user->lang ?: 'en',
            'audioPreference' => 'on',
        ];

        return json_encode($preferences, JSON_UNESCAPED_SLASHES);
    }
}
