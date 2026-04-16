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
 * Restore task for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cmi5/backup/moodle2/restore_cmi5_stepslib.php');

/**
 * cmi5 restore task.
 */
class restore_cmi5_activity_task extends restore_activity_task {

    /**
     * Define particular settings for this activity.
     */
    protected function define_my_settings() {
        // No particular settings.
    }

    /**
     * Define restore steps.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_cmi5_activity_structure_step('cmi5_structure', 'cmi5.xml'));
    }

    /**
     * Define decode contents.
     *
     * @return array
     */
    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('cmi5', ['intro'], 'cmi5');
        return $contents;
    }

    /**
     * Define decode rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        $rules = [];
        $rules[] = new restore_decode_rule('CMI5VIEWBYID', '/mod/cmi5/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('CMI5INDEX', '/mod/cmi5/index.php?id=$1', 'course');
        return $rules;
    }
}
