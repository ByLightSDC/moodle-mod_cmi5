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
            'sort' => new external_value(PARAM_ALPHA, 'Sort order: recent, title or usage',
                VALUE_DEFAULT, content_library::SORT_RECENT),
            'source' => new external_value(PARAM_INT, 'Source filter (-1=all, 0=zip, 1=external, 2=api)',
                VALUE_DEFAULT, -1),
            'contextid' => new external_value(PARAM_INT, 'Course or module context for the picker (0 = system)',
                VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $search = '', int $status = 1,
            int $offset = 0, int $limit = 50, string $sort = content_library::SORT_RECENT,
            int $source = -1, int $contextid = 0): array {

        $params = self::validate_parameters(self::execute_parameters(), [
            'search' => $search,
            'status' => $status,
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'source' => $source,
            'contextid' => $contextid,
        ]);

        if (!in_array($params['sort'], content_library::VALID_SORTS, true)) {
            $params['sort'] = content_library::SORT_RECENT;
        }
        $params['offset'] = max(0, $params['offset']);
        $params['limit'] = max(1, min(100, $params['limit']));

        $systemcontext = \context_system::instance();
        $context = $params['contextid'] ? \context::instance_by_id($params['contextid'], MUST_EXIST) : $systemcontext;
        if (!in_array($context->contextlevel, [CONTEXT_SYSTEM, CONTEXT_COURSE, CONTEXT_MODULE], true)) {
            throw new \invalid_parameter_exception('Expected a system, course or module context');
        }
        self::validate_context($context);

        // Check teachers in the form context; library management remains a system permission.
        if (!has_capability('mod/cmi5:managelibrary', $systemcontext) &&
                !has_capability('mod/cmi5:addinstance', $context, null, false)) {
            require_capability('mod/cmi5:addinstance', $context);
        }

        $packages = content_library::list_packages_with_meta(
            $params['search'],
            $params['status'],
            $params['offset'],
            $params['limit'],
            $params['sort'],
            $params['source']
        );

        $total = content_library::count_packages(
            $params['search'],
            $params['status'],
            $params['source']
        );

        $result = [];
        foreach ($packages as $pkg) {
            $result[] = [
                'id' => (int) $pkg->id,
                'title' => $pkg->title,
                'description' => $pkg->description ?? '',
                'source' => (int) ($pkg->source ?? 0),
                'status' => (int) ($pkg->status ?? 1),
                'usagecount' => (int) $pkg->usagecount,
                'timecreated' => (int) $pkg->timecreated,
                'timemodified' => (int) $pkg->timemodified,
                'versionid' => (int) ($pkg->versionid ?? 0),
                'versionnumber' => (int) ($pkg->versionnumber ?? 0),
                'aucount' => (int) $pkg->aucount,
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
                    'timemodified' => new external_value(PARAM_INT, 'Last modified timestamp', VALUE_OPTIONAL),
                    'versionid' => new external_value(PARAM_INT, 'Latest version ID', VALUE_OPTIONAL),
                    'versionnumber' => new external_value(PARAM_INT, 'Latest version number', VALUE_OPTIONAL),
                    'aucount' => new external_value(PARAM_INT, 'Number of AUs in the latest version', VALUE_OPTIONAL),
                ])
            ),
            'total' => new external_value(PARAM_INT, 'Total matching packages'),
        ]);
    }
}
