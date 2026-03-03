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
 * Scheduled task to clean up expired cmi5 tokens.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task that removes expired authentication tokens.
 *
 * Deletes all rows from the cmi5_tokens table where the expiry
 * timestamp is earlier than the current time.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_expired_tokens extends \core\task\scheduled_task {

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanupexpiredtokens', 'mod_cmi5');
    }

    /**
     * Execute the task.
     *
     * Deletes all tokens where the expiry timestamp has passed.
     */
    public function execute() {
        global $DB;

        $now = time();
        $count = $DB->count_records_select('cmi5_tokens', 'expiry < :now', ['now' => $now]);

        if ($count > 0) {
            $DB->delete_records_select('cmi5_tokens', 'expiry < :now', ['now' => $now]);
            mtrace("Deleted {$count} expired cmi5 token(s).");
        }
    }
}
