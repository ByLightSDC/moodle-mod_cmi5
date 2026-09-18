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
 * AU launcher - opens AUs in a named window and shows the unit-open state while it is open.
 *
 * Iframe launches (launch method 1) are plain links to launch.php and need nothing here.
 *
 * @module     mod_cmi5/launcher
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

const WINDOW_FEATURES = 'width=1024,height=768,menubar=no,toolbar=no,location=no,status=no';

/** @type {Map<string, {win: Window, url: string}>} Open AU windows by AU id. */
const openWindows = new Map();

/** @type {{auid: string, url: string}|null} The AU most recently launched. */
let lastLaunch = null;

let goToWindowText = '';

/**
 * Initialize the launcher module.
 *
 * @param {number} courseModuleId The course module ID.
 * @param {number} launchMethod Launch method (0=newwindow, 1=iframe).
 */
export const init = (courseModuleId, launchMethod) => {
    const root = document.querySelector('.mod-cmi5-view');
    if (!root) {
        return;
    }

    initHelpTooltips(root);
    initScrollableList(root);

    if (launchMethod !== 0) {
        return;
    }

    getString('launch:gotowindow', 'mod_cmi5').then((text) => {
        goToWindowText = text;
        return text;
    }).catch(Notification.exception);

    root.addEventListener('click', (e) => {
        const launchButton = e.target.closest('.mod-cmi5-launch-btn');
        if (launchButton) {
            e.preventDefault();
            launch(launchButton.dataset.auid, launchButton.getAttribute('href'));
            return;
        }
        if (lastLaunch && (e.target.closest('.mod-cmi5-focus-window') || e.target.closest('.mod-cmi5-relaunch'))) {
            launch(lastLaunch.auid, lastLaunch.url);
        }
    });
};

/**
 * Let Esc close the help tooltip (WCAG 1.4.13). It shows again on the next hover or focus.
 *
 * @param {HTMLElement} root The activity view.
 */
const initHelpTooltips = (root) => {
    root.querySelectorAll('.mod-cmi5-help').forEach((help) => {
        help.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                help.classList.add('is-dismissed');
            }
        });
        const reset = () => help.classList.remove('is-dismissed');
        help.addEventListener('mouseleave', reset);
        help.addEventListener('focusout', reset);
        help.addEventListener('mouseenter', reset);
    });
};

/**
 * Make the AU list keyboard-focusable only while it scrolls sideways, so keyboard users can scroll it
 * without an extra tab stop when everything fits.
 *
 * @param {HTMLElement} root The activity view.
 */
const initScrollableList = (root) => {
    const list = root.querySelector('.mod-cmi5-au-list');
    if (!list || typeof ResizeObserver === 'undefined') {
        return;
    }
    const update = () => {
        if (list.scrollWidth > list.clientWidth + 1) {
            list.setAttribute('tabindex', '0');
        } else {
            list.removeAttribute('tabindex');
        }
    };
    new ResizeObserver(update).observe(list);
    update();
};

/**
 * Open an AU in its own window, or bring its window forward when it is already open.
 *
 * Reopening an open window would navigate it again and start a new AU session, so we only focus it.
 *
 * @param {string} auid The AU database ID.
 * @param {string} url The launch.php URL.
 */
const launch = (auid, url) => {
    lastLaunch = {auid, url};

    const existing = openWindows.get(auid);
    if (existing && !existing.win.closed) {
        existing.win.focus();
        return;
    }

    const win = window.open(url, 'cmi5_au_' + auid, WINDOW_FEATURES);
    if (!win) {
        getString('launch:popupblocked', 'mod_cmi5').then((message) => {
            Notification.addNotification({message, type: 'warning'});
            return message;
        }).catch(Notification.exception);
        return;
    }

    openWindows.set(auid, {win, url});
    markOpen(auid);

    // When every AU window has closed, reload so statuses, scores and progress are current.
    const poll = setInterval(() => {
        if (win.closed) {
            clearInterval(poll);
            openWindows.delete(auid);
            if (openWindows.size === 0) {
                window.location.reload();
            }
        }
    }, 1000);
};

/**
 * Show that an AU is open in another window: on its row, and in the progress card.
 *
 * @param {string} auid The AU database ID.
 */
const markOpen = (auid) => {
    const row = document.querySelector(`.mod-cmi5-au[data-auid="${auid}"]`);
    if (row) {
        row.classList.add('is-open');
        const button = row.querySelector('.mod-cmi5-launch-btn');
        button?.classList.add('mod-cmi5-btn-tinted');
        const label = button?.querySelector('.mod-cmi5-launch-label');
        if (label && goToWindowText) {
            label.textContent = goToWindowText;
        }
    }

    const title = row?.dataset.title ?? '';
    const card = document.querySelector('.mod-cmi5-progress-card');
    const openTitle = card?.querySelector('.mod-cmi5-open-title');
    if (card && openTitle) {
        openTitle.textContent = title;
        card.classList.add('is-open');
    }

    // Tell screen reader users where the unit went; the visual changes above are silent.
    const announcer = document.querySelector('.mod-cmi5-announce');
    if (announcer) {
        getString('launch:openannounce', 'mod_cmi5', title).then((text) => {
            announcer.textContent = text;
            return text;
        }).catch(Notification.exception);
    }
};
