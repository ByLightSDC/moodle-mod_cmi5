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
 * Custom completion rules for cmi5 activity module.
 *
 * Provides a custom completion rule that requires all Assignable Units
 * to be satisfied (course satisfaction) before the activity is complete.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\completion;

defined('MOODLE_INTERNAL') || die();

use core_completion\activity_custom_completion;

/**
 * Custom completion implementation for cmi5 activities.
 *
 * Evaluates whether the current user has satisfied all AUs in the
 * cmi5 course structure by checking the registration's coursesatisfied flag.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {

    /**
     * Check the state of a custom completion rule.
     *
     * For the 'completionau' rule, checks whether the user has a registration
     * record with coursesatisfied = 1 for this activity instance.
     *
     * @param string $rule The name of the custom completion rule.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     * @throws \coding_exception If an unknown rule is requested.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        if ($rule === 'completionau') {
            $cmi5id = $this->cm->instance;
            $userid = $this->userid;

            $registration = $DB->get_record('cmi5_registrations', [
                'cmi5id' => $cmi5id,
                'userid' => $userid,
            ]);

            if ($registration && $registration->coursesatisfied) {
                return COMPLETION_COMPLETE;
            }

            return COMPLETION_INCOMPLETE;
        }

        // Should not reach here due to validate_rule above.
        throw new \coding_exception("Unknown completion rule: {$rule}");
    }

    /**
     * Return the list of custom completion rules defined by this plugin.
     *
     * @return array List of custom rule names.
     */
    public static function get_defined_custom_rules(): array {
        return ['completionau'];
    }

    /**
     * Return human-readable descriptions for each custom completion rule.
     *
     * @return array Associative array of rule name => description string.
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionau' => get_string('completionau', 'cmi5'),
        ];
    }

    /**
     * Return the sort order for completion rules on the activity info display.
     *
     * @return array Ordered list of completion rule names.
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionau',
        ];
    }
}
