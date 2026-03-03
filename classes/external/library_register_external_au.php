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

class library_register_external_au extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'title' => new external_value(PARAM_TEXT, 'AU title'),
            'auid' => new external_value(PARAM_RAW, 'AU IRI identifier'),
            'url' => new external_value(PARAM_URL, 'Absolute URL to the AU content'),
            'description' => new external_value(PARAM_TEXT, 'Description', VALUE_DEFAULT, ''),
            'launchmethod' => new external_value(PARAM_ALPHA, 'Launch method: AnyWindow or OwnWindow', VALUE_DEFAULT, 'AnyWindow'),
            'moveoncriteria' => new external_value(PARAM_TEXT, 'moveOn criteria', VALUE_DEFAULT, 'NotApplicable'),
            'masteryscore' => new external_value(PARAM_FLOAT, 'Mastery score (0.0-1.0)', VALUE_DEFAULT, null),
            'launchparameters' => new external_value(PARAM_RAW, 'Launch parameters string', VALUE_DEFAULT, null),
            'profileid' => new external_value(PARAM_INT, 'Launch profile ID (optional)', VALUE_DEFAULT, 0),
            'packageid' => new external_value(PARAM_INT, 'Existing package ID to create new version (0 = new package)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $title, string $auid, string $url,
            string $description = '', string $launchmethod = 'AnyWindow',
            string $moveoncriteria = 'NotApplicable', ?float $masteryscore = null,
            ?string $launchparameters = null, int $profileid = 0, int $packageid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'title' => $title,
            'auid' => $auid,
            'url' => $url,
            'description' => $description,
            'launchmethod' => $launchmethod,
            'moveoncriteria' => $moveoncriteria,
            'masteryscore' => $masteryscore,
            'launchparameters' => $launchparameters,
            'profileid' => $profileid,
            'packageid' => $packageid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('mod/cmi5:managelibrary', $context);

        $version = content_library::register_external_au(
            $params['title'],
            $params['auid'],
            $params['url'],
            $params['description'],
            $params['launchmethod'],
            $params['moveoncriteria'],
            $params['masteryscore'],
            $params['launchparameters'],
            $params['profileid'],
            $params['packageid']
        );

        return [
            'packageid' => (int) $version->packageid,
            'auid' => (int) $version->auid,
            'title' => $params['title'],
            'versionid' => (int) $version->id,
            'versionnumber' => (int) $version->versionnumber,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'packageid' => new external_value(PARAM_INT, 'The package ID'),
            'auid' => new external_value(PARAM_INT, 'The new AU database ID'),
            'title' => new external_value(PARAM_TEXT, 'The package title'),
            'versionid' => new external_value(PARAM_INT, 'The new version ID'),
            'versionnumber' => new external_value(PARAM_INT, 'The version number'),
        ]);
    }
}
