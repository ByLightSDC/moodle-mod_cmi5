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

namespace mod_cmi5\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use mod_cmi5\content_library;

class library_sync_activity extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'versionid' => new external_value(PARAM_INT, 'Target version ID (0 = latest)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $cmid, int $versionid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'versionid' => $versionid,
        ]);

        $cm = get_coursemodule_from_id('cmi5', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/cmi5:managecontent', $context);

        $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);

        $result = content_library::sync_activity_to_version((int) $cmi5->id, $params['versionid']);

        $changelog = [];
        foreach ($result->changelog as $entry) {
            $changelog[] = [
                'type' => $entry['type'] ?? '',
                'description' => self::format_changelog_entry($entry),
            ];
        }

        return [
            'success' => $result->success,
            'newversionid' => (int) $result->newversionid,
            'changelog' => $changelog,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether sync succeeded'),
            'newversionid' => new external_value(PARAM_INT, 'The new version ID'),
            'changelog' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_TEXT, 'Change type'),
                    'description' => new external_value(PARAM_TEXT, 'Human-readable description'),
                ])
            ),
        ]);
    }

    /**
     * Format a changelog entry into a human-readable string.
     *
     * @param array $entry Changelog entry.
     * @return string Formatted description.
     */
    private static function format_changelog_entry(array $entry): string {
        $type = $entry['type'] ?? '';
        $title = $entry['title'] ?? '';

        switch ($type) {
            case 'au_added':
                return get_string('library:auadded', 'cmi5', $title);
            case 'au_removed':
                return get_string('library:auremoved', 'cmi5', $title);
            case 'au_changed':
                $field = $entry['field'] ?? '';
                return get_string('library:auchanged', 'cmi5', (object) [
                    'title' => $title,
                    'field' => $field,
                ]);
            case 'block_added':
                return get_string('library:blockadded', 'cmi5', $title);
            case 'block_removed':
                return get_string('library:blockremoved', 'cmi5', $title);
            default:
                return $title;
        }
    }
}
