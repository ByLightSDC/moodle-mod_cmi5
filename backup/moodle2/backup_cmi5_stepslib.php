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
 * Backup steps for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete cmi5 structure for backup.
 */
class backup_cmi5_activity_structure_step extends backup_activity_structure_step {

    /**
     * Define the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        // Define elements.
        $cmi5 = new backup_nested_element('cmi5', ['id'], [
            'course', 'name', 'intro', 'introformat', 'packagefilename',
            'packageid', 'packageversionid',
            'courseid_iri', 'grademethod', 'maxgrade', 'launchmethod',
            'sessiontimeout', 'lrsendpoint', 'lrskey', 'lrssecret', 'lrsmode',
            'launchparameters', 'profileid',
            'timecreated', 'timemodified',
        ]);

        $aus = new backup_nested_element('aus');
        $au = new backup_nested_element('au', ['id'], [
            'auid', 'title', 'description', 'url', 'launchmethod',
            'moveoncriteria', 'masteryscore', 'launchparameters',
            'entitlementkey', 'parentblockid', 'sortorder',
        ]);

        $blocks = new backup_nested_element('blocks');
        $block = new backup_nested_element('block', ['id'], [
            'blockid', 'title', 'description', 'parentblockid', 'sortorder',
        ]);

        $registrations = new backup_nested_element('registrations');
        $registration = new backup_nested_element('registration', ['id'], [
            'userid', 'registrationid', 'coursesatisfied', 'timecreated', 'timemodified',
        ]);

        $austatuses = new backup_nested_element('au_statuses');
        $austatus = new backup_nested_element('au_status', ['id'], [
            'auid', 'completed', 'passed', 'failed', 'satisfied', 'waived',
            'score_scaled', 'timecreated', 'timemodified',
        ]);

        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], [
            'auid', 'sessionid', 'launchmode', 'initialized', 'terminated',
            'abandoned', 'timecreated', 'timemodified',
        ]);

        $statements = new backup_nested_element('statements');
        $statement = new backup_nested_element('statement', ['id'], [
            'statementid', 'verb', 'statement_json', 'is_cmi5_defined',
            'forwarded', 'timecreated',
        ]);

        $blockstatuses = new backup_nested_element('block_statuses');
        $blockstatus = new backup_nested_element('block_status', ['id'], [
            'blockid', 'satisfied', 'timecreated', 'timemodified',
        ]);

        // Build the tree.
        $cmi5->add_child($aus);
        $aus->add_child($au);

        $cmi5->add_child($blocks);
        $blocks->add_child($block);

        if ($userinfo) {
            $cmi5->add_child($registrations);
            $registrations->add_child($registration);

            $registration->add_child($austatuses);
            $austatuses->add_child($austatus);

            $registration->add_child($sessions);
            $sessions->add_child($session);

            $session->add_child($statements);
            $statements->add_child($statement);

            $registration->add_child($blockstatuses);
            $blockstatuses->add_child($blockstatus);
        }

        // Define sources.
        $cmi5->set_source_table('cmi5', ['id' => backup::VAR_ACTIVITYID]);
        $au->set_source_table('cmi5_aus', ['cmi5id' => backup::VAR_PARENTID]);
        $block->set_source_table('cmi5_blocks', ['cmi5id' => backup::VAR_PARENTID]);

        if ($userinfo) {
            $registration->set_source_table('cmi5_registrations', ['cmi5id' => backup::VAR_PARENTID]);
            $austatus->set_source_table('cmi5_au_status', ['registrationid' => backup::VAR_PARENTID]);
            $session->set_source_table('cmi5_sessions', ['registrationid' => backup::VAR_PARENTID]);
            $statement->set_source_table('cmi5_statements', ['sessionid' => backup::VAR_PARENTID]);
            $blockstatus->set_source_table('cmi5_block_status', ['registrationid' => backup::VAR_PARENTID]);
        }

        // Define annotations.
        if ($userinfo) {
            $registration->annotate_ids('user', 'userid');
        }

        // Define file annotations.
        $cmi5->annotate_files('mod_cmi5', 'package', null);
        $cmi5->annotate_files('mod_cmi5', 'content', null);
        $cmi5->annotate_files('mod_cmi5', 'intro', null);

        return $this->prepare_activity_structure($cmi5);
    }
}
