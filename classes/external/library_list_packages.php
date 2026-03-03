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

class library_list_packages extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'search' => new external_value(PARAM_TEXT, 'Search string', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_INT, 'Status filter (-1=all, 0=disabled, 1=active)', VALUE_DEFAULT, 1),
            'offset' => new external_value(PARAM_INT, 'Pagination offset', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Max results', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute(string $search = '', int $status = 1,
            int $offset = 0, int $limit = 50): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'search' => $search,
            'status' => $status,
            'offset' => $offset,
            'limit' => $limit,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        // Allow teachers (addinstance) or library managers to list packages.
        if (!has_capability('mod/cmi5:managelibrary', $context) &&
                !has_capability('mod/cmi5:addinstance', $context, null, false)) {
            require_capability('mod/cmi5:managelibrary', $context);
        }

        $packages = content_library::list_packages(
            $params['search'],
            $params['status'],
            $params['offset'],
            $params['limit']
        );

        $total = content_library::count_packages($params['search'], $params['status']);

        $result = [];
        foreach ($packages as $pkg) {
            // Get latest version info for source/status/usagecount.
            $source = 0;
            $pkgstatus = 1;
            $usagecount = 0;
            if (!empty($pkg->latestversion)) {
                $version = $DB->get_record('cmi5_package_versions', ['id' => $pkg->latestversion]);
                if ($version) {
                    $source = (int) $version->source;
                    $pkgstatus = (int) $version->status;
                }
                // Sum usage across all versions.
                $usagecount = (int) $DB->get_field_sql(
                    "SELECT COALESCE(SUM(usagecount), 0) FROM {cmi5_package_versions} WHERE packageid = :pkgid",
                    ['pkgid' => $pkg->id]
                );
            }

            $result[] = [
                'id' => (int) $pkg->id,
                'title' => $pkg->title,
                'description' => $pkg->description ?? '',
                'source' => $source,
                'status' => $pkgstatus,
                'usagecount' => $usagecount,
                'timecreated' => (int) $pkg->timecreated,
            ];
        }

        return [
            'packages' => $result,
            'total' => $total,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'packages' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Package ID'),
                    'title' => new external_value(PARAM_TEXT, 'Title'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'source' => new external_value(PARAM_INT, 'Source: 0=zip, 1=external, 2=api'),
                    'status' => new external_value(PARAM_INT, 'Status: 0=disabled, 1=active'),
                    'usagecount' => new external_value(PARAM_INT, 'Number of activities using this package'),
                    'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
                ])
            ),
            'total' => new external_value(PARAM_INT, 'Total matching packages'),
        ]);
    }
}
