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
 * Grade manager for cmi5 activity module.
 *
 * Calculates grades based on AU scores and the configured grading
 * method, and pushes them to the Moodle gradebook.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages grade calculation and gradebook integration for cmi5.
 *
 * Applies the configured grading method (highest, average, first, last)
 * to AU score_scaled values and converts the result to the activity's
 * grade scale for submission to the Moodle gradebook.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_manager {

    /** @var int Grade method: highest score. */
    const GRADE_HIGHEST = 0;

    /** @var int Grade method: average of all scores. */
    const GRADE_AVERAGE = 1;

    /** @var int Grade method: first score received. */
    const GRADE_FIRST = 2;

    /** @var int Grade method: last (most recent) score received. */
    const GRADE_LAST = 3;

    /** @var \stdClass The cmi5 activity instance record. */
    private $cmi5;

    /**
     * Constructor.
     *
     * @param \stdClass $cmi5 The cmi5 activity instance record.
     */
    public function __construct(\stdClass $cmi5) {
        $this->cmi5 = $cmi5;
    }

    /**
     * Calculate grades for one or all users.
     *
     * Gets all au_status records for the user's registration, extracts
     * score_scaled values, applies the configured grading method, and
     * scales the result to the activity's maxgrade.
     *
     * @param int $userid A specific user ID, or 0 for all users.
     * @return array|null Array of grade objects keyed by userid, or null if no scores.
     */
    public function get_user_grades(int $userid = 0): ?array {
        global $DB;

        // Build conditions for fetching registrations.
        $params = ['cmi5id' => $this->cmi5->id];
        $usercondition = '';
        if ($userid) {
            $usercondition = ' AND userid = :userid';
            $params['userid'] = $userid;
        }

        $registrations = $DB->get_records_select('cmi5_registrations',
            'cmi5id = :cmi5id' . $usercondition, $params);

        if (empty($registrations)) {
            return null;
        }

        $maxgrade = (float) ($this->cmi5->maxgrade ?? 100);
        $grademethod = (int) ($this->cmi5->grademethod ?? self::GRADE_HIGHEST);
        $grades = [];

        foreach ($registrations as $reg) {
            $austatuses = $DB->get_records('cmi5_au_status',
                ['registrationid' => $reg->id], 'timemodified ASC');

            // Extract non-null score_scaled values.
            $scores = [];
            foreach ($austatuses as $aus) {
                if ($aus->score_scaled !== null) {
                    $scores[] = (float) $aus->score_scaled;
                }
            }

            if (empty($scores)) {
                continue;
            }

            // Apply the grading method.
            $rawscore = $this->apply_grade_method($grademethod, $scores);

            // Scale from 0-1 range to 0-maxgrade.
            $grade = new \stdClass();
            $grade->userid = $reg->userid;
            $grade->rawgrade = $rawscore * $maxgrade;
            $grade->dategraded = time();

            $grades[$reg->userid] = $grade;
        }

        return !empty($grades) ? $grades : null;
    }

    /**
     * Calculate and push a user's grade to the gradebook.
     *
     * Retrieves the user's grades and submits them via the
     * cmi5_grade_item_update function in lib.php.
     *
     * @param int $userid The Moodle user ID.
     * @return void
     */
    public function update_grade(int $userid): void {
        $grades = $this->get_user_grades($userid);

        if ($grades) {
            \cmi5_grade_item_update($this->cmi5, $grades);
        } else {
            // No scores; set null grade.
            $grade = new \stdClass();
            $grade->userid = $userid;
            $grade->rawgrade = null;
            \cmi5_grade_item_update($this->cmi5, [$userid => $grade]);
        }
    }

    /**
     * Apply the configured grading method to an array of scores.
     *
     * @param int $method The grading method constant.
     * @param array $scores Array of float score values (0-1 scale).
     * @return float The calculated score (0-1 scale).
     */
    private function apply_grade_method(int $method, array $scores): float {
        switch ($method) {
            case self::GRADE_HIGHEST:
                return max($scores);

            case self::GRADE_AVERAGE:
                return array_sum($scores) / count($scores);

            case self::GRADE_FIRST:
                return reset($scores);

            case self::GRADE_LAST:
                return end($scores);

            default:
                return max($scores);
        }
    }
}
