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
 * The package chooser on the "add a course" page.
 *
 * The label already wraps a real file input, so choosing a file works with this module
 * absent. What it adds is dropping a file onto the zone and naming the chosen file
 * before the form is submitted.
 *
 * @module     mod_cmi5/library_add
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    zone: '[data-region="cmi5-dropzone"]',
    input: '[data-region="cmi5-fileinput"]',
    chosen: '[data-region="cmi5-chosenfile"]',
    name: '[data-region="cmi5-filename"]',
    meta: '[data-region="cmi5-filemeta"]',
    remove: '[data-action="cmi5-removefile"]',
};

/**
 * Render a byte count the way a file manager would.
 *
 * @param {number} bytes The file size.
 * @return {string} A short human-readable size.
 */
const formatSize = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
};

/**
 * Show or hide the chosen-file row to match the input's current selection.
 *
 * @param {HTMLElement} zone The drop zone wrapper.
 */
const refresh = (zone) => {
    const input = zone.querySelector(SELECTORS.input);
    const chosen = zone.parentNode.querySelector(SELECTORS.chosen);
    if (!input || !chosen) {
        return;
    }

    const file = input.files && input.files[0];
    if (!file) {
        chosen.hidden = true;
        return;
    }

    const name = chosen.querySelector(SELECTORS.name);
    const meta = chosen.querySelector(SELECTORS.meta);
    if (name) {
        name.textContent = file.name;
    }
    if (meta) {
        // The template seeds this with the "ready to read" wording, so only the size moves.
        meta.textContent = `${formatSize(file.size)} · ${meta.dataset.readylabel || ''}`.trim();
    }
    chosen.hidden = false;
};

/**
 * Wire the drop zone on the add page, if there is one.
 */
export const init = () => {
    const zone = document.querySelector(SELECTORS.zone);
    if (!zone) {
        return;
    }

    const input = zone.querySelector(SELECTORS.input);
    if (!input) {
        return;
    }

    input.addEventListener('change', () => refresh(zone));

    ['dragenter', 'dragover'].forEach((name) => {
        zone.addEventListener(name, (e) => {
            e.preventDefault();
            zone.classList.add('is-over');
        });
    });

    ['dragleave', 'drop'].forEach((name) => {
        zone.addEventListener(name, () => zone.classList.remove('is-over'));
    });

    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        if (!e.dataTransfer || !e.dataTransfer.files.length) {
            return;
        }
        input.files = e.dataTransfer.files;
        refresh(zone);
    });

    const remove = zone.parentNode.querySelector(SELECTORS.remove);
    if (remove) {
        remove.addEventListener('click', () => {
            input.value = '';
            refresh(zone);
            input.focus();
        });
    }

    // A browser that restores the field on a back navigation should show it named.
    refresh(zone);
};
