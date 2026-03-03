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
 * Live progress updates for AU status.
 *
 * @module     mod_cmi5/progress
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

let cmid = 0;
let pollTimer = null;

/**
 * Initialize progress polling.
 *
 * @param {number} courseModuleId The course module ID.
 * @param {number} intervalMs Polling interval in milliseconds.
 */
export const init = (courseModuleId, intervalMs = 10000) => {
    cmid = courseModuleId;
    startPolling(intervalMs);
};

/**
 * Start polling for status updates.
 *
 * @param {number} intervalMs Polling interval.
 */
const startPolling = (intervalMs) => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
    pollTimer = setInterval(fetchStatus, intervalMs);
};

/**
 * Stop polling.
 */
export const stop = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

/**
 * Fetch current AU status from server.
 */
const fetchStatus = () => {
    Ajax.call([{
        methodname: 'mod_cmi5_get_au_status',
        args: {cmid: cmid, auid: 0},
        done: (response) => {
            updateProgressBar(response);
        },
        fail: () => {
            // Silently fail.
        },
    }]);
};

/**
 * Update the progress bar with current status.
 *
 * @param {Array} aus Array of AU status objects.
 */
const updateProgressBar = (aus) => {
    const total = aus.length;
    if (total === 0) {
        return;
    }

    const satisfied = aus.filter(au => au.satisfied).length;
    const percentage = Math.round((satisfied / total) * 100);

    const progressBar = document.querySelector('.mod-cmi5-progress .progress-bar');
    if (progressBar) {
        progressBar.style.width = percentage + '%';
        progressBar.setAttribute('aria-valuenow', percentage);
    }

    const countDisplay = document.querySelector('.mod-cmi5-progress small:first-child');
    if (countDisplay) {
        countDisplay.textContent = satisfied + ' / ' + total;
    }

    const percentDisplay = document.querySelector('.mod-cmi5-progress small:last-child');
    if (percentDisplay) {
        percentDisplay.textContent = percentage + '%';
    }
};
