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
 * Bulk selection conveniences for the cmi5 content upgrades list.
 *
 * Everything here is an accelerator for controls that already work on their own: each row
 * carries its own checkbox and its own target selector, so the page submits correctly with
 * this module absent. The group and page-wide controls stay hidden until it loads, rather
 * than sitting on the page doing nothing.
 *
 * @module     mod_cmi5/upgrades
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';

const SELECTORS = {
    form: '[data-region="cmi5-upgrades"]',
    group: '[data-region="group"]',
    row: '[data-region="row"]',
    rowCheck: '[data-region="row-check"]',
    rowTarget: '[data-region="row-target"]',
    changesLink: '[data-region="changes-link"]',
    count: '[data-region="count"]',
    selectAll: '[data-action="select-all"]',
    selectGroup: '[data-action="select-group"]',
    groupTarget: '[data-action="group-target"]',
    hidden: '.mod-cmi5-upg-jshidden',
};

/**
 * Every row checkbox inside a container.
 *
 * @param {Element} scope The form or one group.
 * @returns {Array} The checkboxes.
 */
const checksIn = (scope) => Array.from(scope.querySelectorAll(SELECTORS.rowCheck));

/**
 * Keep a row's changes link aligned with its selected target.
 *
 * @param {HTMLSelectElement} select The row target selector.
 */
const refreshChangesLink = (select) => {
    const link = select.closest(SELECTORS.row)?.querySelector(SELECTORS.changesLink);
    if (link) {
        const url = new URL(link.href);
        url.searchParams.set('targetversionid', select.value);
        link.href = url.toString();
    }
};

/**
 * Reflect the current selection in the count and in the group and page-wide boxes.
 *
 * The group box shows a third state when only part of its group is ticked, so "some" never
 * looks like "none".
 *
 * @param {Element} form The upgrades form.
 */
const refresh = async(form) => {
    const all = checksIn(form);
    const selected = all.filter((box) => box.checked);

    const counter = form.querySelector(SELECTORS.count);
    if (counter) {
        counter.textContent = selected.length === 1
            ? await getString('upgrade:selectone', 'mod_cmi5')
            : await getString('upgrade:selectcount', 'mod_cmi5', selected.length);
    }

    form.querySelectorAll(SELECTORS.group).forEach((group) => {
        const box = group.querySelector(SELECTORS.selectGroup);
        if (!box) {
            return;
        }
        const boxes = checksIn(group);
        const ticked = boxes.filter((one) => one.checked).length;
        box.checked = (boxes.length > 0 && ticked === boxes.length);
        box.indeterminate = (ticked > 0 && ticked < boxes.length);
    });

    const master = form.querySelector(SELECTORS.selectAll);
    if (master) {
        master.checked = (all.length > 0 && selected.length === all.length);
        master.indeterminate = (selected.length > 0 && selected.length < all.length);
    }
};

/**
 * Set a group's target selector onto every row it covers.
 *
 * A row whose own selector does not offer that version keeps what it had: the versions a
 * row may move to depend on the version it is on, and silently picking a different one
 * would change what the user is about to confirm.
 *
 * @param {Element} group The group section.
 * @param {string} value The chosen version ID.
 */
const applyGroupTarget = (group, value) => {
    group.querySelectorAll(SELECTORS.rowTarget).forEach((select) => {
        if (Array.from(select.options).some((option) => option.value === value)) {
            select.value = value;
            refreshChangesLink(select);
        }
    });
};

/**
 * Wire up the list.
 */
export const init = () => {
    const form = document.querySelector(SELECTORS.form);
    if (!form) {
        return;
    }

    // Only now are these worth showing: until this ran they controlled nothing.
    form.querySelectorAll(SELECTORS.hidden).forEach((element) => {
        element.classList.remove('mod-cmi5-upg-jshidden');
    });

    form.addEventListener('change', (e) => {
        const target = e.target;

        if (target.matches(SELECTORS.selectAll)) {
            checksIn(form).forEach((box) => {
                box.checked = target.checked;
            });
        } else if (target.matches(SELECTORS.selectGroup)) {
            const group = target.closest(SELECTORS.group);
            checksIn(group).forEach((box) => {
                box.checked = target.checked;
            });
        } else if (target.matches(SELECTORS.groupTarget)) {
            applyGroupTarget(target.closest(SELECTORS.group), target.value);
            return;
        } else if (target.matches(SELECTORS.rowTarget)) {
            refreshChangesLink(target);
            return;
        } else if (!target.matches(SELECTORS.rowCheck)) {
            return;
        }

        refresh(form);
    });

    refresh(form);
};
