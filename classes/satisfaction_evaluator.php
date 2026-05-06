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
 * Satisfaction evaluator for cmi5 activity module.
 *
 * Evaluates moveOn criteria for AUs, blocks, and the course to
 * determine satisfaction status per the cmi5 specification.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Evaluates satisfaction for cmi5 AUs, blocks, and courses.
 *
 * Checks moveOn criteria for each AU, recursively evaluates block
 * satisfaction, determines course satisfaction, and issues Satisfied
 * statements for newly-satisfied items.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class satisfaction_evaluator {

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
     * Evaluate satisfaction for all AUs, blocks, and the course.
     *
     * Checks each AU's moveOn criteria against its current status,
     * recursively evaluates block satisfaction, and determines overall
     * course satisfaction. Issues Satisfied statements for any items
     * that become newly satisfied.
     *
     * @param int $registrationid The database ID of the registration record.
     * @return bool True if course satisfaction changed (became satisfied).
     */
    public function evaluate(int $registrationid): bool {
        global $DB;

        $registration = $DB->get_record('cmi5_registrations', ['id' => $registrationid], '*', MUST_EXIST);
        $aus = $DB->get_records('cmi5_aus', ['cmi5id' => $this->cmi5->id], 'sortorder ASC');
        $blocks = $DB->get_records('cmi5_blocks', ['cmi5id' => $this->cmi5->id], 'sortorder ASC');

        // Evaluate AU satisfaction.
        foreach ($aus as $au) {
            $this->evaluate_au($au, $registration);
        }

        // Evaluate block satisfaction (bottom-up).
        foreach ($blocks as $block) {
            $this->evaluate_block($block, $registration);
        }

        // Evaluate course satisfaction.
        return $this->evaluate_course($registration, $aus, $blocks);
    }

    /**
     * Evaluate satisfaction for a single AU based on its moveOn criteria.
     *
     * Checks the AU's moveOn value against the current au_status record.
     * If the AU becomes newly satisfied, issues a Satisfied statement
     * and updates the au_status record.
     *
     * @param \stdClass $au The AU record from cmi5_aus.
     * @param \stdClass $registration The registration record.
     */
    private function evaluate_au(\stdClass $au, \stdClass $registration): void {
        global $DB;

        $austatus = $DB->get_record('cmi5_au_status', [
            'registrationid' => $registration->id,
            'auid' => $au->id,
        ]);
        
        $moveon = $au->moveoncriteria ?? 'NotApplicable';
        
        if (!$austatus) {
            if ($moveon !== 'NotApplicable') {
                return; // AU hasn't been launched yet, nothing to evaluate.
            }
            // Create record only for NotApplicable AUs — they're satisfied immediately.
            $austatus = new \stdClass();
            $austatus->registrationid = $registration->id;
            $austatus->auid = $au->id;
            $austatus->completed = 0;
            $austatus->passed = 0;
            $austatus->failed = 0;
            $austatus->satisfied = 0;
            $austatus->waived = 0;
            $austatus->score_scaled = null;
            $austatus->timecreated = time();
            $austatus->timemodified = time();
            $austatus->id = $DB->insert_record('cmi5_au_status', $austatus);
        }

        // Already satisfied, nothing to do.
        if ($austatus->satisfied) {
            return;
        }

        
        $issatisfied = $this->check_moveon($moveon, $austatus);

        if ($issatisfied) {
            // Mark as satisfied.
            $DB->update_record('cmi5_au_status', (object) [
                'id' => $austatus->id,
                'satisfied' => 1,
                'timemodified' => time(),
            ]);

            // Issue a Satisfied statement for this AU.
            $this->issue_satisfied_statement($au, $registration, 'au');
        }
    }

    /**
     * Evaluate satisfaction for a block.
     *
     * A block is satisfied when all of its child AUs and child blocks
     * are satisfied. This is evaluated recursively.
     *
     * @param \stdClass $block The block record from cmi5_blocks.
     * @param \stdClass $registration The registration record.
     */
    private function evaluate_block(\stdClass $block, \stdClass $registration): void {
        global $DB;

        // Get or create block status.
        $blockstatus = $DB->get_record('cmi5_block_status', [
            'registrationid' => $registration->id,
            'blockid' => $block->id,
        ]);

        if (!$blockstatus) {
            $blockstatus = new \stdClass();
            $blockstatus->registrationid = $registration->id;
            $blockstatus->blockid = $block->id;
            $blockstatus->satisfied = 0;
            $blockstatus->timecreated = time();
            $blockstatus->timemodified = time();
            $blockstatus->id = $DB->insert_record('cmi5_block_status', $blockstatus);
        }

        // Already satisfied, nothing to do.
        if ($blockstatus->satisfied) {
            return;
        }

        // Check all child AUs.
        $childaus = $DB->get_records('cmi5_aus', [
            'cmi5id' => $this->cmi5->id,
            'parentblockid' => $block->id,
        ]);

        foreach ($childaus as $childau) {
            $austatus = $DB->get_record('cmi5_au_status', [
                'registrationid' => $registration->id,
                'auid' => $childau->id,
            ]);
            if (!$austatus || !$austatus->satisfied) {
                return; // Not all child AUs satisfied.
            }
        }

        // Check all child blocks.
        $childblocks = $DB->get_records('cmi5_blocks', [
            'cmi5id' => $this->cmi5->id,
            'parentblockid' => $block->id,
        ]);

        foreach ($childblocks as $childblock) {
            $childblockstatus = $DB->get_record('cmi5_block_status', [
                'registrationid' => $registration->id,
                'blockid' => $childblock->id,
            ]);
            if (!$childblockstatus || !$childblockstatus->satisfied) {
                return; // Not all child blocks satisfied.
            }
        }

        // All children satisfied; mark this block as satisfied.
        $DB->update_record('cmi5_block_status', (object) [
            'id' => $blockstatus->id,
            'satisfied' => 1,
            'timemodified' => time(),
        ]);

        // Issue a Satisfied statement for this block.
        $this->issue_satisfied_statement($block, $registration, 'block');
    }

    /**
     * Evaluate overall course satisfaction.
     *
     * The course is satisfied when all top-level AUs and blocks
     * (those without a parent block) are satisfied.
     *
     * @param \stdClass $registration The registration record.
     * @param array $aus All AU records for this cmi5 instance.
     * @param array $blocks All block records for this cmi5 instance.
     * @return bool True if course satisfaction changed (became satisfied).
     */
    private function evaluate_course(\stdClass $registration, array $aus, array $blocks): bool {
        global $DB;

        // Already satisfied.
        if ($registration->coursesatisfied) {
            return false;
        }

        // Check top-level AUs (no parent block).
        foreach ($aus as $au) {
            if (empty($au->parentblockid)) {
                $austatus = $DB->get_record('cmi5_au_status', [
                    'registrationid' => $registration->id,
                    'auid' => $au->id,
                ]);
                if (!$austatus || !$austatus->satisfied) {
                    return false;
                }
            }
        }

        // Check top-level blocks (no parent block).
        foreach ($blocks as $block) {
            if (empty($block->parentblockid)) {
                $blockstatus = $DB->get_record('cmi5_block_status', [
                    'registrationid' => $registration->id,
                    'blockid' => $block->id,
                ]);
                if (!$blockstatus || !$blockstatus->satisfied) {
                    return false;
                }
            }
        }

        // Course is satisfied.
        registration::mark_course_satisfied($registration->id);

        return true;
    }

    /**
     * Check if an AU's moveOn criteria is met.
     *
     * @param string $moveon The moveOn value from the AU definition.
     * @param \stdClass $austatus The au_status record.
     * @return bool True if the criteria is met.
     */
    private function check_moveon(string $moveon, \stdClass $austatus): bool {
        switch ($moveon) {
            case 'Completed':
                return (bool) $austatus->completed;

            case 'Passed':
                return (bool) $austatus->passed;

            case 'CompletedAndPassed':
                return (bool) $austatus->completed && (bool) $austatus->passed;

            case 'CompletedOrPassed':
                return (bool) $austatus->completed || (bool) $austatus->passed;

            case 'NotApplicable':
                return true;

            default:
                // Unknown moveOn value; treat as not satisfied.
                return false;
        }
    }

    /**
     * Issue a Satisfied statement for a newly-satisfied AU or block.
     *
     * Builds the statement, stores it locally, and optionally forwards
     * it to the configured LRS.
     *
     * @param \stdClass $auorblock The AU or block record.
     * @param \stdClass $registration The registration record.
     * @param string $type Either 'au' or 'block'.
     */
    private function issue_satisfied_statement(\stdClass $auorblock, \stdClass $registration,
            string $type): void {
        global $DB;

        $statementjson = xapi_statement::build_satisfied_statement(
            $this->cmi5,
            $auorblock,
            $registration->registrationid,
            $registration->userid,
            $type
        );

        $statement = json_decode($statementjson);

        // Find the most recent session for this registration to attach the statement to.
        $sessions = $DB->get_records_select(
            'cmi5_sessions',
            'registrationid = :regid',
            ['regid' => $registration->id],
            'timecreated DESC',
            '*',
            0,
            1
        );
        $latestsession = !empty($sessions) ? reset($sessions) : null;

        // Check LRS mode and send accordingly.
        $lrsmode = (int)$this->cmi5->lrsmode;

        switch ($lrsmode) {
            case 0:
                if ($latestsession) {
                    $this->store_satisfied_locally($statement, $statementjson, $latestsession);
                }
                break;

            case 1:
                if ($latestsession) {
                    $insertid = $this->store_satisfied_locally($statement, $statementjson, $latestsession);
                    $this->forward_satisfied_to_lrs($statementjson, $insertid);
                }
                break;

            case 2:
                $this->forward_satisfied_to_lrs($statementjson);
                break;
        }
    }

    /**
     * Insert a Satisfied statement into the local cmi5_statements table.
     *
     * @param \stdClass $statement Decoded statement object.
     * @param string $statementjson Encoded statement JSON.
     * @param \stdClass $latestsession The session record to attach the statement to.
     * @return int The inserted record ID.
     */
    private function store_satisfied_locally(\stdClass $statement, string $statementjson, \stdClass $latestsession): int {
        global $DB;

        // Stored this way to make query more efficient and not need to parse JSON every time.
        $actorhash = null;
        if (isset($statement->actor->account->homePage, $statement->actor->account->name)) {
            $actorhash = sha1($statement->actor->account->homePage . '|' . $statement->actor->account->name);
        }
        $activityid = isset($statement->object->id)
            ? (\strlen($statement->object->id) > 255 ? substr($statement->object->id, 0, 255) : $statement->object->id)
            : null;

        $record = new \stdClass();
        $record->sessionid = $latestsession->id;
        $record->statementid = $statement->id;
        $record->verb = 'https://w3id.org/xapi/adl/verbs/satisfied';
        $record->statement_json = $statementjson;
        $record->is_cmi5_defined = 1;
        $record->forwarded = 0;
        $record->stored = $statement->stored ?? gmdate('Y-m-d\TH:i:s.000\Z');
        $record->authority_json = isset($statement->authority) ? json_encode($statement->authority, JSON_UNESCAPED_SLASHES) : null;
        $record->voided = 0;
        $record->actor_hash = $actorhash;
        $record->activity_id = $activityid;
        $record->registration = $statement->context->registration ?? null;
        $record->timecreated = time();
        return $DB->insert_record('cmi5_statements', $record);
    }

    /**
     * Forward a Satisfied statement to the external LRS.
     *
     * @param string $statementjson Encoded statement JSON.
     * @param int|null $localid Local cmi5_statements record ID to mark as forwarded, or null for lrsmode 2.
     */
    private function forward_satisfied_to_lrs(string $statementjson, ?int $localid = null): void {
        global $DB;
        if (empty($this->cmi5->lrsendpoint)) {
            return;
        }
        try {
            $lrs = new lrs_client($this->cmi5->lrsendpoint, $this->cmi5->lrskey, $this->cmi5->lrssecret);
            $lrs->send_statement($statementjson);
            if ($localid !== null) {
                $DB->set_field('cmi5_statements', 'forwarded', 1, ['id' => $localid]);
            }
        } catch (\Exception $e) {
            debugging('LRS forwarding of Satisfied statement failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
