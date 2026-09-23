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
 * Content library version deleted event.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Records permanent deletion of a content library package version.
 */
class library_version_deleted extends \core\event\base {

    /**
     * Initialise event data.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'cmi5_package_versions';
    }

    /**
     * Return the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventlibraryversiondeleted', 'mod_cmi5');
    }

    /**
     * Describe the deletion.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' deleted version " .
            "'{$this->other['versionnumber']}' (id '{$this->objectid}') of cmi5 library package " .
            "'{$this->other['packagetitle']}' (id '{$this->other['packageid']}').";
    }

    /**
     * Return the remaining package management page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/cmi5/library.php', [
            'action' => 'view',
            'packageid' => $this->other['packageid'],
            'tab' => 'versions',
        ]);
    }
}
