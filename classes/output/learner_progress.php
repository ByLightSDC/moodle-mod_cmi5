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
 * Builds the learner progress summary for the activity view.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Prepares progress template data from the displayed AU rows, without database access.
 */
class learner_progress {

    /** @var string[] Status order in the progress legend. */
    private const STATUS_ORDER = ['satisfied', 'passed', 'completed', 'inprogress', 'failed', 'notstarted'];

    /**
     * Build the summary, preserving AU order when choosing the next unit.
     *
     * @param array $audata AU template rows, including status, title, index and launch action.
     * @return array|null Progress template data, or null when there are no AUs.
     */
    public static function build(array $audata): ?array {
        if (empty($audata)) {
            return null;
        }

        $statuscounts = array_fill_keys(self::STATUS_ORDER, 0);
        foreach ($audata as $item) {
            $statuscounts[$item['statusclass']]++;
        }

        $total = count($audata);
        $legend = [];
        foreach (self::STATUS_ORDER as $key) {
            if ($statuscounts[$key] > 0) {
                $legend[] = [
                    'statusclass' => $key,
                    'text' => get_string('progress:legend' . $key, 'cmi5', $statuscounts[$key]),
                ];
            }
        }

        // Continue with the first unit in progress, else the first not started, else the first to retry.
        $next = null;
        foreach (['inprogress', 'notstarted', 'failed'] as $wanted) {
            foreach ($audata as $item) {
                if ($item['statusclass'] === $wanted) {
                    $next = $item;
                    break 2;
                }
            }
        }

        return [
            'satisfied' => $statuscounts['satisfied'],
            'total' => $total,
            'segments' => array_map(fn($item) => ['statusclass' => $item['statusclass']], $audata),
            'legend' => $legend,
            'hasnext' => $next !== null,
            'next' => $next ? [
                'id' => $next['id'],
                'title' => $next['title'],
                'launchurl' => $next['launchurl'],
                'actiontext' => $next['actiontext'],
                'heading' => get_string($next['statusclass'] === 'inprogress' ? 'progress:continue' : 'progress:upnext', 'cmi5'),
                'position' => get_string('progress:position', 'cmi5', ['index' => $next['index'], 'total' => $total]),
            ] : null,
        ];
    }
}
