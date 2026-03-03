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
 * Content library for cmi5 activity module.
 *
 * Manages the site-wide centralized content library where cmi5 packages
 * and external AUs are stored independently of activity instances.
 * Supports package versioning with AU-level change tracking.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

class content_library {

    /** @var int Package source: ZIP upload. */
    const SOURCE_ZIP = 0;
    /** @var int Package source: external URL. */
    const SOURCE_EXTERNAL_URL = 1;
    /** @var int Package source: API-registered. */
    const SOURCE_API = 2;

    /** @var int Package status: disabled. */
    const STATUS_DISABLED = 0;
    /** @var int Package status: active. */
    const STATUS_ACTIVE = 1;

    /** @var array AU fields tracked for changelog computation. */
    const TRACKED_AU_FIELDS = [
        'title', 'description', 'url', 'launchmethod', 'moveoncriteria',
        'masteryscore', 'launchparameters', 'entitlementkey',
    ];

    /**
     * Upload a cmi5 ZIP package to the content library.
     *
     * If $packageid is 0, creates a new package with version 1.
     * If $packageid is nonzero, creates a new version under the existing package.
     *
     * @param \stored_file $zipfile The uploaded ZIP file from Moodle file API.
     * @param string $title Optional title override. If empty, uses course title from cmi5.xml.
     * @param string $description Optional description override.
     * @param int $profileid Optional launch profile ID to associate with the version.
     * @param int $packageid Existing package ID for new version upload, or 0 for new package.
     * @return \stdClass The created cmi5_package_versions record with 'packageid' set.
     */
    public static function upload_package(\stored_file $zipfile, string $title = '',
            string $description = '', int $profileid = 0, int $packageid = 0): \stdClass {
        global $DB, $USER;

        $syscontext = \context_system::instance();
        $fs = get_file_storage();

        // Compute hash for dedup detection.
        $sha256 = $zipfile->get_contenthash();

        // Extract to temp dir.
        $tempdir = make_request_directory();
        $packer = get_file_packer('application/zip');
        $zipfile->extract_to_pathname($packer, $tempdir);

        // Parse cmi5.xml.
        $cmi5xmlpath = $tempdir . '/cmi5.xml';
        if (!file_exists($cmi5xmlpath)) {
            throw new \moodle_exception('cmi5xmlnotfound', 'mod_cmi5');
        }
        $xmlcontent = file_get_contents($cmi5xmlpath);
        if ($xmlcontent === false) {
            throw new \moodle_exception('cmi5xmlreaderror', 'mod_cmi5');
        }

        $structure = cmi5_package::parse_cmi5_xml_static($xmlcontent);

        // Use parsed title/description if not overridden.
        if (empty($title)) {
            $title = $structure->coursetitle ?: 'Untitled Package';
        }
        if (empty($description)) {
            $description = $structure->coursedescription ?? '';
        }

        $now = time();

        $transaction = $DB->start_delegated_transaction();

        try {
            if ($packageid > 0) {
                // New version of existing package.
                $package = $DB->get_record('cmi5_packages', ['id' => $packageid], '*', MUST_EXIST);
                $maxversion = (int) $DB->get_field_sql(
                    "SELECT MAX(versionnumber) FROM {cmi5_package_versions} WHERE packageid = :pkgid",
                    ['pkgid' => $packageid]
                );
                $versionnumber = $maxversion + 1;

                // Compute changelog against previous version.
                $previousversionid = $package->latestversion;
                $changelog = null;
                if ($previousversionid) {
                    $changelog = self::compute_changelog((int) $previousversionid, $structure);
                }

                // Update package title/description.
                $DB->update_record('cmi5_packages', (object) [
                    'id' => $packageid,
                    'title' => $title,
                    'description' => $description,
                    'timemodified' => $now,
                ]);
            } else {
                // New package.
                $pkgrecord = new \stdClass();
                $pkgrecord->title = $title;
                $pkgrecord->description = $description;
                $pkgrecord->timecreated = $now;
                $pkgrecord->timemodified = $now;
                $pkgrecord->id = $DB->insert_record('cmi5_packages', $pkgrecord);
                $packageid = $pkgrecord->id;
                $versionnumber = 1;
                $changelog = null;
            }

            // Create the version record.
            $version = new \stdClass();
            $version->packageid = $packageid;
            $version->versionnumber = $versionnumber;
            $version->source = self::SOURCE_ZIP;
            $version->sha256hash = $sha256;
            $version->courseid_iri = $structure->courseid;
            $version->profileid = $profileid ?: null;
            $version->usagecount = 0;
            $version->status = self::STATUS_ACTIVE;
            $version->changelog = $changelog;
            $version->createdby = $USER->id;
            $version->timecreated = $now;
            $version->id = $DB->insert_record('cmi5_package_versions', $version);

            // Update latestversion pointer.
            $DB->set_field('cmi5_packages', 'latestversion', $version->id, ['id' => $packageid]);

            // Clear any stale files at this itemid before storing.
            $fs->delete_area_files($syscontext->id, 'mod_cmi5', 'library_package', $version->id);

            // Store the ZIP in library_package file area keyed by versionid.
            $filerecord = [
                'contextid' => $syscontext->id,
                'component' => 'mod_cmi5',
                'filearea' => 'library_package',
                'itemid' => $version->id,
                'filepath' => '/',
                'filename' => $zipfile->get_filename(),
            ];
            $fs->create_file_from_storedfile($filerecord, $zipfile);

            // Save AUs and blocks to the version tables.
            self::save_package_structure($version->id, $structure);

            // Extract content files to library_content area keyed by versionid.
            self::extract_library_content_files($version->id, $tempdir);

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }

        $version->packageid = $packageid;
        return $version;
    }

