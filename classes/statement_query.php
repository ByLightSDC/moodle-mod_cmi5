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
 * xAPI statement query engine for the mod_cmi5 LRS.
 *
 * Builds SQL queries against denormalized cmi5_statements columns to support
 * xAPI GET /statements filtering and pagination.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5;

defined('MOODLE_INTERNAL') || die();

class statement_query {

    /** @var int Maximum statements per page. */
    private const MAX_LIMIT = 500;

    /** @var int Default statements per page. */
    private const DEFAULT_LIMIT = 100;

    /**
     * Query statements with xAPI filtering and pagination.
     *
     * @param array $params xAPI query parameters (statementId, voidedStatementId, agent, verb,
     *                      activity, registration, since, until, limit, ascending).
     * @param int|null $sessionid Optional session ID to restrict results to one session.
     * @param string $moreurl Base URL for pagination more links.
     * @return array With keys 'statements' (array of decoded objects) and 'more' (string URL or empty).
     */
    public static function query(array $params, ?int $sessionid = null, string $moreurl = ''): array {
        global $DB;

        // Single statement lookup by ID.
        if (!empty($params['statementId'])) {
            return self::get_single($params['statementId'], false);
        }
        if (!empty($params['voidedStatementId'])) {
            return self::get_single($params['voidedStatementId'], true);
        }

        $conditions = [];
        $sqlparams = [];

        // Exclude voided statements from normal queries.
        $conditions[] = 's.voided = :voided';
        $sqlparams['voided'] = 0;

        // Session constraint (for proxy.php).
        if ($sessionid !== null) {
            $conditions[] = 's.sessionid = :sessionid';
            $sqlparams['sessionid'] = $sessionid;
        }

        // Agent filter (by actor_hash).
        if (!empty($params['agent'])) {
            $agent = is_string($params['agent']) ? json_decode($params['agent']) : $params['agent'];
            if ($agent && isset($agent->account->homePage, $agent->account->name)) {
                $hash = sha1($agent->account->homePage . '|' . $agent->account->name);
                $conditions[] = 's.actor_hash = :actor_hash';
                $sqlparams['actor_hash'] = $hash;
            }
        }

        // Verb filter.
        if (!empty($params['verb'])) {
            $conditions[] = 's.verb = :verb';
            $sqlparams['verb'] = $params['verb'];
        }

        // Activity filter.
        if (!empty($params['activity'])) {
            $conditions[] = 's.activity_id = :activity_id';
            $sqlparams['activity_id'] = $params['activity'];
        }

        // Registration filter.
        if (!empty($params['registration'])) {
            $conditions[] = 's.registration = :registration';
            $sqlparams['registration'] = $params['registration'];
        }

        // Since filter (exclusive — stored > since).
        if (!empty($params['since'])) {
            $conditions[] = 's.stored > :since';
            $sqlparams['since'] = $params['since'];
        }

        // Until filter (inclusive — stored <= until).
        if (!empty($params['until'])) {
            $conditions[] = 's.stored <= :until';
            $sqlparams['until'] = $params['until'];
        }

        // Limit and ordering.
        $limit = self::DEFAULT_LIMIT;
        if (isset($params['limit'])) {
            $limit = max(1, min(self::MAX_LIMIT, (int) $params['limit']));
        }

        $ascending = !empty($params['ascending']) && ($params['ascending'] === 'true' || $params['ascending'] === true);
        $order = $ascending ? 'ASC' : 'DESC';

        // Offset for pagination.
        $offset = 0;
        if (!empty($params['_offset'])) {
            $offset = (int) $params['_offset'];
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT s.statement_json FROM {cmi5_statements} s WHERE {$where} ORDER BY s.timecreated {$order}, s.id {$order}";

        // Fetch limit + 1 to detect if there are more results.
        $records = $DB->get_records_sql($sql, $sqlparams, $offset, $limit + 1);

        $statements = [];
        $hasmore = false;
        $count = 0;
        foreach ($records as $record) {
            $count++;
            if ($count > $limit) {
                $hasmore = true;
                break;
            }
            $decoded = json_decode($record->statement_json);
            if ($decoded) {
                $statements[] = $decoded;
            }
        }

        $more = '';
        if ($hasmore && !empty($moreurl)) {
            // Build more URL with encoded continuation params.
            $continuationparams = $params;
            $continuationparams['_offset'] = $offset + $limit;
            $continuationparams['limit'] = $limit;
            if ($ascending) {
                $continuationparams['ascending'] = 'true';
            }
            $token = base64_encode(json_encode($continuationparams));
            $more = $moreurl . '?more=' . urlencode($token);
        }

        return [
            'statements' => $statements,
            'more' => $more,
        ];
    }

    /**
     * Decode a 'more' pagination token back to query params.
     *
     * @param string $token The base64-encoded continuation token.
     * @return array|null The decoded params, or null if invalid.
     */
    public static function decode_more_token(string $token): ?array {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }
        $params = json_decode($decoded, true);
        if (!is_array($params)) {
            return null;
        }
        return $params;
    }

    /**
     * Look up a single statement by its UUID.
     *
     * @param string $statementid The statement UUID.
     * @param bool $voided If true, look for a voided statement.
     * @return array xAPI response with single statement or empty.
     */
    private static function get_single(string $statementid, bool $voided): array {
        global $DB;

        $conditions = ['statementid' => $statementid];
        if ($voided) {
            $conditions['voided'] = 1;
        }

        $record = $DB->get_record('cmi5_statements', $conditions);
        if (!$record) {
            http_response_code(404);
            return [
                'statements' => [],
                'more' => '',
            ];
        }

        $stmt = json_decode($record->statement_json);
        return [
            'statements' => $stmt ? [$stmt] : [],
            'more' => '',
        ];
    }
}
