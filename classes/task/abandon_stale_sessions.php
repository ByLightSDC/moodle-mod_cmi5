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
 * Scheduled task to abandon stale cmi5 sessions.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\task;

defined('MOODLE_INTERNAL') || die();

use mod_cmi5\local\session;
use mod_cmi5\local\xapi_statement;

/**
 * Scheduled task that finds and abandons stale cmi5 sessions.
 *
 * For each cmi5 activity instance, this task checks for sessions that have
 * exceeded the configured session timeout. Stale sessions are marked as
 * abandoned, an xAPI abandoned statement is built and stored, and
 * satisfaction is re-evaluated.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class abandon_stale_sessions extends \core\task\scheduled_task {

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskabandonstalesessions', 'mod_cmi5');
    }

    /**
     * Execute the task.
     *
     * Gets all cmi5 instances, determines the session timeout for each,
     * finds stale sessions, marks them abandoned, builds and stores an
     * xAPI abandoned statement, and evaluates satisfaction.
     */
    public function execute() {
        global $DB;

        $instances = $DB->get_records('cmi5');

        foreach ($instances as $instance) {
            $timeout = $instance->sessiontimeout ?? get_config('mod_cmi5', 'sessiontimeout');
            if (empty($timeout)) {
                continue;
            }

            $stalesessions = session::get_stale_sessions($instance->id, $timeout);

            foreach ($stalesessions as $stalesession) {
                mtrace("Abandoning stale session {$stalesession->id} for cmi5 instance {$instance->id}.");

                // Mark the session as abandoned.
                session::mark_abandoned($stalesession);

                // Build and store the abandoned xAPI statement.
                $statement = xapi_statement::build_abandoned_statement($stalesession);
                xapi_statement::store($statement);

                // Re-evaluate satisfaction for this AU/registration.
                session::evaluate_satisfaction($stalesession);
            }
        }
    }
}
