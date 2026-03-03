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

class library_get_package extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'packageid' => new external_value(PARAM_INT, 'The package ID'),
            'versionid' => new external_value(PARAM_INT, 'Specific version ID (0 = latest)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $packageid, int $versionid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'packageid' => $packageid,
            'versionid' => $versionid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        if (!has_capability('mod/cmi5:managelibrary', $context) &&
                !has_capability('mod/cmi5:addinstance', $context, null, false)) {
            require_capability('mod/cmi5:managelibrary', $context);
        }

        $package = content_library::get_package_details($params['packageid'], $params['versionid']);

        $aus = [];
        foreach ($package->aus as $au) {
            $aus[] = [
                'id' => (int) $au->id,
                'auid' => $au->auid,
                'title' => $au->title,
                'description' => $au->description ?? '',
                'url' => $au->url,
                'launchmethod' => $au->launchmethod,
                'moveoncriteria' => $au->moveoncriteria,
                'masteryscore' => $au->masteryscore !== null ? (float) $au->masteryscore : null,
                'isexternal' => (int) $au->isexternal,
                'sortorder' => (int) $au->sortorder,
            ];
        }

        $blocks = [];
        foreach ($package->blocks as $block) {
            $blocks[] = [
                'id' => (int) $block->id,
                'blockid' => $block->blockid,
                'title' => $block->title,
                'description' => $block->description ?? '',
                'sortorder' => (int) $block->sortorder,
            ];
        }

        // Build versions array.
        $versions = [];
        $allversions = content_library::get_package_versions($params['packageid']);
        foreach ($allversions as $ver) {
            $versions[] = [
                'id' => (int) $ver->id,
                'versionnumber' => (int) $ver->versionnumber,
                'source' => (int) $ver->source,
                'usagecount' => (int) $ver->usagecount,
                'timecreated' => (int) $ver->timecreated,
            ];
        }

        return [
            'id' => (int) $package->id,
            'title' => $package->title,
            'description' => $package->description ?? '',
            'courseid_iri' => $package->courseid_iri ?? '',
            'source' => (int) ($package->source ?? 0),
            'status' => (int) ($package->status ?? 1),
            'usagecount' => (int) ($package->usagecount ?? 0),
            'profileid' => (int) ($package->profileid ?? 0),
            'timecreated' => (int) $package->timecreated,
            'versionid' => (int) ($package->versionid ?? 0),
            'versionnumber' => (int) ($package->versionnumber ?? 0),
            'aus' => $aus,
            'blocks' => $blocks,
            'versions' => $versions,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Package ID'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'description' => new external_value(PARAM_RAW, 'Description'),
            'courseid_iri' => new external_value(PARAM_RAW, 'Course ID IRI'),
            'source' => new external_value(PARAM_INT, 'Source type'),
            'status' => new external_value(PARAM_INT, 'Status'),
            'usagecount' => new external_value(PARAM_INT, 'Usage count'),
            'profileid' => new external_value(PARAM_INT, 'Launch profile ID (0 if none)'),
            'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
            'versionid' => new external_value(PARAM_INT, 'Current version ID'),
            'versionnumber' => new external_value(PARAM_INT, 'Current version number'),
            'aus' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'AU DB ID'),
                    'auid' => new external_value(PARAM_RAW, 'AU IRI'),
                    'title' => new external_value(PARAM_TEXT, 'Title'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'url' => new external_value(PARAM_RAW, 'AU URL'),
                    'launchmethod' => new external_value(PARAM_TEXT, 'Launch method'),
                    'moveoncriteria' => new external_value(PARAM_TEXT, 'moveOn criteria'),
                    'masteryscore' => new external_value(PARAM_FLOAT, 'Mastery score', VALUE_OPTIONAL),
                    'isexternal' => new external_value(PARAM_INT, '1 if external URL'),
                    'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                ])
            ),
            'blocks' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Block DB ID'),
                    'blockid' => new external_value(PARAM_RAW, 'Block IRI'),
                    'title' => new external_value(PARAM_TEXT, 'Title'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                ])
            ),
            'versions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Version ID'),
                    'versionnumber' => new external_value(PARAM_INT, 'Version number'),
                    'source' => new external_value(PARAM_INT, 'Source type'),
                    'usagecount' => new external_value(PARAM_INT, 'Usage count'),
                    'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
                ])
            ),
        ]);
    }
}
