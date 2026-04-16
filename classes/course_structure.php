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
 * Course structure helper for cmi5 activity module.
 *
 * Provides static methods for retrieving the block/AU hierarchy
 * from the database.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Retrieves and structures cmi5 course structure data from the database.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_structure {

    /**
     * Get the full block/AU hierarchy for a cmi5 activity.
     *
     * Returns a nested object tree with top-level blocks and AUs as root
     * children. Each block may contain nested blocks and AUs in its
     * children property, ordered by sortorder.
     *
     * @param int $cmi5id The cmi5 activity instance ID.
     * @return array Array of top-level block and AU objects with nested children.
     */
    public static function get_structure(int $cmi5id): array {
        $blocks = self::get_blocks($cmi5id);
        $aus = self::get_aus($cmi5id);

        // Index blocks by their database ID.
        $blocksbyid = [];
        foreach ($blocks as $block) {
            $block->type = 'block';
            $block->children = [];
            $blocksbyid[$block->id] = $block;
        }

        // Add AUs as children of their parent blocks, or as top-level items.
        $toplevel = [];
        foreach ($aus as $au) {
            $au->type = 'au';
            if (!empty($au->parentblockid) && isset($blocksbyid[$au->parentblockid])) {
                $blocksbyid[$au->parentblockid]->children[] = $au;
            } else {
                $toplevel[] = $au;
            }
        }

        // Build the block hierarchy bottom-up. Process blocks in reverse
        // sortorder so that children are attached before parents.
        $reversedblocks = array_reverse($blocks, true);
        foreach ($reversedblocks as $block) {
            if (!empty($block->parentblockid) && isset($blocksbyid[$block->parentblockid])) {
                $blocksbyid[$block->parentblockid]->children[] = $blocksbyid[$block->id];
            } else {
                $toplevel[] = $blocksbyid[$block->id];
            }
        }

        // Sort top-level items by sortorder.
        usort($toplevel, function ($a, $b) {
            return $a->sortorder <=> $b->sortorder;
        });

        // Sort children within each block by sortorder.
        foreach ($blocksbyid as $block) {
            usort($block->children, function ($a, $b) {
                return $a->sortorder <=> $b->sortorder;
            });
        }

        return $toplevel;
    }

    /**
     * Get a flat list of all AUs for a cmi5 activity.
     *
     * @param int $cmi5id The cmi5 activity instance ID.
     * @return array Array of AU record objects ordered by sortorder.
     */
    public static function get_aus(int $cmi5id): array {
        global $DB;

        return array_values($DB->get_records('cmi5_aus', ['cmi5id' => $cmi5id], 'sortorder ASC'));
    }

    /**
     * Get a flat list of all blocks for a cmi5 activity.
     *
     * @param int $cmi5id The cmi5 activity instance ID.
     * @return array Array of block record objects ordered by sortorder.
     */
    public static function get_blocks(int $cmi5id): array {
        global $DB;

        return array_values($DB->get_records('cmi5_blocks', ['cmi5id' => $cmi5id], 'sortorder ASC'));
    }
}
