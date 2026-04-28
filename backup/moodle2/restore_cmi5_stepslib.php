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
class restore_cmi5_activity_structure_step extends restore_activity_structure_step
{
    protected function define_structure()
    {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('cmi5', '/activity/cmi5');
        $paths[] = new restore_path_element('cmi5_au', '/activity/cmi5/aus/au');
        $paths[] = new restore_path_element('cmi5_block', '/activity/cmi5/blocks/block');

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'cmi5_registration',
                '/activity/cmi5/registrations/registration'
            );
            $paths[] = new restore_path_element(
                'cmi5_au_status',
                '/activity/cmi5/registrations/registration/au_statuses/au_status'
            );
            $paths[] = new restore_path_element(
                'cmi5_session',
                '/activity/cmi5/registrations/registration/sessions/session'
            );
            $paths[] = new restore_path_element(
                'cmi5_statement',
                '/activity/cmi5/registrations/registration/sessions/session/statements/statement'
            );
            $paths[] = new restore_path_element(
                'cmi5_block_status',
                '/activity/cmi5/registrations/registration/block_statuses/block_status'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    protected function process_cmi5($data)
    {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->course = $this->get_courseid();
        $data->timecreated = time();
        $data->timemodified = time();

        // Save before any nulling so we can set up a file-mapping for library content.
        $oldpackageversionid = !empty($data->packageversionid) ? (int) $data->packageversionid : null;

        debugging(
            'cmi5 restore: processing cmi5 id=' . $oldid .
            ', packageid=' . ($data->packageid ?? 'null') .
            ', packageversionid=' . ($oldpackageversionid ?? 'null'),
            DEBUG_DEVELOPER
        );

        if (!empty($data->packageversionid)) {
            $exists = $DB->record_exists('cmi5_package_versions', ['id' => $data->packageversionid]);
            if (!$exists) {
                debugging(
                    'cmi5 restore: packageversionid=' . $data->packageversionid .
                    ' not found on target - will restore content files from backup',
                    DEBUG_DEVELOPER
                );
                $data->packageversionid = null;
            }
        }

        if (!empty($data->packageid)) {
            $exists = $DB->record_exists('cmi5_packages', ['id' => $data->packageid]);
            if (!$exists) {
                debugging(
                    'cmi5 restore: packageid=' . $data->packageid .
                    ' not found on target - nulling packageid and packageversionid',
                    DEBUG_DEVELOPER
                );
                $data->packageid = null;
                $data->packageversionid = null;
            }
        }

        $newitemid = $DB->insert_record('cmi5', $data);
        debugging('cmi5 restore: inserted cmi5 record, newitemid=' . $newitemid, DEBUG_DEVELOPER);

        $this->apply_activity_instance($newitemid);

        // Mapping name 'cmi5' is used in add_related_files for package/content files.
        $this->set_mapping('cmi5', $oldid, $newitemid, true);

        // If this activity used a library package that doesn't exist on the target,
        // register a mapping from the old versionid to the new cmi5id. after_execute()
        // uses this to find and convert backed-up library_content files into standalone
        // 'content' files (which the content_server fallback path will then serve).
        if ($oldpackageversionid && empty($data->packageversionid)) {
            debugging(
                'cmi5 restore: registering cmi5_package_version mapping ' .
                $oldpackageversionid . ' -> ' . $newitemid . ' for library content restore',
                DEBUG_DEVELOPER
            );
            $this->set_mapping('cmi5_package_version', $oldpackageversionid, $newitemid);
        }
    }

    protected function process_cmi5_au($data)
    {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->cmi5id = $this->get_new_parentid('cmi5');

        if (!empty($data->parentblockid)) {
            $data->parentblockid = $this->get_mappingid('cmi5_block', $data->parentblockid);
        }

        $newitemid = $DB->insert_record('cmi5_aus', $data);
        $this->set_mapping('cmi5_au', $oldid, $newitemid);
    }

    protected function process_cmi5_block($data)
    {
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

    protected function process_cmi5_registration($data)
    {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->cmi5id = $this->get_new_parentid('cmi5');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('cmi5_registrations', $data);
        $this->set_mapping('cmi5_registration', $oldid, $newitemid);
    }

    protected function process_cmi5_au_status($data)
    {
        global $DB;

        $data = (object) $data;

        $data->registrationid = $this->get_new_parentid('cmi5_registration');
        $data->auid = $this->get_mappingid('cmi5_au', $data->auid);

        $DB->insert_record('cmi5_au_status', $data);
    }

    protected function process_cmi5_session($data)
    {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->registrationid = $this->get_new_parentid('cmi5_registration');
        $data->auid = $this->get_mappingid('cmi5_au', $data->auid);

        $newitemid = $DB->insert_record('cmi5_sessions', $data);
        $this->set_mapping('cmi5_session', $oldid, $newitemid);
    }

    protected function process_cmi5_statement($data)
    {
        global $DB;

        $data = (object) $data;

        $data->sessionid = $this->get_new_parentid('cmi5_session');

        $DB->insert_record('cmi5_statements', $data);
    }

    protected function process_cmi5_block_status($data)
    {
        global $DB;

        $data = (object) $data;

        $data->registrationid = $this->get_new_parentid('cmi5_registration');
        $data->blockid = $this->get_mappingid('cmi5_block', $data->blockid);

        $DB->insert_record('cmi5_block_status', $data);
    }

    protected function after_execute()
    {
        debugging('cmi5 restore: after_execute - starting file restoration', DEBUG_DEVELOPER);

        $this->add_related_files('mod_cmi5', 'intro', null);

        // 'package' ZIP is stored with itemid=0, so no remapping needed.
        $this->add_related_files('mod_cmi5', 'package', null);

        // 'content' uses cmi5.id as itemid; 'cmi5' is the mapping set in process_cmi5.
        $this->add_related_files('mod_cmi5', 'content', 'cmi5');

        debugging('cmi5 restore: standard files restored (intro, package, content)', DEBUG_DEVELOPER);

        // Library content was backed up from SYSTEM context keyed by packageversionid.
        // Restore it temporarily into the module context as 'library_content', then
        // convert to standalone 'content' files — which is where content_server looks
        // when packageversionid is null (always the case after restore to a new site).
        $syscontextid = \context_system::instance()->id;
        $this->add_related_files('mod_cmi5', 'library_content', 'cmi5_package_version', $syscontextid);

        debugging('cmi5 restore: library_content files staged in module context', DEBUG_DEVELOPER);

        $this->convert_library_content_to_standalone();
    }

    /**
     * Copy library_content files (temporarily landed in module context) into the 'content'
     * filearea with cmi5.id as itemid, then delete the temporary library_content entries.
     *
     * When packageversionid is null after restore, content_server::get_launch_content_url()
     * falls back to serving from 'content' filearea. This ensures those files are present.
     */
    protected function convert_library_content_to_standalone()
    {
        global $DB;

        $fs        = get_file_storage();
        $contextid = $this->task->get_contextid();
        $restoreid = $this->get_restoreid();

        debugging(
            'cmi5 restore: convert_library_content_to_standalone - contextid=' . $contextid,
            DEBUG_DEVELOPER
        );

        // Each row maps: old packageversionid (itemid) -> new cmi5.id (newitemid).
        $mappings = $DB->get_records('backup_ids_temp', [
            'backupid' => $restoreid,
            'itemname' => 'cmi5_package_version',
        ]);

        if (empty($mappings)) {
            debugging('cmi5 restore: no library content mappings found - nothing to convert', DEBUG_DEVELOPER);
            return;
        }

        foreach ($mappings as $mapping) {
            $newcmi5id = (int) $mapping->newitemid;

            debugging(
                'cmi5 restore: converting library_content for cmi5id=' . $newcmi5id .
                ' (old versionid=' . $mapping->itemid . ')',
                DEBUG_DEVELOPER
            );

            // add_related_files placed these at: contextid / mod_cmi5 / library_content / newcmi5id.
            $libfiles = $fs->get_area_files(
                $contextid, 'mod_cmi5', 'library_content', $newcmi5id, 'filepath, filename', false
            );

            $copied  = 0;
            $skipped = 0;

            foreach ($libfiles as $file) {
                $filepath = $file->get_filepath();
                $filename = $file->get_filename();

                if ($fs->file_exists($contextid, 'mod_cmi5', 'content', $newcmi5id, $filepath, $filename)) {
                    $skipped++;
                    continue;
                }

                $filerecord = [
                    'contextid' => $contextid,
                    'component' => 'mod_cmi5',
                    'filearea'  => 'content',
                    'itemid'    => $newcmi5id,
                    'filepath'  => $filepath,
                    'filename'  => $filename,
                ];

                try {
                    $fs->create_file_from_storedfile($filerecord, $file);
                    $copied++;
                    debugging('cmi5 restore: copied ' . $filepath . $filename, DEBUG_DEVELOPER);
                } catch (\Exception $e) {
                    debugging(
                        'cmi5 restore: failed to copy ' . $filepath . $filename .
                        ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }

            debugging(
                'cmi5 restore: cmi5id=' . $newcmi5id .
                ' - copied=' . $copied . ', skipped=' . $skipped,
                DEBUG_DEVELOPER
            );

            // Remove the temporary library_content entries from the module context.
            $fs->delete_area_files($contextid, 'mod_cmi5', 'library_content', $newcmi5id);
            debugging(
                'cmi5 restore: cleaned up temporary library_content files for cmi5id=' . $newcmi5id,
                DEBUG_DEVELOPER
            );
        }
    }
}
