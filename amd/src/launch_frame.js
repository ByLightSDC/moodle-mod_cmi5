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
 * Launch frame - listens for cmi5_return postMessage from the AU iframe.
 *
 * @module     mod_cmi5/launch_frame
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the postMessage listener for the AU iframe.
 */
export const init = () => {
    window.addEventListener('message', (e) => {
        if (e.origin !== window.location.origin) {
            return;
        }
        if (e.data && e.data.type === 'cmi5_return') {
            window.location.href = e.data.url;
        }
    });
};
