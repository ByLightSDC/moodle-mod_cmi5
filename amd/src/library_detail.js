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
 * Opens an assignable unit's details in place on the content library package page.
 *
 * Without this module every row still renders; the details simply stay hidden, so the
 * page degrades to the same information one page load away.
 *
 * @module     mod_cmi5/library_detail
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    region: '[data-region="cmi5-structure"]',
    toggle: '[data-action="cmi5-toggle-au"]',
};

/**
 * Open or close the details panel a row controls.
 *
 * @param {HTMLElement} toggle The row button.
 */
const toggleUnit = (toggle) => {
    const detail = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!detail) {
        return;
    }

    const open = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    detail.hidden = open;
};

/**
 * Wire up the structure tree.
 */
export const init = () => {
    const region = document.querySelector(SELECTORS.region);
    if (!region || region.dataset.cmi5Initialised) {
        return;
    }
    region.dataset.cmi5Initialised = '1';

    region.addEventListener('click', (e) => {
        const toggle = e.target.closest(SELECTORS.toggle);
        if (toggle && region.contains(toggle)) {
            toggleUnit(toggle);
        }
    });
};
