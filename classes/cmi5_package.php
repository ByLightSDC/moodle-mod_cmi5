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
 * Package parser for cmi5 activity module.
 *
 * Handles extraction and parsing of uploaded cmi5 ZIP packages,
 * including cmi5.xml course structure parsing and content file storage.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Processes uploaded cmi5 packages.
 *
 * Extracts the ZIP, parses cmi5.xml for course structure (blocks and AUs),
 * saves the structure to the database, and stores content files in the Moodle file API.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cmi5_package {

    /** @var \context_module The module context. */
    protected $context;

    /** @var int The cmi5 activity instance ID. */
    protected $cmi5id;

    /**
     * Constructor.
     *
     * @param \context_module $context The module context.
     * @param int $cmi5id The cmi5 activity instance ID.
     */
    public function __construct(\context_module $context, int $cmi5id) {
        $this->context = $context;
        $this->cmi5id = $cmi5id;
    }

    /**
     * Process an uploaded cmi5 ZIP package.
     *
     * Retrieves the uploaded ZIP from the Moodle file API, extracts it to a
     * temporary directory, locates and parses cmi5.xml, saves the course structure
     * to the database, extracts content files to the file API, and updates the
     * cmi5 record with the course ID IRI.
     *
     * @return \stdClass The parsed course structure data.
     * @throws \moodle_exception If the package or cmi5.xml cannot be found or parsed.
     */
    public function process_uploaded_package(): \stdClass {
        global $DB;

        $fs = get_file_storage();

        // Get the uploaded package file.
        $files = $fs->get_area_files(
            $this->context->id,
            'mod_cmi5',
            'package',
            0,
            'sortorder, id',
            false
        );

        if (empty($files)) {
            throw new \moodle_exception('packagenotfound', 'mod_cmi5');
        }

        $packagefile = reset($files);

        // Extract to a temp directory.
        $tempdir = make_request_directory();
        $packer = get_file_packer('application/zip');
        $packagefile->extract_to_pathname($packer, $tempdir);

        // Locate cmi5.xml.
        $cmi5xmlpath = $tempdir . '/cmi5.xml';
        if (!file_exists($cmi5xmlpath)) {
            throw new \moodle_exception('cmi5xmlnotfound', 'mod_cmi5');
        }

        $xmlcontent = file_get_contents($cmi5xmlpath);
        if ($xmlcontent === false) {
            throw new \moodle_exception('cmi5xmlreaderror', 'mod_cmi5');
        }

        // Parse the cmi5.xml course structure.
        $structure = $this->parse_cmi5_xml($xmlcontent);

        // Save course structure to the database.
        $this->save_course_structure($structure);

        // Extract content files to Moodle file API.
        $this->extract_content_files($tempdir);

        // Update the cmi5 record with the course ID IRI.
        $DB->set_field('cmi5', 'courseid_iri', $structure->courseid, ['id' => $this->cmi5id]);

        return $structure;
    }

    /**
     * Parse a cmi5.xml string and return the course structure (static version).
     *
     * This static method can be called without instantiating cmi5_package,
     * making it reusable by the content_library class.
     *
     * @param string $xmlcontent The raw XML content of cmi5.xml.
     * @return \stdClass Parsed structure with courseid, coursetitle, blocks, and aus arrays.
     * @throws \moodle_exception If the XML is invalid or missing required elements.
     */
    public static function parse_cmi5_xml_static(string $xmlcontent): \stdClass {
        $instance = new class extends cmi5_package {
            public function __construct() {
                // No-op: we only need the parsing helpers.
            }
        };
        return $instance->parse_cmi5_xml($xmlcontent);
    }

    /**
     * Parse a cmi5.xml file and extract the course structure.
     *
     * The cmi5.xml has a <courseStructure> root element containing a <course>
     * element (with an id attribute) and nested <block> and <au> elements.
     *
     * @param string $xmlcontent The raw XML content of cmi5.xml.
     * @return \stdClass Parsed structure with courseid, coursetitle, blocks, and aus arrays.
     * @throws \moodle_exception If the XML is invalid or missing required elements.
     */
    public function parse_cmi5_xml(string $xmlcontent): \stdClass {
        // Strip namespace declarations so SimpleXML works with plain element names.
        // cmi5.xml files may use a default namespace which makes ->element access fail.
        $xmlcontent = preg_replace('/\s+xmlns\s*=\s*"[^"]*"/', '', $xmlcontent);

        $xml = simplexml_load_string($xmlcontent);
        if ($xml === false) {
            throw new \moodle_exception('invalidcmi5xml', 'mod_cmi5');
        }

        $structure = new \stdClass();
        $structure->blocks = [];
        $structure->aus = [];

        // Extract course element.
        $course = $xml->course ?? null;
        if ($course === null) {
            throw new \moodle_exception('missingcourseelement', 'mod_cmi5');
        }

        $structure->courseid = (string) $course['id'];
        $structure->coursetitle = $this->get_langstring($course->title);
        $structure->coursedescription = $this->get_langstring($course->description);

        // In cmi5.xml, blocks and AUs are children of <courseStructure> (root),
        // NOT children of <course>. The <course> element only contains metadata.
        $sortorder = 0;
        foreach ($xml->children() as $child) {
            $childname = $child->getName();
            if ($childname === 'block') {
                $this->parse_block($child, null, $structure, $sortorder);
            } else if ($childname === 'au') {
                $this->parse_au($child, null, $structure, $sortorder);
            }
        }

        return $structure;
    }

    /**
     * Parse a block element and its children recursively.
     *
     * @param \SimpleXMLElement $blockelement The block XML element.
     * @param string|null $parentblockid The ID attribute of the parent block, or null for top-level.
     * @param \stdClass $structure The structure object being built.
     * @param int $sortorder The current sort order counter (passed by reference).
     */
    protected function parse_block(\SimpleXMLElement $blockelement, ?string $parentblockid,
            \stdClass $structure, int &$sortorder): void {
        $block = new \stdClass();
        $block->blockid = (string) $blockelement['id'];
        $block->title = $this->get_langstring($blockelement->title);
        $block->description = $this->get_langstring($blockelement->description);
        $block->parentblockid = $parentblockid;
        $block->sortorder = $sortorder++;
        $block->children = [];

        $structure->blocks[] = $block;

        // Parse child elements.
        foreach ($blockelement->children() as $child) {
            $childname = $child->getName();
            if ($childname === 'block') {
                $this->parse_block($child, $block->blockid, $structure, $sortorder);
            } else if ($childname === 'au') {
                $this->parse_au($child, $block->blockid, $structure, $sortorder);
            }
        }
    }

    /**
     * Parse an AU (Assignable Unit) element.
     *
     * @param \SimpleXMLElement $auelement The AU XML element.
     * @param string|null $parentblockid The ID attribute of the parent block, or null for top-level.
     * @param \stdClass $structure The structure object being built.
     * @param int $sortorder The current sort order counter (passed by reference).
     */
    protected function parse_au(\SimpleXMLElement $auelement, ?string $parentblockid,
            \stdClass $structure, int &$sortorder): void {
        $au = new \stdClass();
        $au->auid = (string) $auelement['id'];
        $au->title = $this->get_langstring($auelement->title);
        $au->description = $this->get_langstring($auelement->description);
        $au->url = (string) $auelement->url;
        $au->launchmethod = isset($auelement['launchMethod'])
            ? (string) $auelement['launchMethod'] : 'AnyWindow';
        $au->moveoncriteria = isset($auelement['moveOn'])
            ? (string) $auelement['moveOn'] : 'NotApplicable';
        $au->masteryscore = isset($auelement['masteryScore'])
            ? (float) $auelement['masteryScore'] : null;
        $au->launchparameters = isset($auelement->launchParameters)
            ? (string) $auelement->launchParameters : null;
        $au->entitlementkey = isset($auelement->entitlementKey)
            ? (string) $auelement->entitlementKey : null;
        $au->parentblockid = $parentblockid;
        $au->sortorder = $sortorder++;

        $structure->aus[] = $au;
    }

    /**
     * Extract the text from a title or description element containing langstring children.
     *
     * @param \SimpleXMLElement|null $element The title or description element.
     * @return string The extracted text, or empty string if not found.
     */
    protected function get_langstring(?\SimpleXMLElement $element): string {
        if ($element === null) {
            return '';
        }
        $langstring = $element->langstring ?? null;
        if ($langstring !== null) {
            return trim((string) $langstring);
        }
        return trim((string) $element);
    }

    /**
     * Save the parsed course structure to the database.
     *
     * Clears any existing blocks and AUs for this activity, then inserts the
     * new structure. Handles nested blocks by resolving parent block IDs from
     * the cmi5.xml block id attributes to database record IDs.
     *
     * @param \stdClass $structure The parsed course structure from parse_cmi5_xml().
     */
    public function save_course_structure(\stdClass $structure): void {
        global $DB;

        // Clear existing records for this activity.
        $DB->delete_records('cmi5_aus', ['cmi5id' => $this->cmi5id]);
        $DB->delete_records('cmi5_blocks', ['cmi5id' => $this->cmi5id]);

        // Map of cmi5.xml block IDs to database record IDs.
        $blockidmap = [];

        // Insert blocks.
        foreach ($structure->blocks as $block) {
            $record = new \stdClass();
            $record->cmi5id = $this->cmi5id;
            $record->blockid = $block->blockid;
            $record->title = $block->title;
            $record->description = $block->description;
            $record->parentblockid = null;
            $record->sortorder = $block->sortorder;

            // Resolve parent block ID if this is a nested block.
            if ($block->parentblockid !== null && isset($blockidmap[$block->parentblockid])) {
                $record->parentblockid = $blockidmap[$block->parentblockid];
            }

            $recordid = $DB->insert_record('cmi5_blocks', $record);
            $blockidmap[$block->blockid] = $recordid;
        }

        // Insert AUs.
        foreach ($structure->aus as $au) {
            $record = new \stdClass();
            $record->cmi5id = $this->cmi5id;
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

            // Resolve parent block ID.
            $record->parentblockid = null;
            if ($au->parentblockid !== null && isset($blockidmap[$au->parentblockid])) {
                $record->parentblockid = $blockidmap[$au->parentblockid];
            }

            $DB->insert_record('cmi5_aus', $record);
        }
    }

    /**
     * Extract content files from the temporary directory into the Moodle file API.
     *
     * Stores all files from the extracted package (except cmi5.xml) under the
     * 'content' file area with the cmi5 instance ID as the item ID.
     *
     * @param string $tempdir Path to the temporary directory containing extracted files.
     */
    private function extract_content_files(string $tempdir): void {
        $fs = get_file_storage();

        // Remove any existing content files.
        $fs->delete_area_files($this->context->id, 'mod_cmi5', 'content', $this->cmi5id);

        // Recursively iterate through all files in the temp directory.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempdir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            // Get the relative path from the temp directory.
            $relativepath = substr($file->getPathname(), strlen($tempdir));
            $relativepath = str_replace('\\', '/', $relativepath);

            // Skip cmi5.xml - it's metadata, not content.
            if (strtolower(ltrim($relativepath, '/')) === 'cmi5.xml') {
                continue;
            }

            if ($file->isDir()) {
                // Ensure filepath starts and ends with /.
                $dirpath = '/' . ltrim($relativepath, '/');
                if (substr($dirpath, -1) !== '/') {
                    $dirpath .= '/';
                }

                $filerecord = [
                    'contextid' => $this->context->id,
                    'component' => 'mod_cmi5',
                    'filearea' => 'content',
                    'itemid' => $this->cmi5id,
                    'filepath' => $dirpath,
                    'filename' => '.',
                ];
                try {
                    $fs->create_file_from_string($filerecord, '');
                } catch (\Exception $e) {
                    // Skip directories that can't be created (duplicate, invalid chars).
                    debugging('cmi5: skipping directory ' . $dirpath . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            } else {
                // Build filepath: must start and end with /.
                $filename = basename($relativepath);
                $dirpart = dirname($relativepath);

                // Normalize the directory path.
                if ($dirpart === '' || $dirpart === '.' || $dirpart === '/') {
                    $filepath = '/';
                } else {
                    $filepath = '/' . ltrim($dirpart, '/');
                    if (substr($filepath, -1) !== '/') {
                        $filepath .= '/';
                    }
                }

                // Sanitize filename: Moodle rejects empty names, names with \0, and
                // names that are just whitespace. Replace problematic chars.
                $filename = clean_param($filename, PARAM_FILE);
                if (empty($filename)) {
                    debugging('cmi5: skipping file with invalid name: ' . $relativepath, DEBUG_DEVELOPER);
                    continue;
                }

                $filerecord = [
                    'contextid' => $this->context->id,
                    'component' => 'mod_cmi5',
                    'filearea' => 'content',
                    'itemid' => $this->cmi5id,
                    'filepath' => $filepath,
                    'filename' => $filename,
                ];
                try {
                    $fs->create_file_from_pathname($filerecord, $file->getPathname());
                } catch (\Exception $e) {
                    debugging('cmi5: skipping file ' . $relativepath . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }
}
