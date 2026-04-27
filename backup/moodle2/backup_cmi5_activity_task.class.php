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
 * Backup task for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cmi5/backup/moodle2/backup_cmi5_stepslib.php');

/**
 * Provides the steps to perform one complete backup of the cmi5 instance.
 */
class backup_cmi5_activity_task extends backup_activity_task
{

    /**
     * No particular settings for this activity.
     */
    protected function define_my_settings()
    {
        // No particular settings.
    }

    /**
     * Define backup steps.
     */
    protected function define_my_steps()
    {
        $this->add_step(new backup_cmi5_activity_structure_step('cmi5_structure', 'cmi5.xml'));
    }

    /**
     * Encode content links.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content)
    {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of cmi5 activities.
        $search = "/(" . $base . "\/mod\/cmi5\/index\.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@CMI5INDEX*$2@$', $content);

        // Link to cmi5 view by moduleid.
        $search = "/(" . $base . "\/mod\/cmi5\/view\.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@CMI5VIEWBYID*$2@$', $content);

        return $content;
    }
}
