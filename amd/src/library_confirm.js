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
 * Asks before following a destructive link on the content library pages.
 *
 * The prompt text travels on the link itself, so the template owns the wording and
 * this module stays free of strings.
 *
 * @module     mod_cmi5/library_confirm
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTOR = '[data-action="cmi5-confirm"]';

/**
 * Listen once at the document, so rows rendered later are covered too.
 */
export const init = () => {
    if (document.body.dataset.cmi5ConfirmReady) {
        return;
    }
    document.body.dataset.cmi5ConfirmReady = '1';

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest(SELECTOR);
        if (!trigger) {
            return;
        }

        const message = trigger.dataset.confirm;
        if (message && !window.confirm(message)) {
            e.preventDefault();
        }
    });
};
