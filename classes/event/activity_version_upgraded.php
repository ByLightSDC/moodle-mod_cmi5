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
 * Activity moved to a newer library package version.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Records one activity moving from one content library version to a later one.
 *
 * Raised per activity rather than per batch: an upgrade is only auditable if the record
 * says which activity moved, and from and to which version.
 */
class activity_version_upgraded extends \core\event\base {

    /**
     * Initialise event data.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'cmi5';
    }

    /**
     * Return the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventactivityversionupgraded', 'mod_cmi5');
    }

    /**
     * Describe the upgrade, including the batch it belonged to.
     *
     * @return string
     */
    public function get_description() {
        $description = "The user with id '{$this->userid}' upgraded the cmi5 activity with id " .
            "'{$this->objectid}' from version '{$this->other['fromversionnumber']}' " .
            "(id '{$this->other['fromversionid']}') to version '{$this->other['toversionnumber']}' " .
            "(id '{$this->other['toversionid']}') of library package " .
            "'{$this->other['packageid']}'.";

        if (!empty($this->other['batchid'])) {
            $description .= " This was part of bulk upgrade batch '{$this->other['batchid']}'.";
        }

        return $description;
    }

    /**
     * Return the activity that was upgraded.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/cmi5/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Describe the required fields, so a malformed event fails here rather than in the log.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set.');
        }
        foreach (['packageid', 'fromversionid', 'fromversionnumber', 'toversionid', 'toversionnumber'] as $field) {
            if (!isset($this->other[$field])) {
                throw new \coding_exception("The '{$field}' value must be set in other.");
            }
        }
    }

    /**
     * Map the activity for backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'cmi5', 'restore' => 'cmi5'];
    }

    /**
     * The package and version IDs are site-level library records, not course content.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'packageid' => \core\event\base::NOT_MAPPED,
            'fromversionid' => \core\event\base::NOT_MAPPED,
            'toversionid' => \core\event\base::NOT_MAPPED,
        ];
    }
}
