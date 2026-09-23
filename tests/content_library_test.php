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

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for content library package usage reporting.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_cmi5\content_library
 */
final class content_library_test extends \advanced_testcase {

    /**
     * The original uploaded filename and content are retained for download.
     */
    public function test_get_version_archive_returns_original_zip(): void {
        $this->resetAfterTest();

        [$package, $version] = $this->create_versioned_package();
        $filerecord = [
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_cmi5',
            'filearea' => 'library_package',
            'itemid' => $version->id,
            'filepath' => '/',
            'filename' => 'original-course.zip',
        ];
        get_file_storage()->create_file_from_string($filerecord, 'original archive content');

        $archive = content_library::get_version_archive($package->id, $version->id);

        $this->assertSame('original-course.zip', $archive->get_filename());
        $this->assertSame('original archive content', $archive->get_content());
    }

    /**
     * A version cannot be downloaded through a different package ID.
     */
    public function test_get_version_archive_rejects_package_mismatch(): void {
        $this->resetAfterTest();

        [$package, $version] = $this->create_versioned_package();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('library:invalidpackageversion', 'cmi5'));
        content_library::get_version_archive($package->id + 1, $version->id);
    }

    /**
     * External content explains that it has no original ZIP.
     */
    public function test_get_version_archive_rejects_external_version(): void {
        global $DB;

        $this->resetAfterTest();

        [$package, $version] = $this->create_versioned_package();
        $DB->set_field('cmi5_package_versions', 'source', content_library::SOURCE_API,
            ['id' => $version->id]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('library:noarchiveforversion', 'cmi5'));
        content_library::get_version_archive($package->id, $version->id);
    }

    /**
     * A ZIP version with a missing stored file produces a specific error.
     */
    public function test_get_version_archive_reports_missing_file(): void {
        $this->resetAfterTest();

        [$package, $version] = $this->create_versioned_package();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('library:archivefilemissing', 'cmi5'));
        content_library::get_version_archive($package->id, $version->id);
    }

    /**
     * Actual activity references take precedence over cached usage counts.
     */
    public function test_usage_counts_are_derived_from_activity_references(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Hidden usage course',
            'shortname' => 'hiddenusage',
            'visible' => 0,
        ]);
        [$package, $version1, $version2] = $this->create_versioned_package();

        $this->create_activity($course->id, 'Version one activity', $package->id, $version1->id);
        $this->create_activity($course->id, 'Latest activity', $package->id, $version2->id);
        $this->create_activity($course->id, 'Legacy latest activity', $package->id, null);
        $this->create_activity($course->id, 'Dangling package link', null, $version1->id);

        $this->assertSame(2, content_library::count_version_usage($version1->id));
        $this->assertSame(2, content_library::count_version_usage($version2->id));
        $this->assertSame(4, content_library::count_package_usage($package->id));

        $versions = content_library::get_package_versions($package->id);
        $this->assertSame(2, (int) $versions[0]->usagecount);
        $this->assertSame(2, (int) $versions[1]->usagecount);

        $details = content_library::get_package_details($package->id, $version2->id);
        $this->assertSame(2, (int) $details->usagecount);

        $packages = content_library::list_packages_with_meta('', -1);
        $this->assertCount(1, $packages);
        $this->assertSame(4, (int) $packages[0]->usagecount);

        // Confirm that the deliberately incorrect cache values remain irrelevant.
        $this->assertSame(88, (int) $DB->get_field('cmi5_package_versions', 'usagecount',
            ['id' => $version2->id]));
    }

    /**
     * Legacy package-only references belong to the latest version only.
     */
    public function test_legacy_reference_is_not_attributed_to_an_older_version(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        [$package, $version1, $version2] = $this->create_versioned_package();
        $this->create_activity($course->id, 'Legacy activity', $package->id, null);

        $this->assertSame(0, content_library::count_version_usage($version1->id));
        $this->assertSame(1, content_library::count_version_usage($version2->id));
    }

    /**
     * Usage details include hidden courses and activities without course-module rows.
     */
    public function test_usage_details_include_incomplete_activity_references(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Hidden usage course',
            'shortname' => 'hiddenusage',
            'visible' => 0,
        ]);
        [$package, $version1] = $this->create_versioned_package();
        $activityid = $this->create_activity(
            $course->id, 'Activity without course module', $package->id, $version1->id);

        $usage = content_library::get_version_usage($version1->id);

        $this->assertCount(1, $usage);
        $this->assertSame($activityid, (int) $usage[0]->cmi5id);
        $this->assertSame('Hidden usage course', $usage[0]->coursename);
        $this->assertSame(0, (int) $usage[0]->coursevisible);
        $this->assertNull($usage[0]->cmid);
    }

    /**
     * Structure rows preserve nesting, ordering and package-wide AU numbering.
     */
    public function test_build_structure_tree_preserves_hierarchy_and_counts(): void {
        $rootblock = (object) [
            'id' => 1,
            'parentblockid' => null,
            'sortorder' => 1,
        ];
        $nestedblock = (object) [
            'id' => 2,
            'parentblockid' => 1,
            'sortorder' => 2,
        ];
        $emptyblock = (object) [
            'id' => 3,
            'parentblockid' => null,
            'sortorder' => 3,
        ];
        $rootau = (object) [
            'id' => 10,
            'parentblockid' => 1,
            'sortorder' => 1,
        ];
        $nestedau = (object) [
            'id' => 11,
            'parentblockid' => 2,
            'sortorder' => 1,
        ];
        $toplevelau = (object) [
            'id' => 12,
            'parentblockid' => null,
            'sortorder' => 4,
        ];

        $rows = content_library::build_structure_tree(
            [$rootblock, $nestedblock, $emptyblock],
            [$rootau, $nestedau, $toplevelau]
        );

        $this->assertSame([
            ['block', 1, 0, 2, null],
            ['au', 10, 1, null, 1],
            ['block', 2, 1, 1, null],
            ['au', 11, 2, null, 2],
            ['block', 3, 0, 0, null],
            ['au', 12, 0, null, 3],
        ], array_map(static function(array $row): array {
            return [
                $row['type'],
                (int) $row['record']->id,
                (int) $row['depth'],
                isset($row['aucount']) ? (int) $row['aucount'] : null,
                isset($row['index']) ? (int) $row['index'] : null,
            ];
        }, $rows));
    }

    /**
     * Invalid parent links do not cause structure rows to disappear.
     */
    public function test_build_structure_tree_keeps_orphans_and_cycles(): void {
        $cyclea = (object) ['id' => 1, 'parentblockid' => 2, 'sortorder' => 1];
        $cycleb = (object) ['id' => 2, 'parentblockid' => 1, 'sortorder' => 2];
        $cycleau = (object) ['id' => 10, 'parentblockid' => 1, 'sortorder' => 3];
        $orphanau = (object) ['id' => 11, 'parentblockid' => 99, 'sortorder' => 4];

        $rows = content_library::build_structure_tree([$cyclea, $cycleb], [$cycleau, $orphanau]);

        $this->assertSame([1, 2, 10, 11], array_map(static function(array $row): int {
            return (int) $row['record']->id;
        }, $rows));
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row['depth']);
        }
    }

    /**
     * Create a package containing two versions with intentionally stale counters.
     *
     * @return array Package, first version and second version records.
     */
    private function create_versioned_package(): array {
        global $DB;

        $now = time();
        $package = (object) [
            'title' => 'Usage test package',
            'description' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $package->id = $DB->insert_record('cmi5_packages', $package);

        $version1 = (object) [
            'packageid' => $package->id,
            'versionnumber' => 1,
            'source' => content_library::SOURCE_ZIP,
            'usagecount' => 99,
            'status' => content_library::STATUS_ACTIVE,
            'createdby' => 2,
            'timecreated' => $now,
        ];
        $version1->id = $DB->insert_record('cmi5_package_versions', $version1);

        $version2 = clone $version1;
        unset($version2->id);
        $version2->versionnumber = 2;
        $version2->usagecount = 88;
        $version2->id = $DB->insert_record('cmi5_package_versions', $version2);

        $package->latestversion = $version2->id;
        $DB->update_record('cmi5_packages', $package);

        return [$package, $version1, $version2];
    }

    /**
     * Create a minimal cmi5 activity reference.
     *
     * @param int $courseid Course ID.
     * @param string $name Activity name.
     * @param int|null $packageid Package ID.
     * @param int|null $versionid Package version ID.
     * @return int Activity ID.
     */
    private function create_activity(int $courseid, string $name, ?int $packageid, ?int $versionid): int {
        global $DB;

        return $DB->insert_record('cmi5', (object) [
            'course' => $courseid,
            'name' => $name,
            'packageid' => $packageid,
            'packageversionid' => $versionid,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
