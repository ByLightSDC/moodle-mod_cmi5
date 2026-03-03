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

class library_delete_package extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'packageid' => new external_value(PARAM_INT, 'The package ID to delete'),
            'force' => new external_value(PARAM_BOOL, 'Force delete even if in use', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $packageid, bool $force = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'packageid' => $packageid,
            'force' => $force,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('mod/cmi5:managelibrary', $context);

        content_library::delete_package($params['packageid'], $params['force']);

        return ['success' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the deletion succeeded'),
        ]);
    }
}