    /**
     * Upload a package from a draft area file (form upload or web service).
     *
     * @param int $draftitemid The draft area item ID containing the uploaded ZIP.
     * @param string $title Optional title override.
     * @param string $description Optional description override.
     * @param int $profileid Optional launch profile ID.
     * @param int $packageid Existing package ID for new version, or 0 for new package.
     * @return \stdClass The created cmi5_package_versions record.
     */
    public static function upload_package_from_draft(int $draftitemid, string $title = '',
            string $description = '', int $profileid = 0, int $packageid = 0): \stdClass {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);

        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'sortorder, id', false);
        if (empty($files)) {
            throw new \moodle_exception('packagenotfound', 'mod_cmi5');
        }

        $zipfile = reset($files);
        return self::upload_package($zipfile, $title, $description, $profileid, $packageid);
    }

    /**
     * Register an external AU (no ZIP needed).
     *
     * If $packageid is 0, creates a new package with version 1.
     * If $packageid is nonzero, creates a new version under the existing package.
     *
     * @param string $title The AU/package title.
     * @param string $auid The AU IRI identifier.
     * @param string $url The absolute URL to the AU content.
     * @param string $description Optional description.
     * @param string $launchmethod Launch method: AnyWindow or OwnWindow.
     * @param string $moveoncriteria moveOn criteria.
     * @param float|null $masteryscore Optional mastery score.
     * @param string|null $launchparameters Optional launch parameters.
     * @param int $profileid Optional launch profile ID.
     * @param int $packageid Existing package ID for new version, or 0 for new package.
     * @return \stdClass The created cmi5_package_versions record with 'auid' property set.
     */
    public static function register_external_au(string $title, string $auid, string $url,
            string $description = '', string $launchmethod = 'AnyWindow',
            string $moveoncriteria = 'NotApplicable', ?float $masteryscore = null,
            ?string $launchparameters = null, int $profileid = 0, int $packageid = 0): \stdClass {
        global $DB, $USER;

        $now = time();

        $transaction = $DB->start_delegated_transaction();

        try {
            if ($packageid > 0) {
                $package = $DB->get_record('cmi5_packages', ['id' => $packageid], '*', MUST_EXIST);
                $maxversion = (int) $DB->get_field_sql(
                    "SELECT MAX(versionnumber) FROM {cmi5_package_versions} WHERE packageid = :pkgid",
                    ['pkgid' => $packageid]
                );
                $versionnumber = $maxversion + 1;

                // Compute changelog: build a structure object for the new AU.
                $previousversionid = $package->latestversion;
                $changelog = null;
                if ($previousversionid) {
                    $newstructure = new \stdClass();
                    $newstructure->aus = [(object) [
                        'auid' => $auid,
                        'title' => $title,
                        'description' => $description,
                        'url' => $url,
                        'launchmethod' => $launchmethod,
                        'moveoncriteria' => $moveoncriteria,
                        'masteryscore' => $masteryscore,
                        'launchparameters' => $launchparameters,
                        'entitlementkey' => null,
                    ]];
                    $newstructure->blocks = [];
                    $changelog = self::compute_changelog((int) $previousversionid, $newstructure);
                }

                $DB->update_record('cmi5_packages', (object) [
                    'id' => $packageid,
                    'title' => $title,
                    'description' => $description,
                    'timemodified' => $now,
                ]);
            } else {
                $pkgrecord = new \stdClass();
                $pkgrecord->title = $title;
                $pkgrecord->description = $description;
                $pkgrecord->timecreated = $now;
                $pkgrecord->timemodified = $now;
                $pkgrecord->id = $DB->insert_record('cmi5_packages', $pkgrecord);
                $packageid = $pkgrecord->id;
                $versionnumber = 1;
                $changelog = null;
            }

            // Create the version record.
            $version = new \stdClass();
            $version->packageid = $packageid;
            $version->versionnumber = $versionnumber;
            $version->source = self::SOURCE_API;
            $version->externalurl = $url;
            $version->courseid_iri = null;
            $version->profileid = $profileid ?: null;
            $version->usagecount = 0;
            $version->status = self::STATUS_ACTIVE;
            $version->changelog = $changelog;
            $version->createdby = $USER->id;
            $version->timecreated = $now;
            $version->id = $DB->insert_record('cmi5_package_versions', $version);

            // Update latestversion.
            $DB->set_field('cmi5_packages', 'latestversion', $version->id, ['id' => $packageid]);

            // Create the single AU.
            $aurecord = new \stdClass();
            $aurecord->versionid = $version->id;
            $aurecord->auid = $auid;
            $aurecord->title = $title;
            $aurecord->description = $description;
            $aurecord->url = $url;
            $aurecord->launchmethod = $launchmethod;
            $aurecord->moveoncriteria = $moveoncriteria;
            $aurecord->masteryscore = $masteryscore;
            $aurecord->launchparameters = $launchparameters;
            $aurecord->sortorder = 0;
            $aurecord->isexternal = 1;
            $aurecord->id = $DB->insert_record('cmi5_package_aus', $aurecord);

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }

        $version->auid = $aurecord->id;
        $version->packageid = $packageid;
        return $version;
    }

    /**
     * Get a package record by ID.
     *
     * @param int $packageid The package ID.
     * @return \stdClass|null The package record, or null if not found.
     */
    public static function get_package(int $packageid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('cmi5_packages', ['id' => $packageid]);
        return $record ?: null;
    }

    /**
     * Get a single version record.
     *
     * @param int $versionid The version ID.
     * @return \stdClass|null The version record, or null if not found.
     */
    public static function get_version(int $versionid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('cmi5_package_versions', ['id' => $versionid]);
        return $record ?: null;
    }

    /**
     * Get all versions for a package, ordered by version number descending.
     *
     * @param int $packageid The package ID.
     * @return array Array of version records.
     */
    public static function get_package_versions(int $packageid): array {
        global $DB;
        return array_values($DB->get_records('cmi5_package_versions',
            ['packageid' => $packageid], 'versionnumber DESC'));
    }

    /**
     * Get a package with its AUs and blocks from a specific version.
     *
     * @param int $packageid The package ID.
     * @param int $versionid Optional version ID; defaults to latestversion.
     * @return \stdClass The package record with 'aus', 'blocks', and version fields.
     */
    public static function get_package_details(int $packageid, int $versionid = 0): \stdClass {
        global $DB;

        $package = $DB->get_record('cmi5_packages', ['id' => $packageid], '*', MUST_EXIST);

        if ($versionid <= 0) {
            $versionid = (int) $package->latestversion;
        }

        if ($versionid > 0) {
            $version = $DB->get_record('cmi5_package_versions', ['id' => $versionid], '*', MUST_EXIST);
            // Merge version fields into the package object.
            $package->versionid = $version->id;
            $package->versionnumber = $version->versionnumber;
            $package->source = $version->source;
            $package->externalurl = $version->externalurl;
            $package->sha256hash = $version->sha256hash;
            $package->courseid_iri = $version->courseid_iri;
            $package->profileid = $version->profileid;
            $package->usagecount = $version->usagecount;
            $package->status = $version->status;
            $package->changelog = $version->changelog;
            $package->createdby = $version->createdby;

            $package->aus = array_values($DB->get_records('cmi5_package_aus',
                ['versionid' => $versionid], 'sortorder ASC'));
            $package->blocks = array_values($DB->get_records('cmi5_package_blocks',
                ['versionid' => $versionid], 'sortorder ASC'));
        } else {
            // No versions yet (shouldn't happen in practice).
            $package->versionid = 0;
            $package->versionnumber = 0;
            $package->source = 0;
            $package->usagecount = 0;
            $package->status = 1;
            $package->aus = [];
            $package->blocks = [];
        }

        return $package;
    }

    /**
     * List packages in the library with optional filtering.
     *
     * @param string $search Search string to filter by title.
     * @param int $status Filter by status (-1 for all). Checks latest version status.
     * @param int $offset Pagination offset.
     * @param int $limit Maximum results.
     * @return array Array of package records.
     */
    public static function list_packages(string $search = '', int $status = -1,
            int $offset = 0, int $limit = 50): array {
        global $DB;

        $conditions = [];
        $params = [];

        if ($status >= 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM {cmi5_package_versions} v WHERE v.id = p.latestversion AND v.status = :status)';
            $params['status'] = $status;
        }

        if (!empty($search)) {
            $conditions[] = $DB->sql_like('p.title', ':search', false);
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        $where = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

        $sql = "SELECT p.* FROM {cmi5_packages} p WHERE {$where} ORDER BY p.timemodified DESC";
        return array_values($DB->get_records_sql($sql, $params, $offset, $limit));
    }

    /**
     * Get the total count of packages matching the filter.
     *
     * @param string $search Search string.
     * @param int $status Filter by status (-1 for all).
     * @return int Total count.
     */
    public static function count_packages(string $search = '', int $status = -1): int {
        global $DB;

        $conditions = [];
        $params = [];

        if ($status >= 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM {cmi5_package_versions} v WHERE v.id = p.latestversion AND v.status = :status)';
            $params['status'] = $status;
        }

        if (!empty($search)) {
            $conditions[] = $DB->sql_like('p.title', ':search', false);
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        $where = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

        return $DB->count_records_sql("SELECT COUNT(*) FROM {cmi5_packages} p WHERE {$where}", $params);
    }

    /**
     * Delete a package from the library, including all versions.
     *
     * @param int $packageid The package ID.
     * @param bool $force Force deletion even if activities reference it.
     * @throws \moodle_exception If package is in use and force is false.
     */
    public static function delete_package(int $packageid, bool $force = false): void {
        global $DB;

        $package = $DB->get_record('cmi5_packages', ['id' => $packageid], '*', MUST_EXIST);

        // Sum usage across all versions.
        $totalusage = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(usagecount), 0) FROM {cmi5_package_versions} WHERE packageid = :pkgid",
            ['pkgid' => $packageid]
        );

        if ($totalusage > 0 && !$force) {
            throw new \moodle_exception('library:packageinuse', 'mod_cmi5', '', $totalusage);
        }

        // If forcing deletion, unlink activities that reference this package.
        if ($force && $totalusage > 0) {
            $DB->set_field('cmi5', 'packageid', null, ['packageid' => $packageid]);
            $DB->set_field('cmi5', 'packageversionid', null, ['packageid' => $packageid]);
        }

        // Delete all versions and their content.
        $versions = $DB->get_records('cmi5_package_versions', ['packageid' => $packageid]);
        $syscontext = \context_system::instance();
        $fs = get_file_storage();

        foreach ($versions as $version) {
            $fs->delete_area_files($syscontext->id, 'mod_cmi5', 'library_package', $version->id);
            $fs->delete_area_files($syscontext->id, 'mod_cmi5', 'library_content', $version->id);
            $DB->delete_records('cmi5_package_aus', ['versionid' => $version->id]);
            $DB->delete_records('cmi5_package_blocks', ['versionid' => $version->id]);
        }

        $DB->delete_records('cmi5_package_versions', ['packageid' => $packageid]);
        $DB->delete_records('cmi5_packages', ['id' => $packageid]);
    }

    /**
     * Copy package version structure (AUs and blocks) to an activity instance.
     *
     * @param int $versionid The package version ID.
     * @param int $cmi5id The activity instance ID.
     * @param int|null $singleauid If set, only copy this specific package AU (by DB id).
     */
    public static function copy_structure_to_activity(int $versionid, int $cmi5id, ?int $singleauid = null): void {
        global $DB;

        // Clear any existing structure for this activity.
        $DB->delete_records('cmi5_aus', ['cmi5id' => $cmi5id]);
        $DB->delete_records('cmi5_blocks', ['cmi5id' => $cmi5id]);

        // Get version structure.
        $pkgaus = $DB->get_records('cmi5_package_aus', ['versionid' => $versionid], 'sortorder ASC');

        // If selecting a single AU, skip blocks entirely (single AU = single activity).
        if ($singleauid !== null) {
            $pkgaus = array_filter($pkgaus, function($au) use ($singleauid) {
                return (int) $au->id === $singleauid;
            });
        }

        // Only copy blocks if we're copying all AUs.
        $blockidmap = [];
        if ($singleauid === null) {
            $pkgblocks = $DB->get_records('cmi5_package_blocks', ['versionid' => $versionid], 'sortorder ASC');
            foreach ($pkgblocks as $pkgblock) {
                $record = new \stdClass();
                $record->cmi5id = $cmi5id;
                $record->blockid = $pkgblock->blockid;
                $record->title = $pkgblock->title;
                $record->description = $pkgblock->description;
                $record->parentblockid = null;
                if ($pkgblock->parentblockid && isset($blockidmap[$pkgblock->parentblockid])) {
                    $record->parentblockid = $blockidmap[$pkgblock->parentblockid];
                }
                $record->sortorder = $pkgblock->sortorder;

                $newid = $DB->insert_record('cmi5_blocks', $record);
                $blockidmap[$pkgblock->id] = $newid;
            }
        }

        foreach ($pkgaus as $pkgau) {
            $record = new \stdClass();
            $record->cmi5id = $cmi5id;
            $record->auid = $pkgau->auid;
            $record->title = $pkgau->title;
            $record->description = $pkgau->description;
            $record->url = $pkgau->url;
            $record->launchmethod = $pkgau->launchmethod;
            $record->moveoncriteria = $pkgau->moveoncriteria;
            $record->masteryscore = $pkgau->masteryscore;
            $record->launchparameters = $pkgau->launchparameters;
            $record->entitlementkey = $pkgau->entitlementkey;
            $record->parentblockid = null;
            if ($singleauid === null && $pkgau->parentblockid && isset($blockidmap[$pkgau->parentblockid])) {
                $record->parentblockid = $blockidmap[$pkgau->parentblockid];
            }
            $record->sortorder = $pkgau->sortorder;

            $DB->insert_record('cmi5_aus', $record);
        }
    }

    /**
     * Increment the usage count for a package version.
     *
     * @param int $versionid The version ID.
     */
    public static function increment_usage(int $versionid): void {
        global $DB;
        $sql = "UPDATE {cmi5_package_versions} SET usagecount = usagecount + 1 WHERE id = :id";
        $DB->execute($sql, ['id' => $versionid]);
    }

    /**
     * Decrement the usage count for a package version.
     *
     * @param int $versionid The version ID.
     */
    public static function decrement_usage(int $versionid): void {
        global $DB;
        $sql = "UPDATE {cmi5_package_versions} SET usagecount = CASE WHEN usagecount > 0 THEN usagecount - 1 ELSE 0 END
                WHERE id = :id";
        $DB->execute($sql, ['id' => $versionid]);
    }

    /**
     * Compute changelog between a previous version and new structure.
     *
     * Compares AUs by IRI and blocks by IRI, tracking additions, removals, and field changes.
     *
     * @param int $previousversionid The previous version ID.
     * @param \stdClass $newstructure The new parsed structure with 'aus' and 'blocks' arrays.
     * @return string|null JSON changelog string, or null if no changes.
     */
    public static function compute_changelog(int $previousversionid, \stdClass $newstructure): ?string {
        global $DB;

        $changes = [];

        // Get previous AUs indexed by auid IRI.
        $prevaus = $DB->get_records('cmi5_package_aus', ['versionid' => $previousversionid]);
        $prevaumap = [];
        foreach ($prevaus as $au) {
            $prevaumap[$au->auid] = $au;
        }

        // Build new AU map.
        $newaumap = [];
        foreach ($newstructure->aus as $au) {
            $newaumap[$au->auid] = $au;
        }

        // Detect added and changed AUs.
        foreach ($newaumap as $auid => $newau) {
            if (!isset($prevaumap[$auid])) {
                $changes[] = [
                    'type' => 'au_added',
                    'auid' => $auid,
                    'title' => $newau->title,
                ];
            } else {
                $prevau = $prevaumap[$auid];
                foreach (self::TRACKED_AU_FIELDS as $field) {
                    $oldval = $prevau->$field ?? null;
                    $newval = $newau->$field ?? null;
                    // Normalize for comparison.
                    if (is_numeric($oldval)) {
                        $oldval = (float) $oldval;
                    }
                    if (is_numeric($newval)) {
                        $newval = (float) $newval;
                    }
                    if ($oldval != $newval) {
                        $changes[] = [
                            'type' => 'au_changed',
                            'auid' => $auid,
                            'title' => $newau->title,
                            'field' => $field,
                            'old' => (string) ($oldval ?? ''),
                            'new' => (string) ($newval ?? ''),
                        ];
                    }
                }
            }
        }

        // Detect removed AUs.
        foreach ($prevaumap as $auid => $prevau) {
            if (!isset($newaumap[$auid])) {
                $changes[] = [
                    'type' => 'au_removed',
                    'auid' => $auid,
                    'title' => $prevau->title,
                ];
            }
        }

        // Get previous blocks indexed by blockid IRI.
        $prevblocks = $DB->get_records('cmi5_package_blocks', ['versionid' => $previousversionid]);
        $prevblockmap = [];
        foreach ($prevblocks as $block) {
            $prevblockmap[$block->blockid] = $block;
        }

        $newblockmap = [];
        foreach (($newstructure->blocks ?? []) as $block) {
            $newblockmap[$block->blockid] = $block;
        }

        foreach ($newblockmap as $blockid => $newblock) {
            if (!isset($prevblockmap[$blockid])) {
                $changes[] = [
                    'type' => 'block_added',
                    'blockid' => $blockid,
                    'title' => $newblock->title,
                ];
            }
        }

        foreach ($prevblockmap as $blockid => $prevblock) {
            if (!isset($newblockmap[$blockid])) {
                $changes[] = [
                    'type' => 'block_removed',
                    'blockid' => $blockid,
                    'title' => $prevblock->title,
                ];
            }
        }

        if (empty($changes)) {
            return null;
        }

        return json_encode($changes, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Check if an update is available for a package relative to a specific version.
     *
     * @param int $packageid The package ID.
     * @param int $currentversionid The current version ID the activity is on.
     * @return \stdClass Object with 'available' (bool), 'latestversionnumber' (int), 'changelog' (array).
     */
    public static function check_update_available(int $packageid, int $currentversionid): \stdClass {
        global $DB;

        $result = new \stdClass();
        $result->available = false;
        $result->latestversionnumber = 0;
        $result->latestversionid = 0;
        $result->changelog = [];

        $package = $DB->get_record('cmi5_packages', ['id' => $packageid]);
        if (!$package || !$package->latestversion || (int) $package->latestversion === $currentversionid) {
            return $result;
        }

        $latestversion = $DB->get_record('cmi5_package_versions', ['id' => $package->latestversion]);
        if (!$latestversion) {
            return $result;
        }

        $result->available = true;
        $result->latestversionnumber = (int) $latestversion->versionnumber;
        $result->latestversionid = (int) $latestversion->id;

        // Collect changelogs from all versions between current and latest.
        $currentversion = $DB->get_record('cmi5_package_versions', ['id' => $currentversionid]);
        $currentnum = $currentversion ? (int) $currentversion->versionnumber : 0;

        $newversions = $DB->get_records_select(
            'cmi5_package_versions',
            'packageid = :pkgid AND versionnumber > :curnum',
            ['pkgid' => $packageid, 'curnum' => $currentnum],
            'versionnumber ASC'
        );

        foreach ($newversions as $ver) {
            if (!empty($ver->changelog)) {
                $decoded = json_decode($ver->changelog, true);
                if (is_array($decoded)) {
                    $result->changelog = array_merge($result->changelog, $decoded);
                }
            }
        }

        return $result;
    }

    /**
     * Sync an activity to a new package version.
     *
     * Copies the new version's structure to the activity, updates the packageversionid,
     * and adjusts usage counts.
     *
     * @param int $cmi5id The activity instance ID.
     * @param int $newversionid The target version ID (0 = latest).
     * @param int|null $singleauid If set, only sync this specific AU.
     * @return \stdClass Result with 'success', 'newversionid', 'changelog'.
     */
    public static function sync_activity_to_version(int $cmi5id, int $newversionid = 0,
            ?int $singleauid = null): \stdClass {
        global $DB;

        $cmi5 = $DB->get_record('cmi5', ['id' => $cmi5id], '*', MUST_EXIST);
        $result = new \stdClass();
        $result->success = false;
        $result->newversionid = 0;
        $result->changelog = [];

        if (empty($cmi5->packageid)) {
            return $result;
        }

        $package = $DB->get_record('cmi5_packages', ['id' => $cmi5->packageid], '*', MUST_EXIST);

        if ($newversionid <= 0) {
            $newversionid = (int) $package->latestversion;
        }

        if (!$newversionid) {
            return $result;
        }

        $newversion = $DB->get_record('cmi5_package_versions', ['id' => $newversionid], '*', MUST_EXIST);

        // Decrement old version usage.
        if (!empty($cmi5->packageversionid)) {
            self::decrement_usage((int) $cmi5->packageversionid);
        }

        // Copy structure from new version.
        self::copy_structure_to_activity($newversionid, $cmi5id, $singleauid);

        // Update activity record.
        $DB->set_field('cmi5', 'packageversionid', $newversionid, ['id' => $cmi5id]);
        $DB->set_field('cmi5', 'timemodified', time(), ['id' => $cmi5id]);

        // Copy courseid_iri from the package version.
        if (!empty($newversion->courseid_iri)) {
            $DB->set_field('cmi5', 'courseid_iri', $newversion->courseid_iri, ['id' => $cmi5id]);
        }

        // Increment new version usage.
        self::increment_usage($newversionid);

        $result->success = true;
        $result->newversionid = $newversionid;

        // Collect changelog.
        if (!empty($newversion->changelog)) {
            $decoded = json_decode($newversion->changelog, true);
            if (is_array($decoded)) {
                $result->changelog = $decoded;
            }
        }

        return $result;
    }

    /**
     * Save parsed course structure to the library package version tables.
     *
     * @param int $versionid The version ID.
     * @param \stdClass $structure The parsed structure from cmi5_package::parse_cmi5_xml_static().
     */
    private static function save_package_structure(int $versionid, \stdClass $structure): void {
        global $DB;

        $blockidmap = [];

        foreach ($structure->blocks as $block) {
            $record = new \stdClass();
            $record->versionid = $versionid;
            $record->blockid = $block->blockid;
            $record->title = $block->title;
            $record->description = $block->description;
            $record->parentblockid = null;
            $record->sortorder = $block->sortorder;

            if ($block->parentblockid !== null && isset($blockidmap[$block->parentblockid])) {
                $record->parentblockid = $blockidmap[$block->parentblockid];
            }

            $recordid = $DB->insert_record('cmi5_package_blocks', $record);
            $blockidmap[$block->blockid] = $recordid;
        }

        foreach ($structure->aus as $au) {
            $record = new \stdClass();
            $record->versionid = $versionid;
            $record->auid = $au->auid;
            $record->title = $au->title;
            $record->description = $au->description;
            $record->url = $au->url;
            $record->launchmethod = $au->launchmethod;
            $record->moveoncriteria = $au->moveoncriteria;
            $record->masteryscore = $au->masteryscore;
            $record->launchparameters = $au->launchparameters;
            $record->entitlementkey = $au->entitlementkey;
            $record->sortorder = $au->sortorder;
            $record->isexternal = self::is_absolute_url($au->url) ? 1 : 0;

            $record->parentblockid = null;
            if ($au->parentblockid !== null && isset($blockidmap[$au->parentblockid])) {
                $record->parentblockid = $blockidmap[$au->parentblockid];
            }

            $DB->insert_record('cmi5_package_aus', $record);
        }
    }

    /**
     * Extract content files from temp dir to SYSTEM context library_content area.
     *
     * @param int $versionid The version ID (used as itemid).
     * @param string $tempdir Path to extracted package directory.
     */
    private static function extract_library_content_files(int $versionid, string $tempdir): void {
        $syscontext = \context_system::instance();
        $fs = get_file_storage();

        // Remove any existing content files for this version.
        $fs->delete_area_files($syscontext->id, 'mod_cmi5', 'library_content', $versionid);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempdir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativepath = substr($file->getPathname(), strlen($tempdir));
            $relativepath = str_replace('\\', '/', $relativepath);

            if (strtolower(ltrim($relativepath, '/')) === 'cmi5.xml') {
                continue;
            }

            if ($file->isDir()) {
                $dirpath = '/' . ltrim($relativepath, '/');
                if (substr($dirpath, -1) !== '/') {
                    $dirpath .= '/';
                }
                $filerecord = [
                    'contextid' => $syscontext->id,
                    'component' => 'mod_cmi5',
                    'filearea' => 'library_content',
                    'itemid' => $versionid,
                    'filepath' => $dirpath,
                    'filename' => '.',
                ];
                try {
                    $fs->create_file_from_string($filerecord, '');
                } catch (\Exception $e) {
                    debugging('cmi5 library: skipping directory ' . $dirpath . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER);
                }
            } else {
                $filename = basename($relativepath);
                $dirpart = dirname($relativepath);

                if ($dirpart === '' || $dirpart === '.' || $dirpart === '/') {
                    $filepath = '/';
                } else {
                    $filepath = '/' . ltrim($dirpart, '/');
                    if (substr($filepath, -1) !== '/') {
                        $filepath .= '/';
                    }
                }

                $filename = clean_param($filename, PARAM_FILE);
                if (empty($filename)) {
                    continue;
                }

                $filerecord = [
                    'contextid' => $syscontext->id,
                    'component' => 'mod_cmi5',
                    'filearea' => 'library_content',
                    'itemid' => $versionid,
                    'filepath' => $filepath,
                    'filename' => $filename,
                ];
                try {
                    $fs->create_file_from_pathname($filerecord, $file->getPathname());
                } catch (\Exception $e) {
                    debugging('cmi5 library: skipping file ' . $relativepath . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * Check if a URL is absolute.
     *
     * @param string $url The URL to check.
     * @return bool True if absolute.
     */
    private static function is_absolute_url(string $url): bool {
        return (bool) preg_match('#^https?://#i', $url);
    }
}
