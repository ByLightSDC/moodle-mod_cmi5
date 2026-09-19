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
 * Embedded AU launch: hides the start-up card once the AU loads, and offers full screen.
 *
 * @module     mod_cmi5/launch_frame
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** How long to wait for the AU's load event before revealing the frame anyway. */
const LOAD_TIMEOUT_MS = 15000;

/**
 * Initialize the launch frame.
 */
export const init = () => {
    const frame = document.querySelector('.mod-cmi5-launch-frame');
    const iframe = frame?.querySelector('.mod-cmi5-iframe');
    if (!frame || !iframe) {
        return;
    }

    const reveal = () => frame.classList.add('is-loaded');
    iframe.addEventListener('load', reveal, {once: true});
    setTimeout(reveal, LOAD_TIMEOUT_MS);

    // The AU may have finished loading before this ran. Only same-origin content can tell us so.
    try {
        const doc = iframe.contentDocument;
        if (doc && doc.readyState === 'complete' && doc.location.href !== 'about:blank') {
            reveal();
        }
    } catch (e) {
        // Cross-origin AU: wait for the load event or the timeout.
    }

    const stage = frame.querySelector('.mod-cmi5-frame-stage');
    const fullscreenButton = frame.querySelector('.mod-cmi5-frame-fullscreen');
    if (!fullscreenButton) {
        return;
    }
    if (!document.fullscreenEnabled || !stage?.requestFullscreen) {
        fullscreenButton.hidden = true;
        return;
    }
    fullscreenButton.addEventListener('click', () => {
        stage.requestFullscreen().catch(() => {
            fullscreenButton.hidden = true;
        });
    });
};
