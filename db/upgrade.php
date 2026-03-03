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
 * Database upgrade steps for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute mod_cmi5 upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_cmi5_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026022700) {

        // 1. Create cmi5_packages table.
        $table = new xmldb_table('cmi5_packages');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('courseid_iri', XMLDB_TYPE_CHAR, '1333', null, null, null, null);
        $table->add_field('source', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('externalurl', XMLDB_TYPE_CHAR, '1333', null, null, null, null);
        $table->add_field('sha256hash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usagecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('sha256hash', XMLDB_INDEX_NOTUNIQUE, ['sha256hash']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2. Create cmi5_package_aus table.
        $table = new xmldb_table('cmi5_package_aus');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('packageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('auid', XMLDB_TYPE_CHAR, '1333', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('url', XMLDB_TYPE_CHAR, '1333', null, XMLDB_NOTNULL, null, null);
        $table->add_field('launchmethod', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'AnyWindow');
        $table->add_field('moveoncriteria', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'NotApplicable');
        $table->add_field('masteryscore', XMLDB_TYPE_NUMBER, '10', null, null, null, null, null, 7);
        $table->add_field('launchparameters', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('entitlementkey', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('parentblockid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('isexternal', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('packageid', XMLDB_KEY_FOREIGN, ['packageid'], 'cmi5_packages', ['id']);
        $table->add_index('packageid_sortorder', XMLDB_INDEX_NOTUNIQUE, ['packageid', 'sortorder']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 3. Create cmi5_package_blocks table.
        $table = new xmldb_table('cmi5_package_blocks');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('packageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('blockid', XMLDB_TYPE_CHAR, '1333', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('parentblockid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('packageid', XMLDB_KEY_FOREIGN, ['packageid'], 'cmi5_packages', ['id']);
        $table->add_index('packageid_sortorder', XMLDB_INDEX_NOTUNIQUE, ['packageid', 'sortorder']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 4. Add packageid column to cmi5 table.
        $table = new xmldb_table('cmi5');
        $field = new xmldb_field('packageid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'packagefilename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('packageid', XMLDB_INDEX_NOTUNIQUE, ['packageid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026022700, 'cmi5');
    }

    if ($oldversion < 2026022701) {
        // Add launchparameters column to cmi5 table.
        $table = new xmldb_table('cmi5');
        $field = new xmldb_field('launchparameters', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lrsmode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026022701, 'cmi5');
    }

    if ($oldversion < 2026022702) {

        // 1. Create cmi5_launch_profiles table.
        $table = new xmldb_table('cmi5_launch_profiles');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('parameters', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2. Add profileid column to cmi5 table.
        $table = new xmldb_table('cmi5');
        $field = new xmldb_field('profileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'launchparameters');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('profileid', XMLDB_INDEX_NOTUNIQUE, ['profileid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026022702, 'cmi5');
    }

    if ($oldversion < 2026022703) {

        // 1. Create cmi5_state_documents table.
        $table = new xmldb_table('cmi5_state_documents');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('registrationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('activityid', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('activityidhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('stateid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('document', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('etag', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('registrationid', XMLDB_KEY_FOREIGN, ['registrationid'], 'cmi5_registrations', ['id']);
        $table->add_index('reg_acthash_state', XMLDB_INDEX_UNIQUE, ['registrationid', 'activityidhash', 'stateid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2. Create cmi5_agent_profiles table.
        $table = new xmldb_table('cmi5_agent_profiles');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('profileid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('document', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('etag', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_profileid', XMLDB_INDEX_UNIQUE, ['userid', 'profileid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026022703, 'cmi5');
    }

    if ($oldversion < 2026022704) {

        // Add profileid column to cmi5_packages table.
        $table = new xmldb_table('cmi5_packages');
        $field = new xmldb_field('profileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('profileid', XMLDB_INDEX_NOTUNIQUE, ['profileid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026022704, 'cmi5');
    }

    if ($oldversion < 2026022800) {

        // 1. Create cmi5_package_versions table.
        $table = new xmldb_table('cmi5_package_versions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('packageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('versionnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('externalurl', XMLDB_TYPE_CHAR, '1333', null, null, null, null);
        $table->add_field('sha256hash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('courseid_iri', XMLDB_TYPE_CHAR, '1333', null, null, null, null);
        $table->add_field('profileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('usagecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('changelog', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('packageid', XMLDB_KEY_FOREIGN, ['packageid'], 'cmi5_packages', ['id']);
        $table->add_index('packageid_versionnumber', XMLDB_INDEX_UNIQUE, ['packageid', 'versionnumber']);
        $table->add_index('sha256hash', XMLDB_INDEX_NOTUNIQUE, ['sha256hash']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2. Migrate existing packages → version 1 rows.
        $existingpackages = $DB->get_records('cmi5_packages');
        $packageversionmap = []; // packageid => versionid.
        foreach ($existingpackages as $pkg) {
            $version = new \stdClass();
            $version->packageid = $pkg->id;
            $version->versionnumber = 1;
            $version->source = $pkg->source;
            $version->externalurl = $pkg->externalurl ?? null;
            $version->sha256hash = $pkg->sha256hash ?? null;
            $version->courseid_iri = $pkg->courseid_iri ?? null;
            $version->profileid = $pkg->profileid ?? null;
            $version->usagecount = $pkg->usagecount;
            $version->status = $pkg->status;
            $version->changelog = null;
            $version->createdby = $pkg->createdby;
            $version->timecreated = $pkg->timecreated;
            $version->id = $DB->insert_record('cmi5_package_versions', $version);
            $packageversionmap[$pkg->id] = $version->id;
        }

        // 3. Add latestversion to cmi5_packages.
        $table = new xmldb_table('cmi5_packages');
        $field = new xmldb_field('latestversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'description');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Populate latestversion from migrated versions.
        foreach ($packageversionmap as $pkgid => $verid) {
            $DB->set_field('cmi5_packages', 'latestversion', $verid, ['id' => $pkgid]);
        }

        // Add index on latestversion.
        $index = new xmldb_index('latestversion', XMLDB_INDEX_NOTUNIQUE, ['latestversion']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 4. Add versionid to cmi5_package_aus, populate, drop packageid.
        $table = new xmldb_table('cmi5_package_aus');
        $field = new xmldb_field('versionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Populate versionid from packageid mapping.
        foreach ($packageversionmap as $pkgid => $verid) {
            $DB->execute(
                "UPDATE {cmi5_package_aus} SET versionid = :verid WHERE packageid = :pkgid",
                ['verid' => $verid, 'pkgid' => $pkgid]
            );
        }

        // Make versionid NOT NULL now that it's populated.
        $field = new xmldb_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');
        $dbman->change_field_notnull($table, $field);

        // Drop old packageid FK and index, then column.
        $key = new xmldb_key('packageid', XMLDB_KEY_FOREIGN, ['packageid'], 'cmi5_packages', ['id']);
        $dbman->drop_key($table, $key);
        $index = new xmldb_index('packageid_sortorder', XMLDB_INDEX_NOTUNIQUE, ['packageid', 'sortorder']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new xmldb_field('packageid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Add new FK and index.
        $key = new xmldb_key('versionid', XMLDB_KEY_FOREIGN, ['versionid'], 'cmi5_package_versions', ['id']);
        $dbman->add_key($table, $key);
        $index = new xmldb_index('versionid_sortorder', XMLDB_INDEX_NOTUNIQUE, ['versionid', 'sortorder']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 5. Same for cmi5_package_blocks.
        $table = new xmldb_table('cmi5_package_blocks');
        $field = new xmldb_field('versionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        foreach ($packageversionmap as $pkgid => $verid) {
            $DB->execute(
                "UPDATE {cmi5_package_blocks} SET versionid = :verid WHERE packageid = :pkgid",
                ['verid' => $verid, 'pkgid' => $pkgid]
            );
        }

        $field = new xmldb_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');
        $dbman->change_field_notnull($table, $field);

        $key = new xmldb_key('packageid', XMLDB_KEY_FOREIGN, ['packageid'], 'cmi5_packages', ['id']);
        $dbman->drop_key($table, $key);
        $index = new xmldb_index('packageid_sortorder', XMLDB_INDEX_NOTUNIQUE, ['packageid', 'sortorder']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new xmldb_field('packageid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $key = new xmldb_key('versionid', XMLDB_KEY_FOREIGN, ['versionid'], 'cmi5_package_versions', ['id']);
        $dbman->add_key($table, $key);
        $index = new xmldb_index('versionid_sortorder', XMLDB_INDEX_NOTUNIQUE, ['versionid', 'sortorder']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 6. Add packageversionid to cmi5 table, populate from packageid mapping.
        $table = new xmldb_table('cmi5');
        $field = new xmldb_field('packageversionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'packageid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        foreach ($packageversionmap as $pkgid => $verid) {
            $DB->execute(
                "UPDATE {cmi5} SET packageversionid = :verid WHERE packageid = :pkgid",
                ['verid' => $verid, 'pkgid' => $pkgid]
            );
        }

        $index = new xmldb_index('packageversionid', XMLDB_INDEX_NOTUNIQUE, ['packageversionid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 7. Migrate file areas: library_package and library_content itemids from packageid to versionid.
        // Also recalculate pathnamehash since it includes the itemid.
        $syscontext = \context_system::instance();
        foreach ($packageversionmap as $pkgid => $verid) {
            $DB->execute(
                "UPDATE {files} SET itemid = :verid
                 WHERE contextid = :ctxid AND component = 'mod_cmi5'
                   AND filearea IN ('library_package', 'library_content')
                   AND itemid = :pkgid",
                ['verid' => $verid, 'ctxid' => $syscontext->id, 'pkgid' => $pkgid]
            );
        }

        // Recalculate pathnamehash for all migrated files (hash includes itemid).
        $migratedfiles = $DB->get_records_sql(
            "SELECT * FROM {files} WHERE contextid = :ctxid AND component = 'mod_cmi5'
               AND filearea IN ('library_package', 'library_content')",
            ['ctxid' => $syscontext->id]
        );
        foreach ($migratedfiles as $f) {
            $correcthash = sha1("/{$f->contextid}/{$f->component}/{$f->filearea}/{$f->itemid}{$f->filepath}{$f->filename}");
            if ($f->pathnamehash !== $correcthash) {
                $DB->set_field('files', 'pathnamehash', $correcthash, ['id' => $f->id]);
            }
        }

        // 8. Drop moved columns from cmi5_packages.
        $table = new xmldb_table('cmi5_packages');

        // Drop indexes first.
        $index = new xmldb_index('sha256hash', XMLDB_INDEX_NOTUNIQUE, ['sha256hash']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('profileid', XMLDB_INDEX_NOTUNIQUE, ['profileid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $dropfields = ['source', 'externalurl', 'sha256hash', 'courseid_iri', 'profileid',
                        'usagecount', 'status', 'createdby'];
        foreach ($dropfields as $fname) {
            $field = new xmldb_field($fname);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026022800, 'cmi5');
    }

    if ($oldversion < 2026030200) {

        // 1. Add new columns to cmi5_statements.
        $table = new xmldb_table('cmi5_statements');

        $field = new xmldb_field('stored', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'forwarded');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('authority_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'stored');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('voided', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'authority_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('actor_hash', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'voided');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('activity_id', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'actor_hash');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('registration', XMLDB_TYPE_CHAR, '36', null, null, null, null, 'activity_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add indexes on new columns.
        $index = new xmldb_index('actor_hash', XMLDB_INDEX_NOTUNIQUE, ['actor_hash']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('activity_id', XMLDB_INDEX_NOTUNIQUE, ['activity_id']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('registration', XMLDB_INDEX_NOTUNIQUE, ['registration']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('verb', XMLDB_INDEX_NOTUNIQUE, ['verb']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 2. Create cmi5_activity_profiles table.
        $table = new xmldb_table('cmi5_activity_profiles');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('activityid', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('activityidhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('profileid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('document', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('etag', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('acthash_profileid', XMLDB_INDEX_UNIQUE, ['activityidhash', 'profileid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 3. Backfill denormalized columns from existing statement_json.
        $rs = $DB->get_recordset('cmi5_statements', null, '', 'id, statement_json, timecreated');
        foreach ($rs as $rec) {
            $stmt = json_decode($rec->statement_json);
            if (!$stmt) {
                continue;
            }

            $update = new \stdClass();
            $update->id = $rec->id;

            // stored.
            $update->stored = gmdate('Y-m-d\TH:i:s.000\Z', $rec->timecreated);

            // actor_hash.
            if (isset($stmt->actor->account->homePage, $stmt->actor->account->name)) {
                $update->actor_hash = sha1($stmt->actor->account->homePage . '|' . $stmt->actor->account->name);
            }

            // activity_id.
            if (isset($stmt->object->id)) {
                $objectid = $stmt->object->id;
                $update->activity_id = (strlen($objectid) > 255) ? substr($objectid, 0, 255) : $objectid;
            }

            // registration.
            if (isset($stmt->context->registration)) {
                $update->registration = $stmt->context->registration;
            }

            $DB->update_record('cmi5_statements', $update);
        }
        $rs->close();

        upgrade_mod_savepoint(true, 2026030200, 'cmi5');
    }

    return true;
}
