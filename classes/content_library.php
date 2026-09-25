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
 * @copyright  2026 Bylight
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

    /** @var string Sort packages by most recently modified. */
    const SORT_RECENT = 'recent';
    /** @var string Sort packages alphabetically by title. */
    const SORT_TITLE = 'title';
    /** @var string Sort packages by number of activities using them. */
    const SORT_USAGE = 'usage';

    /** @var array Valid sort keys for package listings. */
    const VALID_SORTS = [self::SORT_RECENT, self::SORT_TITLE, self::SORT_USAGE];

    /** @var string Lock factory used to coordinate version assignment and deletion. */
    const VERSION_LOCK_FACTORY = 'mod_cmi5_content_library';

    /** @var string Lock factory guarding which version an activity points at. */
    const ACTIVITY_LOCK_FACTORY = 'mod_cmi5_activity_upgrade';

    /** @var string The activity moved to the requested version. */
    const UPGRADE_UPGRADED = 'upgraded';

    /** @var string Nothing was changed, and nothing needed to be. */
    const UPGRADE_SKIPPED = 'skipped';

    /** @var string The activity could not be moved; it is unchanged. */
    const UPGRADE_FAILED = 'failed';

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

        // Extract to temp dir and read the manifest.
        $tempdir = make_request_directory();
        $structure = self::read_package_zip($zipfile, $tempdir);

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
     * Extract a package ZIP and parse the cmi5.xml manifest inside it.
     *
     * @param \stored_file $zipfile The ZIP to read.
     * @param string $tempdir Directory the ZIP is extracted into. The caller owns it, so that
     *                        upload_package() can go on to store the extracted content files.
     * @return \stdClass The parsed structure, exactly as cmi5_package returns it.
     */
    private static function read_package_zip(\stored_file $zipfile, string $tempdir): \stdClass {
        $packer = get_file_packer('application/zip');
        $zipfile->extract_to_pathname($packer, $tempdir);

        $cmi5xmlpath = $tempdir . '/cmi5.xml';
        if (!file_exists($cmi5xmlpath)) {
            throw new \moodle_exception('cmi5xmlnotfound', 'mod_cmi5');
        }
        $xmlcontent = file_get_contents($cmi5xmlpath);
        if ($xmlcontent === false) {
            throw new \moodle_exception('cmi5xmlreaderror', 'mod_cmi5');
        }

        return cmi5_package::parse_cmi5_xml_static($xmlcontent);
    }

    /**
     * Read a package ZIP without storing anything.
     *
     * This backs the "check what we read" step of the add flow: the admin sees the structure
     * the manifest declares, and only then decides to commit it. Nothing here touches the
     * database or the permanent file areas.
     *
     * The blocks and AUs come back keyed the way the stored records are, so the result can be
     * handed straight to build_structure_tree() and rendered by the same code that draws a
     * stored version.
     *
     * @param \stored_file $zipfile The ZIP to inspect.
     * @return \stdClass Object with courseid, coursetitle, coursedescription, blocks, aus,
     *                   aucount, blockcount, sha256, filename, filesize and existingpackage.
     */
    public static function inspect_package(\stored_file $zipfile): \stdClass {
        $tempdir = make_request_directory();
        $parsed = self::read_package_zip($zipfile, $tempdir);

        $inspection = self::normalize_parsed_structure($parsed);
        $inspection->sha256 = $zipfile->get_contenthash();
        $inspection->filename = $zipfile->get_filename();
        $inspection->filesize = (int) $zipfile->get_filesize();
        $inspection->existingpackage = self::find_package_by_course_iri($inspection->courseid);

        return $inspection;
    }

    /**
     * Read a package ZIP held in a draft file area without storing anything.
     *
     * @param int $draftitemid The draft area item ID holding the uploaded ZIP.
     * @return \stdClass The inspection result, as inspect_package() describes it.
     */
    public static function inspect_draft_package(int $draftitemid): \stdClass {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);

        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'sortorder, id', false);
        if (empty($files)) {
            throw new \moodle_exception('packagenotfound', 'mod_cmi5');
        }

        return self::inspect_package(reset($files));
    }

    /**
     * Give a freshly parsed structure the shape the stored records have.
     *
     * The parser carries a block's own XML id in parentblockid, while everything downstream
     * expects the numeric row id of the parent. save_package_structure() resolves that against
     * the ids the database hands back; here there is no database, so blocks get sequential
     * synthetic ids and the same mapping is applied.
     *
     * @param \stdClass $parsed The structure from cmi5_package::parse_cmi5_xml_static().
     * @return \stdClass Structure with numeric ids on blocks and numeric parentblockid links.
     */
    private static function normalize_parsed_structure(\stdClass $parsed): \stdClass {
        $blockidmap = [];
        $blocks = [];
        $nextid = 1;

        foreach ($parsed->blocks as $block) {
            $record = clone $block;
            $record->id = $nextid++;
            // Parents are always parsed before their children, so the map is already populated.
            $record->parentblockid = $block->parentblockid !== null
                ? ($blockidmap[$block->parentblockid] ?? null)
                : null;
            $blockidmap[$block->blockid] = $record->id;
            unset($record->children);
            $blocks[] = $record;
        }

        $aus = [];
        foreach ($parsed->aus as $index => $au) {
            $record = clone $au;
            // AUs are not stored yet, but the tree builder sorts on id to break sortorder ties.
            $record->id = $index + 1;
            $record->parentblockid = $au->parentblockid !== null
                ? ($blockidmap[$au->parentblockid] ?? null)
                : null;
            $record->isexternal = 0;
            $aus[] = $record;
        }

        $inspection = new \stdClass();
        $inspection->courseid = $parsed->courseid;
        $inspection->coursetitle = $parsed->coursetitle;
        $inspection->coursedescription = $parsed->coursedescription ?? '';
        $inspection->blocks = $blocks;
        $inspection->aus = $aus;
        $inspection->aucount = count($aus);
        $inspection->blockcount = count($blocks);

        return $inspection;
    }

    /**
     * Find the package whose latest version already declares a course IRI.
     *
     * Used to tell an admin that the ZIP they are adding belongs to a course the library
     * already holds, so they can add it as a version instead of a second course.
     *
     * @param string $courseiri The course IRI from the manifest.
     * @return \stdClass|null The package record with versionnumber set, or null when it is new.
     */
    public static function find_package_by_course_iri(string $courseiri): ?\stdClass {
        global $DB;

        if (trim($courseiri) === '') {
            return null;
        }

        $sql = "SELECT p.id, p.title, v.versionnumber
                  FROM {cmi5_packages} p
                  JOIN {cmi5_package_versions} v ON v.id = p.latestversion
                 WHERE v.courseid_iri = :iri";
        $record = $DB->get_record_sql($sql, ['iri' => $courseiri], IGNORE_MULTIPLE);

        return $record ?: null;
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
     * Get the original ZIP uploaded for a package version.
     *
     * The package ID is checked as part of the lookup so a download URL cannot
     * combine a valid version with a different package.
     *
     * @param int $packageid Package ID expected to own the version.
     * @param int $versionid Package version ID.
     * @return \stored_file The original uploaded ZIP.
     * @throws \moodle_exception If the version is invalid or has no available archive.
     */
    public static function get_version_archive(int $packageid, int $versionid): \stored_file {
        global $DB;

        $version = $DB->get_record('cmi5_package_versions', [
            'id' => $versionid,
            'packageid' => $packageid,
        ]);
        if (!$version) {
            throw new \moodle_exception('library:invalidpackageversion', 'cmi5');
        }

        if ((int) $version->source !== self::SOURCE_ZIP) {
            throw new \moodle_exception('library:noarchiveforversion', 'cmi5');
        }

        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'mod_cmi5',
            'library_package',
            $versionid,
            'id ASC',
            false
        );
        if (empty($files)) {
            throw new \moodle_exception('library:archivefilemissing', 'cmi5');
        }

        return reset($files);
    }

    /**
     * Acquire the lock shared by operations which assign or delete a package version.
     *
     * The lock can be acquired even if the version no longer exists. Callers must
     * revalidate the version after acquiring it and must always release the lock.
     *
     * @param int $versionid Package version ID.
     * @return \core\lock\lock Acquired lock.
     * @throws \moodle_exception If the lock cannot be acquired promptly.
     */
    public static function acquire_version_lock(int $versionid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::VERSION_LOCK_FACTORY);
        $lock = $factory->get_lock('version-' . $versionid, 10);
        if (!$lock) {
            throw new \moodle_exception('library:versionbusy', 'cmi5');
        }
        return $lock;
    }

    /**
     * Return the current deletion eligibility for a package version.
     *
     * Usage is always calculated from activity references, never the cached counter.
     *
     * @param int $packageid Package ID expected to own the version.
     * @param int $versionid Package version ID.
     * @return \stdClass Package, version, usage count and eligibility information.
     * @throws \moodle_exception If the package/version pair is invalid.
     */
    public static function get_version_deletion_status(int $packageid, int $versionid): \stdClass {
        global $DB;

        $package = $DB->get_record('cmi5_packages', ['id' => $packageid]);
        $version = $DB->get_record('cmi5_package_versions', [
            'id' => $versionid,
            'packageid' => $packageid,
        ]);
        if (!$package || !$version) {
            throw new \moodle_exception('library:invalidpackageversion', 'cmi5');
        }

        $usagecount = self::count_version_usage($versionid);
        $islatest = (int) $package->latestversion === $versionid;

        return (object) [
            'package' => $package,
            'version' => $version,
            'usagecount' => $usagecount,
            'islatest' => $islatest,
            'candelete' => !$islatest && $usagecount === 0,
        ];
    }

    /**
     * Permanently delete an unused, non-latest package version.
     *
     * Eligibility is checked only after acquiring the same lock used by activity
     * assignment paths, preventing a new reference from racing the deletion.
     *
     * @param int $packageid Package ID expected to own the version.
     * @param int $versionid Package version ID.
     * @throws \moodle_exception If the version is protected or cannot be locked.
     */
    public static function delete_version(int $packageid, int $versionid): void {
        global $DB;

        require_capability('mod/cmi5:managelibrary', \context_system::instance());
        $lock = self::acquire_version_lock($versionid);

        try {
            $status = self::get_version_deletion_status($packageid, $versionid);
            if ($status->islatest) {
                throw new \moodle_exception('library:versionislatest', 'cmi5');
            }
            if ($status->usagecount > 0) {
                $errorcode = $status->usagecount === 1
                    ? 'library:versioninuse_one'
                    : 'library:versioninuse';
                throw new \moodle_exception(
                    $errorcode,
                    'cmi5',
                    '',
                    $status->usagecount
                );
            }

            $event = \mod_cmi5\event\library_version_deleted::create([
                'context' => \context_system::instance(),
                'objectid' => $versionid,
                'other' => [
                    'packageid' => $packageid,
                    'packagetitle' => $status->package->title,
                    'versionnumber' => (int) $status->version->versionnumber,
                ],
            ]);
            $event->add_record_snapshot('cmi5_package_versions', $status->version);

            $transaction = $DB->start_delegated_transaction();
            try {
                get_file_storage()->delete_area_files(
                    \context_system::instance()->id,
                    'mod_cmi5',
                    'library_package',
                    $versionid
                );
                get_file_storage()->delete_area_files(
                    \context_system::instance()->id,
                    'mod_cmi5',
                    'library_content',
                    $versionid
                );
                $DB->delete_records('cmi5_package_aus', ['versionid' => $versionid]);
                $DB->delete_records('cmi5_package_blocks', ['versionid' => $versionid]);
                $DB->delete_records('cmi5_package_versions', ['id' => $versionid]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            $event->trigger();
        } finally {
            $lock->release();
        }
    }

    /**
     * Get all versions for a package, ordered by version number descending.
     *
     * @param int $packageid The package ID.
     * @return array Array of version records.
     */
    public static function get_package_versions(int $packageid): array {
        global $DB;

        // The stored counter is only a cache. Resolve all version counts from current
        // activity references in one query, including legacy activities which use the
        // package's latest version without storing packageversionid.
        $sql = "SELECT v.id, v.packageid, v.versionnumber, v.source, v.externalurl,
                       v.sha256hash, v.courseid_iri, v.profileid, v.status,
                       v.changelog, v.createdby, v.timecreated,
                       (SELECT COUNT(1)
                          FROM {cmi5} c
                         WHERE c.packageversionid = v.id
                            OR (v.id = p.latestversion
                                AND c.packageid = p.id
                                AND (c.packageversionid IS NULL OR c.packageversionid = 0))) AS usagecount
                  FROM {cmi5_package_versions} v
                  JOIN {cmi5_packages} p ON p.id = v.packageid
                 WHERE v.packageid = :packageid
              ORDER BY v.versionnumber DESC";

        return array_values($DB->get_records_sql($sql, ['packageid' => $packageid]));
    }

    /**
     * Return activities that currently depend on a package version.
     *
     * Activities with a direct packageversionid reference are always included. Legacy
     * activities which only have packageid set resolve through the package's latestversion,
     * so they are included when querying that version as well.
     *
     * Course-module data is left joined deliberately: an incomplete or pending-deletion
     * activity record still represents a dependency and must remain visible to management
     * and deletion checks.
     *
     * @param int $versionid Package version ID.
     * @param int $offset Result offset.
     * @param int $limit Maximum records to return; 0 means no limit.
     * @return array List of activity usage records.
     */
    public static function get_version_usage(int $versionid, int $offset = 0, int $limit = 0): array {
        global $DB;

        $version = $DB->get_record('cmi5_package_versions', ['id' => $versionid],
            'id, packageid', MUST_EXIST);
        $package = $DB->get_record('cmi5_packages', ['id' => $version->packageid],
            'id, latestversion', MUST_EXIST);
        $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'cmi5']);

        [$where, $params] = self::version_usage_condition($version, $package);
        $params['moduleid'] = $moduleid;

        $sql = "SELECT c.id AS cmi5id, c.name AS activityname, c.course AS courseid,
                       cr.fullname AS coursename, cr.visible AS coursevisible,
                       cm.id AS cmid, cm.visible AS activityvisible,
                       cm.deletioninprogress
                  FROM {cmi5} c
             LEFT JOIN {course} cr ON cr.id = c.course
             LEFT JOIN {course_modules} cm
                    ON cm.course = c.course
                   AND cm.instance = c.id
                   AND cm.module = :moduleid
                 WHERE {$where}
              ORDER BY cr.sortorder ASC, cr.fullname ASC, c.name ASC, c.id ASC";

        return array_values($DB->get_records_sql($sql, $params, max(0, $offset), max(0, $limit)));
    }

    /**
     * Count activities that currently depend on a package version.
     *
     * @param int $versionid Package version ID.
     * @return int Number of activity references.
     */
    public static function count_version_usage(int $versionid): int {
        global $DB;

        $version = $DB->get_record('cmi5_package_versions', ['id' => $versionid],
            'id, packageid', MUST_EXIST);
        $package = $DB->get_record('cmi5_packages', ['id' => $version->packageid],
            'id, latestversion', MUST_EXIST);
        [$where, $params] = self::version_usage_condition($version, $package);

        return (int) $DB->count_records_sql("SELECT COUNT(1) FROM {cmi5} c WHERE {$where}", $params);
    }

    /**
     * Count activities that currently depend on any version of a package.
     *
     * This also catches inconsistent records where packageid is missing but
     * packageversionid still points to a version owned by the package.
     *
     * @param int $packageid Package ID.
     * @return int Number of activity references.
     */
    public static function count_package_usage(int $packageid): int {
        global $DB;

        $DB->get_record('cmi5_packages', ['id' => $packageid], 'id', MUST_EXIST);

        $sql = "SELECT COUNT(1)
                  FROM {cmi5} c
                 WHERE c.packageid = :activitypackageid
                    OR EXISTS (
                           SELECT 1
                             FROM {cmi5_package_versions} linkedversion
                            WHERE linkedversion.id = c.packageversionid
                              AND linkedversion.packageid = :versionpackageid
                       )";

        return (int) $DB->count_records_sql($sql, [
            'activitypackageid' => $packageid,
            'versionpackageid' => $packageid,
        ]);
    }

    /**
     * Build the condition used by version usage list and count queries.
     *
     * @param \stdClass $version Package version record.
     * @param \stdClass $package Package record.
     * @return array SQL condition and parameters.
     */
    private static function version_usage_condition(\stdClass $version, \stdClass $package): array {
        $params = ['versionid' => (int) $version->id];
        $conditions = ['c.packageversionid = :versionid'];

        if ((int) $package->latestversion === (int) $version->id) {
            $conditions[] = "(c.packageid = :legacypackageid
                              AND (c.packageversionid IS NULL OR c.packageversionid = 0))";
            $params['legacypackageid'] = (int) $version->packageid;
        }

        return ['(' . implode(' OR ', $conditions) . ')', $params];
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
            $version = $DB->get_record('cmi5_package_versions',
                ['id' => $versionid, 'packageid' => $packageid], '*', MUST_EXIST);
            // Merge version fields into the package object.
            $package->versionid = $version->id;
            $package->versionnumber = $version->versionnumber;
            $package->source = $version->source;
            $package->externalurl = $version->externalurl;
            $package->sha256hash = $version->sha256hash;
            $package->courseid_iri = $version->courseid_iri;
            $package->profileid = $version->profileid;
            $package->usagecount = self::count_version_usage((int) $version->id);
            $package->status = $version->status;
            $package->changelog = $version->changelog;
            $package->createdby = $version->createdby;
            $package->versiontimecreated = $version->timecreated;

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
            $package->versiontimecreated = 0;
            $package->aus = [];
            $package->blocks = [];
        }

        return $package;
    }

    /**
     * Validate and resolve a package/AU selection from the activity form.
     *
     * @param int $packageid Package ID.
     * @param string $auvalue Empty for all AUs, or "packageid:packageauid" for one AU.
     * @param int $versionid Version to use, or 0 for the package's latest version.
     * @param bool $activeonly Whether the package version must be active.
     * @return \stdClass Object containing package, version and singleauid.
     */
    public static function resolve_package_selection(int $packageid, string $auvalue = '',
            int $versionid = 0, bool $activeonly = true): \stdClass {
        global $DB;

        $package = $DB->get_record('cmi5_packages', ['id' => $packageid]);
        if (!$package) {
            throw new \moodle_exception('picker:invalidpackage', 'cmi5');
        }

        if ($versionid <= 0) {
            $versionid = (int) $package->latestversion;
        }
        $version = $DB->get_record('cmi5_package_versions', [
            'id' => $versionid,
            'packageid' => $packageid,
        ]);
        if (!$version || ($activeonly && (int) $version->status !== self::STATUS_ACTIVE)) {
            throw new \moodle_exception('picker:invalidpackage', 'cmi5');
        }

        $singleauid = null;
        if ($auvalue !== '') {
            if (!preg_match('/^(\d+):(\d+)$/', $auvalue, $matches)
                    || (int) $matches[1] !== $packageid) {
                throw new \moodle_exception('picker:invalidau', 'cmi5');
            }
            $singleauid = (int) $matches[2];
            if (!$DB->record_exists('cmi5_package_aus', [
                'id' => $singleauid,
                'versionid' => $versionid,
            ])) {
                throw new \moodle_exception('picker:invalidau', 'cmi5');
            }
        }

        return (object) [
            'package' => $package,
            'version' => $version,
            'singleauid' => $singleauid,
        ];
    }

    /**
     * Reconstruct the picker value for an activity using only one AU.
     *
     * @param int $cmi5id Activity instance ID.
     * @param int $packageid Package ID.
     * @param int $versionid Package version ID.
     * @return string Empty for all AUs, or "packageid:packageauid" for one AU.
     */
    public static function get_activity_au_selection(int $cmi5id, int $packageid, int $versionid): string {
        global $DB;

        $activityaus = $DB->get_records('cmi5_aus', ['cmi5id' => $cmi5id, 'retired' => 0]);
        $packageaus = $DB->get_records('cmi5_package_aus', ['versionid' => $versionid]);
        if (count($activityaus) !== 1 || count($packageaus) <= 1) {
            return '';
        }

        $activityau = reset($activityaus);
        foreach ($packageaus as $packageau) {
            if (trim($packageau->auid) === trim($activityau->auid)) {
                return $packageid . ':' . $packageau->id;
            }
        }
        return '';
    }

    /**
     * Find the equivalent AU in another version by its cmi5 AU IRI.
     *
     * @param int $singleauid Source cmi5_package_aus row ID.
     * @param int $targetversionid Target package version ID.
     * @return int Target AU row ID.
     */
    public static function map_au_to_version(int $singleauid, int $targetversionid): int {
        global $DB;

        $sourceau = $DB->get_record('cmi5_package_aus', ['id' => $singleauid], '*', MUST_EXIST);
        $targetau = $DB->get_record('cmi5_package_aus', [
            'versionid' => $targetversionid,
            'auid' => $sourceau->auid,
        ]);
        if (!$targetau) {
            throw new \moodle_exception('picker:invalidau', 'cmi5');
        }
        return (int) $targetau->id;
    }

    /**
     * List packages in the library with optional filtering.
     *
     * @param string $search Search string to filter by title or description.
     * @param int $status Filter by status (-1 for all). Checks latest version status.
     * @param int $offset Pagination offset.
     * @param int $limit Maximum results.
     * @return array Array of package records.
     */
    public static function list_packages(string $search = '', int $status = -1,
            int $offset = 0, int $limit = 50): array {
        global $DB;

        [$where, $params] = self::build_package_filter($search, $status);

        $sql = "SELECT p.* FROM {cmi5_packages} p WHERE {$where} ORDER BY p.timemodified DESC";
        return array_values($DB->get_records_sql($sql, $params, $offset, $limit));
    }

    /**
     * List packages along with the metadata the picker grid needs.
     *
     * This resolves the latest version, its AU count and the package's total usage
     * in a single query, so callers rendering a list of cards do not need to call
     * get_package_details() once per package.
     *
     * @param string $search Search string to filter by title or description.
     * @param int $status Filter by status (-1 for all). Checks latest version status.
     * @param int $offset Pagination offset.
     * @param int $limit Maximum results.
     * @param string $sort One of 'recent', 'title' or 'usage'.
     * @param int $source Filter by source type (-1 for all).
     * @return array Array of package records with versionid, versionnumber, source, status,
     *               versiontimecreated, createdby, versioncount, aucount and usagecount populated.
     */
    public static function list_packages_with_meta(string $search = '', int $status = -1,
            int $offset = 0, int $limit = 50, string $sort = self::SORT_RECENT,
            int $source = -1): array {
        global $DB;

        [$where, $params] = self::build_package_filter($search, $status, $source);

        $sql = "SELECT p.id, p.title, p.description, p.timecreated, p.timemodified, p.latestversion,
                       v.id AS versionid, v.versionnumber, v.source, v.status,
                       v.timecreated AS versiontimecreated, v.createdby,
                       (SELECT COUNT(1) FROM {cmi5_package_versions} av WHERE av.packageid = p.id) AS versioncount,
                       (SELECT COUNT(1) FROM {cmi5_package_aus} a WHERE a.versionid = v.id) AS aucount,
                       (SELECT COUNT(1)
                          FROM {cmi5} ci
                     LEFT JOIN {cmi5_package_versions} linkedversion
                            ON linkedversion.id = ci.packageversionid
                         WHERE ci.packageid = p.id OR linkedversion.packageid = p.id) AS usagecount
                  FROM {cmi5_packages} p
             LEFT JOIN {cmi5_package_versions} v ON v.id = p.latestversion
                 WHERE {$where}
              ORDER BY " . self::sort_clause($sort);

        return array_values($DB->get_records_sql($sql, $params, $offset, $limit));
    }

    /**
     * Map a sort key to an ORDER BY clause.
     *
     * @param string $sort One of 'recent', 'title' or 'usage'.
     * @return string The ORDER BY clause body.
     */
    private static function sort_clause(string $sort): string {
        switch ($sort) {
            case self::SORT_TITLE:
                return 'p.title ASC';
            case self::SORT_USAGE:
                return 'usagecount DESC, p.title ASC';
            case self::SORT_RECENT:
            default:
                return 'p.timemodified DESC';
        }
    }

    /**
     * Build the shared WHERE clause used by the package listing and counting queries.
     *
     * @param string $search Search string matched against title and description.
     * @param int $status Filter by status (-1 for all).
     * @param int $source Filter by source type (-1 for all).
     * @return array [string $where, array $params]
     */
    private static function build_package_filter(string $search, int $status, int $source = -1): array {
        global $DB;

        $conditions = [];
        $params = [];

        if ($status >= 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM {cmi5_package_versions} v WHERE v.id = p.latestversion AND v.status = :status)';
            $params['status'] = $status;
        }

        if ($source >= 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM {cmi5_package_versions} vs WHERE vs.id = p.latestversion AND vs.source = :source)';
            $params['source'] = $source;
        }

        if (!empty($search)) {
            $titlelike = $DB->sql_like('p.title', ':searchtitle', false);
            $desclike = $DB->sql_like('p.description', ':searchdesc', false);
            $conditions[] = "({$titlelike} OR {$desclike})";
            $escaped = '%' . $DB->sql_like_escape($search) . '%';
            $params['searchtitle'] = $escaped;
            $params['searchdesc'] = $escaped;
        }

        $where = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

        return [$where, $params];
    }

    /**
     * Get the total count of packages matching the filter.
     *
     * @param string $search Search string matched against title and description.
     * @param int $status Filter by status (-1 for all).
     * @param int $source Filter by source type (-1 for all).
     * @return int Total count.
     */
    public static function count_packages(string $search = '', int $status = -1, int $source = -1): int {
        global $DB;

        [$where, $params] = self::build_package_filter($search, $status, $source);

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

        $DB->get_record('cmi5_packages', ['id' => $packageid], 'id', MUST_EXIST);

        // Count live activity references rather than summing the denormalised usagecount
        // column. That counter is only a cache and can drift from the real dependencies,
        // which would let a package still in use be deleted without warning.
        $totalusage = self::count_package_usage($packageid);

        if ($totalusage > 0 && !$force) {
            throw new \moodle_exception('library:packageinuse', 'mod_cmi5', '', $totalusage);
        }

        $versionids = $DB->get_fieldset_select('cmi5_package_versions', 'id',
            'packageid = :pkgid', ['pkgid' => $packageid]);

        // If forcing deletion, unlink every activity that references this package, either
        // directly through packageid or indirectly through one of its versions. In each
        // pair the column used by the WHERE clause is cleared last, otherwise the second
        // update would match nothing.
        if ($force && $totalusage > 0) {
            if (!empty($versionids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($versionids, SQL_PARAMS_NAMED, 'ver');
                $DB->set_field_select('cmi5', 'packageid', null, "packageversionid {$insql}", $inparams);
                $DB->set_field_select('cmi5', 'packageversionid', null, "packageversionid {$insql}", $inparams);
            }
            $DB->set_field('cmi5', 'packageversionid', null, ['packageid' => $packageid]);
            $DB->set_field('cmi5', 'packageid', null, ['packageid' => $packageid]);
        }

        // Delete all versions and their content.
        $syscontext = \context_system::instance();
        $fs = get_file_storage();

        foreach ($versionids as $versionid) {
            $fs->delete_area_files($syscontext->id, 'mod_cmi5', 'library_package', $versionid);
            $fs->delete_area_files($syscontext->id, 'mod_cmi5', 'library_content', $versionid);
            $DB->delete_records('cmi5_package_aus', ['versionid' => $versionid]);
            $DB->delete_records('cmi5_package_blocks', ['versionid' => $versionid]);
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

        // Build existing AU IRI → DB id map so we can update in place, keeping IDs stable
        // for cmi5_au_status and cmi5_sessions references.
        $existingaumap = [];
        foreach ($DB->get_records('cmi5_aus', ['cmi5id' => $cmi5id]) as $rec) {
            $existingaumap[trim($rec->auid)] = (int) $rec->id;
        }

        // Blocks have no learner-data FKs at this layer, so delete and re-insert them cleanly.
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

        // Upsert AUs: update in place if IRI already exists (preserves DB id and learner data),
        // insert new otherwise. Un-retire AUs that have returned in the new version.
        $processedirls = [];
        foreach ($pkgaus as $pkgau) {
            $iri = trim($pkgau->auid);
            $processedirls[] = $iri;

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
            $record->retired = 0;

            if (isset($existingaumap[$iri])) {
                $record->id = $existingaumap[$iri];
                $DB->update_record('cmi5_aus', $record);
            } else {
                $DB->insert_record('cmi5_aus', $record);
            }
        }

        // Retire any existing AUs not present in the new version so they are hidden from
        // learners but their progress history (cmi5_au_status, cmi5_sessions) is preserved.
        foreach ($existingaumap as $iri => $dbid) {
            if (!in_array($iri, $processedirls, true)) {
                $DB->set_field('cmi5_aus', 'retired', 1, ['id' => $dbid]);
            }
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
     * Return the FROM/WHERE fragment shared by every upgrade-candidate query.
     *
     * An activity is upgradeable only when its package and version references are whole and
     * agree with each other: the version it names must belong to the package it names, the
     * package must have a latest version of its own, and that version must be active and
     * numbered higher. Anything less is a repair case, not an upgrade.
     *
     * @param array $filters Optional 'packageid', 'courseid' and 'search' keys.
     * @return array The SQL body after SELECT and the parameters it needs.
     */
    private static function upgrade_candidate_parts(array $filters): array {
        global $DB;

        $params = [
            'moduleid' => (int) $DB->get_field('modules', 'id', ['name' => 'cmi5']),
            'activestatus' => self::STATUS_ACTIVE,
        ];
        $conditions = [
            'latest.versionnumber > cur.versionnumber',
            'latest.status = :activestatus',
        ];

        if (!empty($filters['packageid'])) {
            $conditions[] = 'p.id = :filterpackageid';
            $params['filterpackageid'] = (int) $filters['packageid'];
        }

        if (!empty($filters['courseid'])) {
            $conditions[] = 'c.course = :filtercourseid';
            $params['filtercourseid'] = (int) $filters['courseid'];
        }

        // One box searches all three names a user might remember the row by.
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $DB->sql_like_escape($search) . '%';
            $parts = [
                $DB->sql_like('p.title', ':searchpackage', false),
                $DB->sql_like('c.name', ':searchactivity', false),
                $DB->sql_like('cr.fullname', ':searchcourse', false),
            ];
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
            $params['searchpackage'] = $like;
            $params['searchactivity'] = $like;
            $params['searchcourse'] = $like;
        }

        $body = "FROM {cmi5} c
                  JOIN {cmi5_package_versions} cur
                    ON cur.id = c.packageversionid AND cur.packageid = c.packageid
                  JOIN {cmi5_packages} p ON p.id = c.packageid
                  JOIN {cmi5_package_versions} latest
                    ON latest.id = p.latestversion AND latest.packageid = p.id
             LEFT JOIN {course} cr ON cr.id = c.course
             LEFT JOIN {course_modules} cm
                    ON cm.course = c.course AND cm.instance = c.id AND cm.module = :moduleid
                 WHERE " . implode(' AND ', $conditions);

        return [$body, $params];
    }

    /**
     * List activities that can move to a newer version of the library package they use.
     *
     * This is the single source the library counts, the upgrades list and the execution
     * preflight all read from, so the screens cannot disagree about what is upgradeable.
     * Authorization is not applied here; callers filter by module context.
     *
     * @param array $filters Optional 'packageid', 'courseid' and 'search' keys.
     * @return array Candidate rows ordered by package, then current version, then course.
     */
    public static function get_upgrade_candidates(array $filters = []): array {
        global $DB;

        [$body, $params] = self::upgrade_candidate_parts($filters);

        $sql = "SELECT c.id AS cmi5id, c.name AS activityname, c.course AS courseid,
                       cr.fullname AS coursename, cr.visible AS coursevisible,
                       cm.id AS cmid, cm.visible AS activityvisible, cm.deletioninprogress,
                       p.id AS packageid, p.title AS packagetitle,
                       cur.id AS currentversionid, cur.versionnumber AS currentversionnumber,
                       latest.id AS latestversionid, latest.versionnumber AS latestversionnumber
                  {$body}
              ORDER BY p.title ASC, cur.versionnumber ASC, cr.fullname ASC, c.name ASC, c.id ASC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Count activities that can move to a newer version.
     *
     * @param array $filters Optional 'packageid', 'courseid' and 'search' keys.
     * @return int Number of upgradeable activities.
     */
    public static function count_upgrade_candidates(array $filters = []): int {
        global $DB;

        [$body, $params] = self::upgrade_candidate_parts($filters);

        return (int) $DB->count_records_sql("SELECT COUNT(1) {$body}", $params);
    }

    /**
     * Count upgradeable activities per library package.
     *
     * Derived from live references rather than the cached usage counter, so a package's
     * badge and the rows behind it always describe the same set.
     *
     * @param array $filters Optional 'courseid' and 'search' keys.
     * @return array Package ID to number of upgradeable activities, for packages with at least one.
     */
    public static function get_package_upgrade_counts(array $filters = []): array {
        global $DB;

        [$body, $params] = self::upgrade_candidate_parts($filters);

        $sql = "SELECT p.id AS packageid, COUNT(1) AS upgradecount {$body} GROUP BY p.id";

        $counts = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $counts[(int) $record->packageid] = (int) $record->upgradecount;
        }

        return $counts;
    }

    /**
     * List activities that name a library package but no usable version of it.
     *
     * These cannot be upgraded: without a baseline version there is nothing to upgrade
     * from, and resolving them through the package's latest version would silently invent
     * one. They are reported separately so they are fixed rather than overlooked.
     *
     * @param array $filters Optional 'packageid', 'courseid' and 'search' keys.
     * @return array Rows describing each activity needing repair.
     */
    public static function get_repair_candidates(array $filters = []): array {
        global $DB;

        $params = [
            'moduleid' => (int) $DB->get_field('modules', 'id', ['name' => 'cmi5']),
        ];
        $conditions = [
            'c.packageid > 0',
            '(c.packageversionid IS NULL OR c.packageversionid = 0 OR cur.id IS NULL)',
        ];

        if (!empty($filters['packageid'])) {
            $conditions[] = 'p.id = :filterpackageid';
            $params['filterpackageid'] = (int) $filters['packageid'];
        }

        if (!empty($filters['courseid'])) {
            $conditions[] = 'c.course = :filtercourseid';
            $params['filtercourseid'] = (int) $filters['courseid'];
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $DB->sql_like_escape($search) . '%';
            $parts = [
                $DB->sql_like('p.title', ':searchpackage', false),
                $DB->sql_like('c.name', ':searchactivity', false),
                $DB->sql_like('cr.fullname', ':searchcourse', false),
            ];
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
            $params['searchpackage'] = $like;
            $params['searchactivity'] = $like;
            $params['searchcourse'] = $like;
        }

        $sql = "SELECT c.id AS cmi5id, c.name AS activityname, c.course AS courseid,
                       cr.fullname AS coursename, cm.id AS cmid, cm.deletioninprogress,
                       p.id AS packageid, p.title AS packagetitle
                  FROM {cmi5} c
                  JOIN {cmi5_packages} p ON p.id = c.packageid
             LEFT JOIN {cmi5_package_versions} cur
                    ON cur.id = c.packageversionid AND cur.packageid = c.packageid
             LEFT JOIN {course} cr ON cr.id = c.course
             LEFT JOIN {course_modules} cm
                    ON cm.course = c.course AND cm.instance = c.id AND cm.module = :moduleid
                 WHERE " . implode(' AND ', $conditions) . "
              ORDER BY p.title ASC, cr.fullname ASC, c.name ASC, c.id ASC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * List the versions an activity on a given version number may be moved to.
     *
     * Only later versions of the same package qualify. A downgrade is not an upgrade, and
     * a version of another package is a different course entirely.
     *
     * @param int $packageid Package ID.
     * @param int $currentnumber The version number the activity is on.
     * @return array Version records ordered newest first.
     */
    public static function get_valid_targets(int $packageid, int $currentnumber): array {
        global $DB;

        $params = [
            'packageid' => $packageid,
            'currentnumber' => $currentnumber,
            'activestatus' => self::STATUS_ACTIVE,
        ];
        $where = 'packageid = :packageid AND versionnumber > :currentnumber AND status = :activestatus';

        return array_values($DB->get_records_select('cmi5_package_versions', $where, $params,
            'versionnumber DESC'));
    }

    /**
     * Summarise everything that changed between two version numbers of a package.
     *
     * The range is exclusive of the version the activity is on and inclusive of the target,
     * so skipping intermediate versions still shows what they contained. Absent or invalid
     * changelog JSON is reported as unavailable rather than as "no changes", and never
     * blocks an otherwise valid upgrade.
     *
     * @param int $packageid Package ID.
     * @param int $fromnumber The version number the activity is on, exclusive.
     * @param int $tonumber The target version number, inclusive.
     * @return \stdClass Entries, per-kind counts, the versions covered and an availability flag.
     */
    public static function get_cumulative_changelog(int $packageid, int $fromnumber, int $tonumber): \stdClass {
        global $DB;

        $result = (object) [
            'entries' => [],
            'versions' => [],
            'added' => 0,
            'changed' => 0,
            'removed' => 0,
            'available' => false,
        ];

        if ($tonumber <= $fromnumber) {
            return $result;
        }

        $versions = $DB->get_records_select(
            'cmi5_package_versions',
            'packageid = :packageid AND versionnumber > :fromnumber AND versionnumber <= :tonumber',
            ['packageid' => $packageid, 'fromnumber' => $fromnumber, 'tonumber' => $tonumber],
            'versionnumber ASC'
        );

        // Counted by IRI across the whole range: a unit touched in two versions is one
        // changed unit, and a unit added then removed nets out to neither.
        $added = [];
        $changed = [];
        $removed = [];

        foreach ($versions as $version) {
            $decoded = json_decode((string) $version->changelog, true);
            $hasentries = is_array($decoded) && !empty($decoded);
            $result->versions[] = (object) [
                'version' => $version,
                'entries' => $hasentries ? $decoded : [],
                'available' => $hasentries,
            ];

            if (!$hasentries) {
                continue;
            }

            $result->available = true;
            foreach ($decoded as $entry) {
                if (!is_array($entry) || empty($entry['type'])) {
                    continue;
                }
                $result->entries[] = $entry;
                $auid = (string) ($entry['auid'] ?? '');
                if ($auid === '') {
                    continue;
                }
                switch ($entry['type']) {
                    case 'au_added':
                        $added[$auid] = true;
                        unset($removed[$auid]);
                        break;
                    case 'au_changed':
                        if (!isset($added[$auid])) {
                            $changed[$auid] = true;
                        }
                        break;
                    case 'au_removed':
                        $removed[$auid] = true;
                        unset($added[$auid], $changed[$auid]);
                        break;
                }
            }
        }

        $result->added = count($added);
        $result->changed = count($changed);
        $result->removed = count($removed);

        return $result;
    }

    /**
     * Turn one changelog entry into a line a person can read.
     *
     * Titles come from the uploaded package, so everything interpolated here is escaped: a
     * package is content, not a trusted source of markup.
     *
     * @param array $change One decoded changelog entry.
     * @return string The description, or an empty string for an entry of an unknown shape.
     */
    public static function describe_change(array $change): string {
        $title = s((string) ($change['title'] ?? ''));

        switch ($change['type'] ?? '') {
            case 'au_added':
                return get_string('upgrade:change_auadded', 'cmi5', $title);
            case 'au_removed':
                return get_string('upgrade:change_auremoved', 'cmi5', $title);
            case 'au_changed':
                return get_string('upgrade:change_auchanged', 'cmi5', (object) [
                    'title' => $title,
                    'field' => s((string) ($change['field'] ?? '')),
                ]);
            case 'block_added':
                return get_string('upgrade:change_blockadded', 'cmi5', $title);
            case 'block_removed':
                return get_string('upgrade:change_blockremoved', 'cmi5', $title);
            default:
                return '';
        }
    }

    /**
     * Acquire the exclusive lock for changing which version an activity uses.
     *
     * Every path that reassigns an activity takes this before the version lock, so two
     * users cannot overwrite each other's choice or double-count a version's usage.
     * Callers must always release it.
     *
     * @param int $cmi5id Activity instance ID.
     * @return \core\lock\lock Acquired lock.
     * @throws \moodle_exception If the lock cannot be acquired promptly.
     */
    public static function acquire_activity_lock(int $cmi5id): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::ACTIVITY_LOCK_FACTORY);
        $lock = $factory->get_lock('activity-' . $cmi5id, 10);
        if (!$lock) {
            throw new \moodle_exception('upgrade:reason_locked', 'cmi5');
        }
        return $lock;
    }

    /**
     * Move one activity to a newer version of its library package.
     *
     * Everything the browser sent is resolved and rechecked here rather than trusted: the
     * activity is reauthorized, the version it is actually on is compared against the one
     * the review step was built from, and the target is revalidated against the same rules
     * that listed it. Each call is independent and atomic, so a caller working through a
     * batch can continue past a row that fails.
     *
     * @param int $cmi5id Activity instance ID.
     * @param int $expectedversionid The version the activity was on when it was selected.
     * @param int $targetversionid The version to move to.
     * @param string $batchid Correlation ID when this is part of a batch.
     * @return \stdClass Outcome with 'status', 'reason', version numbers and display names.
     */
    public static function upgrade_activity(int $cmi5id, int $expectedversionid, int $targetversionid,
            string $batchid = ''): \stdClass {
        global $DB;

        $outcome = (object) [
            'cmi5id' => $cmi5id,
            'status' => self::UPGRADE_FAILED,
            'reason' => 'upgrade:reason_failed',
            'activityname' => '',
            'coursename' => '',
            'cmid' => 0,
            'fromnumber' => 0,
            'tonumber' => 0,
        ];

        $cmi5 = $DB->get_record('cmi5', ['id' => $cmi5id]);
        if (!$cmi5) {
            $outcome->reason = 'upgrade:reason_deleted';
            return $outcome;
        }
        $outcome->activityname = $cmi5->name;

        $cm = get_coursemodule_from_instance('cmi5', $cmi5id, 0, false, IGNORE_MISSING);
        if (!$cm || !empty($cm->deletioninprogress)) {
            $outcome->reason = 'upgrade:reason_deleted';
            return $outcome;
        }
        $outcome->cmid = (int) $cm->id;
        $outcome->coursename = (string) $DB->get_field('course', 'fullname', ['id' => $cmi5->course]);

        // Rechecked here and not only at display time: permission can be withdrawn between
        // the review screen loading and this request arriving.
        $modulecontext = \context_module::instance((int) $cm->id, IGNORE_MISSING);
        if (!$modulecontext || !has_capability('mod/cmi5:managecontent', $modulecontext)) {
            $outcome->reason = 'upgrade:reason_nopermission';
            return $outcome;
        }

        try {
            $lock = self::acquire_activity_lock($cmi5id);
        } catch (\moodle_exception $e) {
            $outcome->reason = 'upgrade:reason_locked';
            return $outcome;
        }

        try {
            // Re-read under the lock: the row may have moved while this request queued.
            $cmi5 = $DB->get_record('cmi5', ['id' => $cmi5id], '*', MUST_EXIST);
            $currentversionid = (int) ($cmi5->packageversionid ?? 0);
            $packageid = (int) ($cmi5->packageid ?? 0);

            if (!$packageid || !$currentversionid) {
                $outcome->reason = 'upgrade:reason_needsrepair';
                return $outcome;
            }

            $current = $DB->get_record('cmi5_package_versions', [
                'id' => $currentversionid,
                'packageid' => $packageid,
            ]);
            if (!$current) {
                $outcome->reason = 'upgrade:reason_needsrepair';
                return $outcome;
            }
            $outcome->fromnumber = (int) $current->versionnumber;

            $target = $DB->get_record('cmi5_package_versions', [
                'id' => $targetversionid,
                'packageid' => $packageid,
            ]);
            if (!$target || (int) $target->status !== self::STATUS_ACTIVE) {
                $outcome->reason = 'upgrade:reason_invalidtarget';
                return $outcome;
            }
            $outcome->tonumber = (int) $target->versionnumber;

            // Already there, or past it: a repeat submission is safe, not an error.
            if ((int) $current->versionnumber >= (int) $target->versionnumber) {
                $outcome->status = self::UPGRADE_SKIPPED;
                $outcome->reason = 'upgrade:reason_already';
                return $outcome;
            }

            // The review step was built from a version this activity is no longer on, so
            // the change the user approved is not the change that would be applied.
            if ($expectedversionid > 0 && $expectedversionid !== $currentversionid) {
                $outcome->status = self::UPGRADE_SKIPPED;
                $outcome->reason = 'upgrade:reason_changed';
                return $outcome;
            }

            // An activity pinned to one unit must land on the same unit in the target.
            // Expanding it to the whole package instead would quietly change what learners get.
            $singleauid = null;
            $selection = self::get_activity_au_selection($cmi5id, $packageid, $currentversionid);
            if ($selection !== '') {
                $sourceauid = (int) explode(':', $selection)[1];
                try {
                    $singleauid = self::map_au_to_version($sourceauid, $targetversionid);
                } catch (\moodle_exception $e) {
                    $outcome->reason = 'upgrade:reason_aumissing';
                    return $outcome;
                }
            }

            $event = \mod_cmi5\event\activity_version_upgraded::create([
                'context' => $modulecontext,
                'objectid' => $cmi5id,
                'other' => [
                    'packageid' => $packageid,
                    'fromversionid' => $currentversionid,
                    'fromversionnumber' => (int) $current->versionnumber,
                    'toversionid' => $targetversionid,
                    'toversionnumber' => (int) $target->versionnumber,
                    'bulk' => $batchid !== '',
                    'batchid' => $batchid,
                ],
            ]);
            self::sync_activity_to_version_locked(
                $cmi5id, $targetversionid, $singleauid, $currentversionid, $event);

            $outcome->status = self::UPGRADE_UPGRADED;
            $outcome->reason = '';
            return $outcome;
        } catch (\Throwable $e) {
            // Reported by its reason, never by its exception text: the message can name
            // database internals the user has no business seeing.
            $outcome->status = self::UPGRADE_FAILED;
            $outcome->reason = 'upgrade:reason_failed';
            return $outcome;
        } finally {
            $lock->release();
        }
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
     * @param int $expectedversionid The version the caller believes the activity is on; 0 skips the check.
     * @return \stdClass Result with 'success', 'newversionid', 'changelog'.
     */
    public static function sync_activity_to_version(int $cmi5id, int $newversionid = 0,
            ?int $singleauid = null, int $expectedversionid = 0): \stdClass {
        $lock = self::acquire_activity_lock($cmi5id);
        try {
            return self::sync_activity_to_version_locked($cmi5id, $newversionid, $singleauid, $expectedversionid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Perform the version change for a caller that already holds the activity lock.
     *
     * Split out so the bulk path can hold one activity lock across its own validation and
     * this mutation, rather than taking and dropping it around each step.
     *
     * @param int $cmi5id The activity instance ID.
     * @param int $newversionid The target version ID (0 = latest).
     * @param int|null $singleauid If set, only sync this specific AU.
     * @param int $expectedversionid The version the caller believes the activity is on; 0 skips the check.
     * @param \core\event\base|null $event Event to trigger atomically with the version change.
     * @return \stdClass Result with 'success', 'newversionid', 'changelog'.
     */
    private static function sync_activity_to_version_locked(int $cmi5id, int $newversionid = 0,
            ?int $singleauid = null, int $expectedversionid = 0, ?\core\event\base $event = null): \stdClass {
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

        $lock = self::acquire_version_lock($newversionid);
        try {
            // Revalidate only after locking, in case deletion completed while waiting.
            $newversion = $DB->get_record('cmi5_package_versions',
                ['id' => $newversionid, 'packageid' => $cmi5->packageid], '*', MUST_EXIST);

            // Re-read under both locks: another request may have moved this activity while
            // this one waited, which would make the change the caller approved the wrong one.
            $cmi5 = $DB->get_record('cmi5', ['id' => $cmi5id], '*', MUST_EXIST);
            if ($expectedversionid > 0 && (int) ($cmi5->packageversionid ?? 0) !== $expectedversionid) {
                throw new \moodle_exception('upgrade:reason_changed', 'cmi5');
            }
            if ($singleauid !== null && !$DB->record_exists('cmi5_package_aus', [
                'id' => $singleauid,
                'versionid' => $newversionid,
            ])) {
                throw new \moodle_exception('picker:invalidau', 'cmi5');
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                // Decrement old version usage.
                if (!empty($cmi5->packageversionid)) {
                    self::decrement_usage((int) $cmi5->packageversionid);
                }

                // Copy structure from new version.
                self::copy_structure_to_activity($newversionid, $cmi5id, $singleauid);

                // Update activity record.
                $DB->set_field('cmi5', 'packageversionid', $newversionid, ['id' => $cmi5id]);
                $DB->set_field('cmi5', 'timemodified', time(), ['id' => $cmi5id]);
                $DB->set_field('cmi5', 'courseid_iri', $newversion->courseid_iri, ['id' => $cmi5id]);

                // Increment new version usage.
                self::increment_usage($newversionid);
                if ($event) {
                    $event->trigger();
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

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
        } finally {
            $lock->release();
        }
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

    /**
     * Flatten a package version's blocks and AUs into ordered rows that carry the cmi5.xml nesting.
     *
     * Blocks and AUs both store parentblockid, so the hierarchy is rebuilt from those links and
     * emitted in document order: a block, then its children (nested blocks and AUs interleaved by
     * sortorder), and finally any AU that sits at the top level of the course. AUs are numbered
     * across the whole tree in the order a learner meets them.
     *
     * Rows referencing a parent that is missing from this version, and rows caught in a parent
     * cycle, are emitted at the top level so nothing is silently dropped.
     *
     * @param array $blocks Block records for one version, as returned by get_package_details().
     * @param array $aus AU records for the same version.
     * @return array List of rows: ['type' => 'block'|'au', 'record' => \stdClass, 'depth' => int]
     *               where block rows also carry 'aucount' (AUs anywhere beneath them) and AU rows
     *               carry 'index' (1-based position in the whole version).
     */
    public static function build_structure_tree(array $blocks, array $aus): array {
        $blocksbyid = [];
        foreach ($blocks as $block) {
            $blocksbyid[(int) $block->id] = $block;
        }

        // A parent link only counts when it names a block of this same version, and when following
        // it upwards actually reaches the top. Anything else is treated as a top-level row.
        $parentof = static function($record) use ($blocksbyid): int {
            $parentid = (int) ($record->parentblockid ?? 0);
            return isset($blocksbyid[$parentid]) ? $parentid : 0;
        };

        $rooted = static function($record) use ($blocksbyid, $parentof): int {
            $parentid = $parentof($record);
            $seen = [];
            $walk = $parentid;
            while ($walk > 0) {
                if (isset($seen[$walk])) {
                    // Cycle: treat the row as top level rather than losing it.
                    return 0;
                }
                $seen[$walk] = true;
                $walk = $parentof($blocksbyid[$walk]);
            }
            return $parentid;
        };

        // Children of each block id, plus the top level under key 0.
        $children = [0 => []];
        foreach ($blocksbyid as $id => $block) {
            $children[$id] = $children[$id] ?? [];
        }
        foreach ($blocks as $block) {
            $children[$rooted($block)][] = ['type' => 'block', 'record' => $block];
        }
        foreach ($aus as $au) {
            $children[$rooted($au)][] = ['type' => 'au', 'record' => $au];
        }

        // Within a parent, blocks and AUs are interleaved by sortorder, blocks first on a tie.
        foreach ($children as $parentid => $list) {
            usort($list, static function($a, $b) {
                $order = (int) ($a['record']->sortorder ?? 0) <=> (int) ($b['record']->sortorder ?? 0);
                if ($order !== 0) {
                    return $order;
                }
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'block' ? -1 : 1;
                }
                return (int) $a['record']->id <=> (int) $b['record']->id;
            });
            $children[$parentid] = $list;
        }

        $rows = [];
        $index = 0;

        $descend = static function(int $parentid, int $depth) use (&$descend, &$rows, &$index, $children): int {
            $aucount = 0;
            foreach ($children[$parentid] ?? [] as $child) {
                if ($child['type'] === 'block') {
                    $position = count($rows);
                    $rows[] = [
                        'type' => 'block',
                        'record' => $child['record'],
                        'depth' => $depth,
                        'aucount' => 0,
                    ];
                    // The count is only known once the whole subtree has been walked.
                    $nested = $descend((int) $child['record']->id, $depth + 1);
                    $rows[$position]['aucount'] = $nested;
                    $aucount += $nested;
                } else {
                    $index++;
                    $aucount++;
                    $rows[] = [
                        'type' => 'au',
                        'record' => $child['record'],
                        'depth' => $depth,
                        'index' => $index,
                    ];
                }
            }
            return $aucount;
        };

        $descend(0, 0);

        return $rows;
    }
}
