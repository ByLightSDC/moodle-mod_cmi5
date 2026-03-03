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
 * Content serving helper for cmi5 activity module.
 *
 * Provides URL generation for serving cmi5 content files and
 * constructing AU launch URLs.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates URLs for cmi5 content files and AU launch entry points.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_server {

    /**
     * Get a Moodle URL to a content file served via pluginfile.php.
     *
     * The returned URL points to pluginfile.php which will serve the file
     * from the 'content' file area of the mod_cmi5 component.
     *
     * @param \context_module $context The module context.
     * @param int $cmi5id The cmi5 activity instance ID (used as itemid).
     * @param string $filepath The relative file path within the content area (e.g. '/index.html').
     * @return \moodle_url The URL to the content file.
     */
    public static function get_content_url(\context_module $context, int $cmi5id,
            string $filepath): \moodle_url {
        global $CFG;

        // Strip leading slash for the relative path portion.
        $filepath = ltrim($filepath, '/');

        // Build URL: pluginfile.php/contextid/mod_cmi5/content/itemid/path/to/file.html
        // This preserves the directory structure so relative URLs in HTML resolve correctly.
        $url = $CFG->wwwroot . '/pluginfile.php/' . $context->id . '/mod_cmi5/content/'
            . $cmi5id . '/' . $filepath;

        return new \moodle_url($url);
    }

    /**
     * Get the full launch URL for an AU's content entry point.
     *
     * Resolves the AU's URL (which may be relative to the package or an
     * absolute URL) into a full Moodle URL for launching the content.
     *
     * @param \context_module $context The module context.
     * @param \stdClass $cmi5 The cmi5 activity instance record.
     * @param \stdClass $au The AU record from cmi5_aus.
     * @return \moodle_url The full URL to the AU content entry point.
     */
    public static function get_launch_content_url(\context_module $context, \stdClass $cmi5,
            \stdClass $au): \moodle_url {
        $auurl = $au->url;

        // If the AU URL is absolute (external), return it directly.
        if (preg_match('#^https?://#i', $auurl)) {
            return new \moodle_url($auurl);
        }

        // If the activity is linked to a library package version, serve from SYSTEM context.
        if (!empty($cmi5->packageversionid)) {
            return self::get_library_content_url((int) $cmi5->packageversionid, $auurl);
        }

        // Fall back to resolving via packageid→latestversion if packageversionid is null.
        if (!empty($cmi5->packageid)) {
            global $DB;
            $latestversion = $DB->get_field('cmi5_packages', 'latestversion', ['id' => $cmi5->packageid]);
            if ($latestversion) {
                return self::get_library_content_url((int) $latestversion, $auurl);
            }
        }

        // Otherwise treat it as a relative path within the package content.
        return self::get_content_url($context, $cmi5->id, $auurl);
    }

    /**
     * Get a URL to a content file in the library (SYSTEM context).
     *
     * @param int $versionid The library package version ID (used as itemid).
     * @param string $filepath The relative file path within the content area.
     * @return \moodle_url The URL to the library content file.
     */
    public static function get_library_content_url(int $versionid, string $filepath): \moodle_url {
        global $CFG;

        $filepath = ltrim($filepath, '/');
        $syscontext = \context_system::instance();

        $url = $CFG->wwwroot . '/pluginfile.php/' . $syscontext->id . '/mod_cmi5/library_content/'
            . $versionid . '/' . $filepath;

        return new \moodle_url($url);
    }
}
