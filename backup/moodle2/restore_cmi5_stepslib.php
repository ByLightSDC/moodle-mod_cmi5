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
 * Restore steps for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one cmi5 activity.
 */
class restore_cmi5_activity_structure_step extends restore_activity_structure_step {

    /**
     * Define restore structure.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('cmi5', '/activity/cmi5');
        $paths[] = new restore_path_element('cmi5_au', '/activity/cmi5/aus/au');
        $paths[] = new restore_path_element('cmi5_block', '/activity/cmi5/blocks/block');

        if ($userinfo) {
            $paths[] = new restore_path_element('cmi5_registration',
                '/activity/cmi5/registrations/registration');
            $paths[] = new restore_path_element('cmi5_au_status',
                '/activity/cmi5/registrations/registration/au_statuses/au_status');
            $paths[] = new restore_path_element('cmi5_session',
                '/activity/cmi5/registrations/registration/sessions/session');
            $paths[] = new restore_path_element('cmi5_statement',
                '/activity/cmi5/registrations/registration/sessions/session/statements/statement');
            $paths[] = new restore_path_element('cmi5_block_status',
                '/activity/cmi5/registrations/registration/block_statuses/block_status');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process the cmi5 element.
     *
     * @param array $data
     */
    protected function process_cmi5($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->timecreated = time();
        $data->timemodified = time();

        // Preserve packageversionid if the referenced version exists on this site.
        if (!empty($data->packageversionid)) {
            $exists = $DB->record_exists('cmi5_package_versions', ['id' => $data->packageversionid]);
            if (!$exists) {
                $data->packageversionid = null;
            }
        }
        // Same for packageid.
        if (!empty($data->packageid)) {
            $exists = $DB->record_exists('cmi5_packages', ['id' => $data->packageid]);
            if (!$exists) {
                $data->packageid = null;
                $data->packageversionid = null;
            }
        }

        $newitemid = $DB->insert_record('cmi5', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Process an AU element.
     *
     * @param array $data
     */
    protected function process_cmi5_au($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->cmi5id = $this->get_new_parentid('cmi5');

        // Map parent block ID if set.
        if (!empty($data->parentblockid)) {
            $data->parentblockid = $this->get_mappingid('cmi5_block', $data->parentblockid);
        }

        $newitemid = $DB->insert_record('cmi5_aus', $data);
        $this->set_mapping('cmi5_au', $oldid, $newitemid);
    }

    /**
     * Process a block element.
     *
     * @param array $data
     */
    protected function process_cmi5_block($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->cmi5id = $this->get_new_parentid('cmi5');

        if (!empty($data->parentblockid)) {
            $data->parentblockid = $this->get_mappingid('cmi5_block', $data->parentblockid);
        }

        $newitemid = $DB->insert_record('cmi5_blocks', $data);
        $this->set_mapping('cmi5_block', $oldid, $newitemid);
    }

    /**
     * Process a registration element.
     *
     * @param array $data
     */
    protected function process_cmi5_registration($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->cmi5id = $this->get_new_parentid('cmi5');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('cmi5_registrations', $data);
        $this->set_mapping('cmi5_registration', $oldid, $newitemid);
    }

    /**
     * Process an AU status element.
     *
     * @param array $data
     */
    protected function process_cmi5_au_status($data) {
        global $DB;

        $data = (object) $data;
        $data->registrationid = $this->get_new_parentid('cmi5_registration');
        $data->auid = $this->get_mappingid('cmi5_au', $data->auid);

        $DB->insert_record('cmi5_au_status', $data);
    }

    /**
     * Process a session element.
     *
     * @param array $data
     */
    protected function process_cmi5_session($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->registrationid = $this->get_new_parentid('cmi5_registration');
        $data->auid = $this->get_mappingid('cmi5_au', $data->auid);

        $newitemid = $DB->insert_record('cmi5_sessions', $data);
        $this->set_mapping('cmi5_session', $oldid, $newitemid);
    }

    /**
     * Process a statement element.
     *
     * @param array $data
     */
    protected function process_cmi5_statement($data) {
        global $DB;

        $data = (object) $data;
        $data->sessionid = $this->get_new_parentid('cmi5_session');

        $DB->insert_record('cmi5_statements', $data);
    }

    /**
     * Process a block status element.
     *
     * @param array $data
     */
    protected function process_cmi5_block_status($data) {
        global $DB;

        $data = (object) $data;
        $data->registrationid = $this->get_new_parentid('cmi5_registration');
        $data->blockid = $this->get_mappingid('cmi5_block', $data->blockid);

        $DB->insert_record('cmi5_block_status', $data);
    }

    /**
     * Post-execution actions.
     */
    protected function after_execute() {
        $this->add_related_files('mod_cmi5', 'intro', null);
        $this->add_related_files('mod_cmi5', 'package', null);
        $this->add_related_files('mod_cmi5', 'content', null);
    }
}
