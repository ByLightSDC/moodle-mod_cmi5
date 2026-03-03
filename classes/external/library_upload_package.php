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
use core_external\external_value;
use mod_cmi5\content_library;

class library_upload_package extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item ID containing the ZIP'),
            'title' => new external_value(PARAM_TEXT, 'Package title (optional, auto-detected from cmi5.xml)', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_TEXT, 'Package description (optional)', VALUE_DEFAULT, ''),
            'profileid' => new external_value(PARAM_INT, 'Launch profile ID (optional)', VALUE_DEFAULT, 0),
            'packageid' => new external_value(PARAM_INT, 'Existing package ID to create new version (0 = new package)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $draftitemid, string $title = '', string $description = '',
            int $profileid = 0, int $packageid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'draftitemid' => $draftitemid,
            'title' => $title,
            'description' => $description,
            'profileid' => $profileid,
            'packageid' => $packageid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('mod/cmi5:managelibrary', $context);

        $version = content_library::upload_package_from_draft(
            $params['draftitemid'],
            $params['title'],
            $params['description'],
            $params['profileid'],
            $params['packageid']
        );

        return [
            'packageid' => (int) $version->packageid,
            'title' => $params['title'] ?: 'Untitled Package',
            'versionid' => (int) $version->id,
            'versionnumber' => (int) $version->versionnumber,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'packageid' => new external_value(PARAM_INT, 'The package ID'),
            'title' => new external_value(PARAM_TEXT, 'The package title'),
            'versionid' => new external_value(PARAM_INT, 'The new version ID'),
            'versionnumber' => new external_value(PARAM_INT, 'The version number'),
        ]);
    }
}
