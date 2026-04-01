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
 * AU launcher - manages window/iframe launch and status polling.
 *
 * @module     mod_cmi5/launcher
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

let cmid = 0;
let defaultLaunchMethod = 0;

/**
 * Initialize the launcher module.
 *
 * @param {number} courseModuleId The course module ID.
 * @param {number} launchMethod Default launch method (0=newwindow, 1=iframe).
 */
export const init = (courseModuleId, launchMethod) => {
    cmid = courseModuleId;
    defaultLaunchMethod = launchMethod;

    document.querySelectorAll('.mod-cmi5-launch-btn').forEach((btn) => {
        btn.addEventListener('click', handleLaunch);
    });
};

/**
 * Handle AU launch button click.
 *
 * @param {Event} e Click event.
 */
const handleLaunch = (e) => {
    // For new window launches, let the default link behavior handle it.
    // For iframe launches, the server-side handles the redirect.
    if (defaultLaunchMethod === 0) {
        // New window - open in a popup-style window.
        e.preventDefault();
        const url = e.currentTarget.getAttribute('href');
        const auid = e.currentTarget.dataset.auid;

        const windowFeatures = 'width=1024,height=768,menubar=no,toolbar=no,location=no,status=no';
        const launchWindow = window.open(url, 'cmi5_au_' + auid, windowFeatures);

        if (launchWindow) {
            // Poll for window close to refresh status.
            const pollInterval = setInterval(() => {
                if (launchWindow.closed) {
                    clearInterval(pollInterval);
                    refreshAuStatus(auid);
                }
            }, 2000);
        }
    }
    // For iframe (launchMethod=1), the default link behavior redirects to launch.php
    // which renders the iframe template.
};

/**
 * Refresh AU status after a launch window closes.
 *
 * @param {number} auid The AU database ID.
 */
const refreshAuStatus = (auid) => {
    Ajax.call([{
        methodname: 'mod_cmi5_get_au_status',
        args: {cmid: cmid, auid: parseInt(auid)},
        done: (response) => {
            if (response && response.length > 0) {
                updateAuDisplay(response[0]);
            }
        },
        fail: () => {
            // Silently fail - user can manually refresh.
        },
    }]);
};

/**
 * Update the AU display row with new status.
 *
 * @param {object} au The AU status data.
 */
const updateAuDisplay = (au) => {
    const row = document.querySelector(`[data-auid="${au.id}"]`);
    if (!row) {
        return;
    }

    const badge = row.querySelector('.badge');
    if (badge) {
        badge.textContent = au.statustext;
        // Remove old status classes and add new one.
        badge.className = badge.className.replace(/badge-\S+/g, '').replace(/bg-\S+/g, '');
        const statusclass = getStatusClass(au);
        badge.classList.add('badge', 'badge-' + statusclass, 'bg-' + statusclass);
    }

    const scoreCell = row.querySelector('.mod-cmi5-au-score');
    if (scoreCell && au.score_scaled !== null) {
        scoreCell.textContent = au.score_scaled;
    }
};

/**
 * Determine CSS class for AU status.
 *
 * @param {object} au The AU status data.
 * @returns {string} CSS class name.
 */
const getStatusClass = (au) => {
    if (au.satisfied) {
        return 'satisfied';
    }
    if (au.passed) {
        return 'passed';
    }
    if (au.failed) {
        return 'failed';
    }
    if (au.completed) {
        return 'completed';
    }
    if (au.inprogress) {
        return 'inprogress';
    }
    return 'notstarted';
};