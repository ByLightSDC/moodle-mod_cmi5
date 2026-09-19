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
 * Content library package picker.
 *
 * The activity settings form shows only the current selection and a "Browse
 * library" button; this module opens a modal holding the searchable, paginated
 * card grid, and writes the result back into the form's packageid and
 * libraryauid selects. Those selects stay in the DOM as the no-JavaScript
 * fallback and remain the values the form actually submits.
 *
 * Package details replace the grid inside the same modal rather than opening a
 * second, stacked modal.
 *
 * @module     mod_cmi5/library_picker
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalFactory from 'core/modal_factory';
import ModalEvents from 'core/modal_events';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

/** @var {Number} Debounce delay for the search box, in milliseconds. */
const SEARCH_DEBOUNCE = 300;

/** @var {Number} Number of colour tones for generated package tiles. Mirrors library_picker::TONE_COUNT. */
const TONE_COUNT = 6;

/** @var {Number} Card description length. Mirrors library_picker::DESCRIPTION_LENGTH. */
const DESCRIPTION_LENGTH = 120;

/** @var {Number} Source type for an externally hosted AU. Mirrors content_library::SOURCE_EXTERNAL_URL. */
const SOURCE_EXTERNAL_URL = 1;

/** @var {Number} How many numbered page buttons to show around the current page. */
const PAGE_WINDOW = 2;

const SELECTORS = {
    root: '[data-region="cmi5-library-picker"]',
    selection: '[data-region="selection"]',
    clear: '[data-action="clear"]',
    browse: '[data-action="browse"]',
    browsePane: '[data-region="browse"]',
    detailPane: '[data-region="detail"]',
    cards: '[data-region="cards"]',
    pages: '[data-region="pages"]',
    empty: '[data-region="empty"]',
    error: '[data-region="error"]',
    card: '[data-region="package-card"]',
    search: '[data-action="search"]',
    sort: '[data-action="sort"]',
    source: '[data-action="source"]',
    page: '[data-action="page"]',
    viewDetails: '[data-action="view-details"]',
    back: '[data-action="back"]',
    selectPackage: '[data-action="select-package"]',
    selectAu: '[data-action="select-au"]',
};

/**
 * Hash a title to a small number. Mirrors library_picker::title_hash(), so tile colours match
 * between server-rendered and client-rendered markup. Works on UTF-8 bytes, as PHP does.
 *
 * @param {String} text The text to hash.
 * @return {Number} A number between 0 and 1000002.
 */
const titleHash = (text) => new TextEncoder().encode(text)
    .reduce((hash, byte) => (hash * 31 + byte) % 1000003, 0);

/**
 * Derive up to two initials from a package title. Mirrors library_picker::initials().
 *
 * @param {String} title The package title.
 * @return {String} One or two upper-case characters.
 */
const initials = (title) => {
    const words = title.trim().split(/[\s\-_]+/).filter((word) => word.length);
    if (!words.length) {
        return '?';
    }
    let result = words[0].charAt(0).toUpperCase();
    if (words.length > 1) {
        result += words[1].charAt(0).toUpperCase();
    }
    return result;
};

/**
 * Pick a tile colour tone for a title. Mirrors library_picker::tone().
 *
 * @param {String} title The package title.
 * @return {Number} A tone index between 1 and TONE_COUNT.
 */
const tone = (title) => (titleHash(title) % TONE_COUNT) + 1;

/**
 * Shorten a description for display on a card.
 *
 * @param {String} text The raw description.
 * @return {String} The shortened plain-text description.
 */
const shorten = (text) => {
    const plain = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    if (plain.length <= DESCRIPTION_LENGTH) {
        return plain;
    }
    const cut = plain.substring(0, DESCRIPTION_LENGTH);
    const lastspace = cut.lastIndexOf(' ');
    return (lastspace > 0 ? cut.substring(0, lastspace) : cut) + '...';
};

/**
 * Build a card template context from a web service package record.
 *
 * @param {Object} pkg A package record from mod_cmi5_library_list_packages.
 * @param {Number} selectedId The package ID currently marked in the grid.
 * @return {Object} The card template context.
 */
const cardContext = (pkg, selectedId) => {
    const description = (pkg.description || '').trim();
    const aucount = pkg.aucount || 0;
    const usagecount = pkg.usagecount || 0;

    return {
        id: pkg.id,
        title: pkg.title,
        description: description ? shorten(description) : '',
        hasdescription: description !== '',
        initials: initials(pkg.title),
        tone: tone(pkg.title),
        versionnumber: pkg.versionnumber || 0,
        hasversion: !!pkg.versionnumber,
        aucount: aucount,
        hasaus: aucount > 0,
        usagecount: usagecount,
        hasusage: usagecount > 0,
        isexternal: pkg.source === SOURCE_EXTERNAL_URL,
        selected: pkg.id === selectedId,
    };
};

/**
 * Build the pagination template context.
 *
 * @param {Number} page The current 1-based page number.
 * @param {Number} perPage Results per page.
 * @param {Number} total Total matching results.
 * @return {Object} The pagination template context.
 */
const pagesContext = (page, perPage, total) => {
    const pagecount = Math.max(1, Math.ceil(total / perPage));
    const pages = [];
    let previous = 0;

    for (let number = 1; number <= pagecount; number++) {
        const isEdge = number === 1 || number === pagecount;
        const isNear = Math.abs(number - page) <= PAGE_WINDOW;
        if (!isEdge && !isNear) {
            continue;
        }
        if (previous && number - previous > 1) {
            pages.push({ellipsis: true});
        }
        pages.push({number: number, active: number === page, ellipsis: false});
        previous = number;
    }

    return {
        pages: pages,
        multipage: pagecount > 1,
        hasprev: page > 1,
        hasnext: page < pagecount,
        prevpage: Math.max(1, page - 1),
        nextpage: Math.min(pagecount, page + 1),
        from: total ? ((page - 1) * perPage) + 1 : 0,
        to: Math.min(page * perPage, total),
        total: total,
    };
};

/**
 * Create a save/cancel modal.
 *
 * Moodle 4.3 moved modal creation onto the modal classes themselves and deprecated
 * core/modal_factory, so prefer the modern API and fall back for Moodle 4.1 and 4.2.
 *
 * @param {Object} config Modal configuration: title, body, large.
 * @return {Promise} Resolved with the created modal.
 */
const createSaveCancelModal = (config) => {
    if (typeof ModalSaveCancel.create === 'function') {
        return ModalSaveCancel.create(config);
    }
    return ModalFactory.create({...config, type: ModalFactory.types.SAVE_CANCEL});
};

/**
 * The picker attached to one activity settings form.
 */
class Picker {

    /**
     * Constructor.
     *
     * @param {HTMLElement} root The picker root element.
     * @param {Object} config Init configuration.
     */
    constructor(root, config) {
        this.root = root;
        this.contextId = config.contextId;
        this.currentPackageId = config.currentPackageId || 0;
        this.currentVersionId = config.currentVersionId || 0;
        this.packageInput = document.getElementById(config.inputId);
        this.auInput = document.getElementById(config.auInputId);
        this.perPage = config.perPage || 9;
        this.modalContext = config.modalContext || {sorts: [], sources: []};
        this.detailCache = {};
        this.resetBrowseState();
    }

    /**
     * Reset the modal's transient browse state.
     */
    resetBrowseState() {
        this.page = 1;
        this.search = '';
        this.sort = 'recent';
        this.source = -1;
        this.total = 0;
        this.searchTimer = null;
        this.requestId = 0;
        this.modal = null;
        this.pending = {packageId: 0, auValue: ''};
    }

    /**
     * Reveal the picker, hide the fallback selects and bind the form-level buttons.
     */
    start() {
        if (!this.packageInput) {
            return;
        }
        this.root.classList.remove('cmi5-picker-jshidden');
        this.hideFallback(this.packageInput);
        this.hideFallback(this.auInput);

        this.root.addEventListener('click', (e) => {
            if (e.target.closest(SELECTORS.browse)) {
                e.preventDefault();
                this.openModal();
            } else if (e.target.closest(SELECTORS.clear)) {
                e.preventDefault();
                this.apply(0, '');
            }
        });
    }

    /**
     * Hide the form group wrapping a fallback select, leaving it in the DOM so it
     * still submits its value.
     *
     * A group carrying a validation message stays visible, otherwise the user would
     * never see why the form was rejected.
     *
     * @param {HTMLElement} element The select element, if present.
     */
    hideFallback(element) {
        if (!element) {
            return;
        }
        const group = element.closest('.fitem') || element.closest('.form-group');
        if (!group || this.hasError(group)) {
            return;
        }
        group.classList.add('cmi5-picker-fallback-hidden');
    }

    /**
     * Check whether a form group is currently showing a validation message.
     *
     * @param {HTMLElement} group The form group element.
     * @return {Boolean} True when a non-empty error message is present.
     */
    hasError(group) {
        const error = group.querySelector('.form-control-feedback, .invalid-feedback, .error');
        return !!(error && error.textContent.trim().length);
    }

    /**
     * The package ID currently saved in the form.
     *
     * @return {Number} The package ID, or 0 when nothing is selected.
     */
    get selectedId() {
        return parseInt(this.packageInput.value, 10) || 0;
    }

    /**
     * Open the browse modal and load the first page of results.
     *
     * @return {Promise} Resolved once the modal is shown.
     */
    openModal() {
        this.page = 1;
        this.search = '';
        this.sort = 'recent';
        this.source = -1;
        this.pending = {
            packageId: this.selectedId,
            auValue: this.auInput ? this.auInput.value : '',
        };

        // The body is rendered up front rather than handed to the modal as a
        // promise: a promised body can still be empty when ModalEvents.shown
        // fires, which would leave the first fetch with nowhere to render.
        return Promise.all([
            getString('picker:modaltitle', 'cmi5'),
            getString('picker:usethispackage', 'cmi5'),
            Templates.renderForPromise('mod_cmi5/library_picker/modal', this.modalContext),
        ]).then(([title, saveLabel, body]) => createSaveCancelModal({
            title: title,
            body: body.html,
            large: true,
        }).then((modal) => {
            this.modal = modal;
            modal.setSaveButtonText(saveLabel);
            modal.getRoot().on(ModalEvents.save, (e) => {
                e.preventDefault();
                this.commit().then((saved) => {
                    if (saved && this.modal === modal) {
                        modal.hide();
                    }
                });
            });
            modal.getRoot().on(ModalEvents.hidden, () => {
                window.clearTimeout(this.searchTimer);
                this.requestId++;
                modal.destroy();
                if (this.modal === modal) {
                    this.modal = null;
                }
            });
            modal.getRoot().on(ModalEvents.shown, () => {
                Templates.runTemplateJS(body.js);
                this.bindModalEvents();
                this.fetch();
            });
            modal.show();
            return modal;
        })).catch(Notification.exception);
    }

    /**
     * The modal's body element.
     *
     * @return {HTMLElement|null} The body element, or null when no modal is open.
     */
    get body() {
        return this.modal ? this.modal.getBody()[0] : null;
    }

    /**
     * Bind the modal's search, filter, paging, card and detail events.
     */
    bindModalEvents() {
        const body = this.body;
        if (!body) {
            return;
        }

        const searchbox = body.querySelector(SELECTORS.search);
        if (searchbox) {
            searchbox.addEventListener('input', () => {
                window.clearTimeout(this.searchTimer);
                this.searchTimer = window.setTimeout(() => {
                    this.search = searchbox.value.trim();
                    this.page = 1;
                    this.fetch();
                }, SEARCH_DEBOUNCE);
            });
        }

        body.addEventListener('change', (e) => {
            const sortbox = e.target.closest(SELECTORS.sort);
            if (sortbox) {
                this.sort = sortbox.value;
                this.page = 1;
                this.fetch();
                return;
            }
            const sourcebox = e.target.closest(SELECTORS.source);
            if (sourcebox) {
                this.source = parseInt(sourcebox.value, 10);
                this.page = 1;
                this.fetch();
                return;
            }
            const radio = e.target.closest(SELECTORS.selectPackage);
            if (radio) {
                this.markPending(parseInt(radio.value, 10), '');
                return;
            }
            const au = e.target.closest(SELECTORS.selectAu);
            if (au) {
                this.pending.auValue = au.value;
            }
        });

        body.addEventListener('click', (e) => {
            const pagebutton = e.target.closest(SELECTORS.page);
            if (pagebutton && !pagebutton.disabled) {
                e.preventDefault();
                this.page = parseInt(pagebutton.dataset.page, 10);
                this.fetch();
                return;
            }
            const details = e.target.closest(SELECTORS.viewDetails);
            if (details) {
                e.preventDefault();
                this.showDetails(parseInt(details.dataset.packageid, 10));
                return;
            }
            if (e.target.closest(SELECTORS.back)) {
                e.preventDefault();
                this.showBrowse();
            }
        });
    }

    /**
     * Fetch and render the current page of results.
     *
     * @return {Promise} Resolved once the grid and pagination have been rendered.
     */
    fetch() {
        const body = this.body;
        const modal = this.modal;
        if (!body) {
            return Promise.resolve();
        }
        this.toggle(SELECTORS.error, false);
        const requestId = ++this.requestId;

        return Ajax.call([{
            methodname: 'mod_cmi5_library_list_packages',
            args: {
                contextid: this.contextId,
                search: this.search,
                status: 1,
                offset: (this.page - 1) * this.perPage,
                limit: this.perPage,
                sort: this.sort,
                source: this.source,
            },
        }])[0].then((response) => {
            if (requestId !== this.requestId || modal !== this.modal) {
                return;
            }
            return this.render(response, requestId);
        })
            .catch((error) => {
                if (requestId === this.requestId && modal === this.modal) {
                    this.toggle(SELECTORS.error, true);
                    window.console.error('mod_cmi5/library_picker: ', error);
                }
            });
    }

    /**
     * Render a page of results into the modal grid.
     *
     * @param {Object} response The web service response.
     * @param {Number} requestId Request generation that produced the response.
     * @return {Promise} Resolved once rendering is complete.
     */
    render(response, requestId) {
        const body = this.body;
        if (!body) {
            return Promise.resolve();
        }
        this.total = response.total;

        const grid = body.querySelector(SELECTORS.cards);
        if (!grid) {
            return Promise.resolve();
        }
        const renders = response.packages.map(
            (pkg) => Templates.renderForPromise('mod_cmi5/library_picker/card',
                cardContext(pkg, this.pending.packageId))
        );

        return Promise.all(renders).then((results) => {
            if (requestId !== this.requestId || !this.body) {
                return;
            }
            grid.innerHTML = '';
            results.forEach(({html, js}) => Templates.appendNodeContents(grid, html, js));
            this.toggle(SELECTORS.empty, results.length === 0);
            return this.renderPages(requestId);
        });
    }

    /**
     * Render the pagination controls for the current result set.
     *
     * @param {Number} requestId Request generation that produced the response.
     * @return {Promise} Resolved once the pagination has been rendered.
     */
    renderPages(requestId) {
        const body = this.body;
        if (!body) {
            return Promise.resolve();
        }
        const region = body.querySelector(SELECTORS.pages);
        return Templates.renderForPromise('mod_cmi5/library_picker/pages',
            pagesContext(this.page, this.perPage, this.total))
            .then(({html, js}) => {
                if (requestId !== this.requestId || !this.body) {
                    return;
                }
                return Templates.replaceNodeContents(region, html, js);
            });
    }

    /**
     * Show or hide one of the modal's status regions.
     *
     * @param {String} selector A region selector.
     * @param {Boolean} visible Whether the region should be visible.
     */
    toggle(selector, visible) {
        const element = this.body ? this.body.querySelector(selector) : null;
        if (element) {
            element.classList.toggle('hidden', !visible);
            element.classList.toggle('d-none', !visible);
        }
    }

    /**
     * Mark a package as the pending selection inside the modal.
     *
     * Choosing a different package resets the AU scope back to "all AUs".
     *
     * @param {Number} packageId The package ID.
     * @param {String} auValue The AU value, or '' for all AUs.
     */
    markPending(packageId, auValue) {
        const changed = packageId !== this.pending.packageId;
        this.pending = {
            packageId: packageId,
            auValue: changed ? auValue : (auValue || this.pending.auValue),
        };

        const body = this.body;
        if (!body) {
            return;
        }
        body.querySelectorAll(SELECTORS.card).forEach((card) => {
            const isSelected = parseInt(card.dataset.packageid, 10) === packageId;
            const label = card.querySelector('label');
            const radio = card.querySelector(SELECTORS.selectPackage);
            if (label) {
                label.classList.toggle('cmi5-picker-card-selected', isSelected);
            }
            if (radio) {
                radio.checked = isSelected;
            }
        });
    }

    /**
     * Replace the grid with a package's details, keeping the search state intact.
     *
     * @param {Number} packageId The package to show.
     * @return {Promise} Resolved once the detail pane is rendered.
     */
    showDetails(packageId) {
        const modal = this.modal;
        const body = this.body;
        return this.getPackage(packageId).then((pkg) => {
            if (!body || modal !== this.modal) {
                return;
            }
            const selectedAu = this.pending.packageId === packageId ? this.pending.auValue : '';
            const context = {
                packageid: pkg.id,
                title: pkg.title,
                description: pkg.description || '',
                hasdescription: !!(pkg.description || '').trim(),
                courseidiri: pkg.courseid_iri || '',
                hascourseidiri: !!pkg.courseid_iri,
                versionnumber: pkg.versionnumber,
                usagecount: pkg.usagecount,
                hasaus: pkg.aus.length > 0,
                allselected: !selectedAu,
                aus: pkg.aus.map((au) => ({
                    id: au.id,
                    value: pkg.id + ':' + au.id,
                    title: au.title,
                    description: au.description || '',
                    hasdescription: !!(au.description || '').trim(),
                    launchmethod: au.launchmethod,
                    moveoncriteria: au.moveoncriteria,
                    isexternal: au.isexternal === 1,
                    selected: selectedAu === pkg.id + ':' + au.id,
                })),
            };

            // Viewing a package's details selects it, so "Use this package" is
            // unambiguous once the AU radios are on screen.
            this.markPending(packageId, selectedAu);

            const region = body.querySelector(SELECTORS.detailPane);
            return Templates.renderForPromise('mod_cmi5/library_picker/detail', context)
                .then(({html, js}) => {
                    Templates.replaceNodeContents(region, html, js);
                    this.toggle(SELECTORS.browsePane, false);
                    this.toggle(SELECTORS.detailPane, true);
                    const back = region.querySelector(SELECTORS.back);
                    if (back) {
                        back.focus();
                    }
                    return region;
                });
        }).catch(Notification.exception);
    }

    /**
     * Return from the detail pane to the results grid.
     */
    showBrowse() {
        if (!this.body) {
            return;
        }
        this.toggle(SELECTORS.detailPane, false);
        this.toggle(SELECTORS.browsePane, true);
        const searchbox = this.body.querySelector(SELECTORS.search);
        if (searchbox) {
            searchbox.focus();
        }
    }

    /**
     * Commit the pending selection to the form when the modal is saved.
     */
    commit() {
        if (!this.pending.packageId) {
            return Promise.resolve(false);
        }
        const selection = {...this.pending};
        return this.getPackage(selection.packageId).then((pkg) => {
            this.ensurePackageOption(pkg);
            this.populateAuOptions(pkg.id, pkg.aus);
            return this.apply(selection.packageId, selection.auValue).then(() => true);
        }).catch((error) => {
            Notification.exception(error);
            return false;
        });
    }

    /**
     * Write a selection into the form and refresh the selection summary.
     *
     * @param {Number} packageId The package ID, or 0 to clear the selection.
     * @param {String} auValue The AU value, or '' for all AUs.
     * @return {Promise} Resolved once the summary has been re-rendered.
     */
    apply(packageId, auValue) {
        this.setSelectValue(this.packageInput, packageId ? packageId : '');
        this.setSelectValue(this.auInput, packageId ? auValue : '');

        const clear = this.root.querySelector(SELECTORS.clear);
        if (clear) {
            clear.classList.toggle('hidden', !packageId);
            clear.classList.toggle('d-none', !packageId);
        }

        return this.renderSelection(packageId, auValue);
    }

    /**
     * Re-render the selection summary shown in the form.
     *
     * @param {Number} packageId The selected package ID, or 0 for none.
     * @param {String} auValue The selected AU value, or '' for all AUs.
     * @return {Promise} Resolved once the summary has been rendered.
     */
    renderSelection(packageId, auValue) {
        const region = this.root.querySelector(SELECTORS.selection);
        if (!region) {
            return Promise.resolve();
        }
        if (!packageId) {
            return Templates.renderForPromise('mod_cmi5/library_picker/selection', {hasselection: false})
                .then(({html, js}) => Templates.replaceNodeContents(region, html, js));
        }

        const title = this.packageTitle(packageId);
        const auOption = auValue && this.auInput
            ? this.auInput.querySelector('option[value="' + auValue + '"]')
            : null;

        return Promise.resolve(auOption ? auOption.textContent : getString('library:allaus', 'cmi5'))
            .then((auLabel) => Templates.renderForPromise('mod_cmi5/library_picker/selection', {
                hasselection: true,
                selectedtitle: title,
                selectedautitle: auLabel,
                initials: initials(title),
                tone: tone(title),
            }))
            .then(({html, js}) => Templates.replaceNodeContents(region, html, js));
    }

    /**
     * Resolve a package's title from the fallback select.
     *
     * @param {Number} packageId The package ID.
     * @return {String} The package title, or '' when it cannot be resolved.
     */
    packageTitle(packageId) {
        const option = this.packageInput.querySelector
            ? this.packageInput.querySelector('option[value="' + packageId + '"]')
            : null;
        return option ? option.textContent.trim() : '';
    }

    /**
     * Set a value on a fallback select or input, tolerating a missing element.
     *
     * @param {HTMLElement} element The select or input.
     * @param {String|Number} value The value to set.
     */
    setSelectValue(element, value) {
        if (!element) {
            return;
        }
        element.value = value;
        if (element.tagName === 'SELECT' && element.value !== String(value)) {
            element.value = '';
        }
    }

    /**
     * Add a package to the fallback select when it was loaded beyond its initial limit.
     *
     * @param {Object} pkg Package returned by mod_cmi5_library_get_package.
     */
    ensurePackageOption(pkg) {
        if (!this.packageInput || this.packageInput.tagName !== 'SELECT') {
            return;
        }
        const value = String(pkg.id);
        if (!Array.from(this.packageInput.options).some((option) => option.value === value)) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = pkg.title;
            this.packageInput.appendChild(option);
        }
    }

    /**
     * Rebuild the fallback AU select so it carries the options for one package.
     *
     * @param {Number} packageId The package the AUs belong to.
     * @param {Array} aus The AU records from mod_cmi5_library_get_package.
     */
    populateAuOptions(packageId, aus) {
        if (!this.auInput || this.auInput.tagName !== 'SELECT') {
            return;
        }
        const keep = this.auInput.querySelector('option[value=""]');
        this.auInput.innerHTML = '';
        if (keep) {
            this.auInput.appendChild(keep);
        }
        aus.forEach((au) => {
            const option = document.createElement('option');
            option.value = packageId + ':' + au.id;
            option.textContent = au.title;
            this.auInput.appendChild(option);
        });
    }

    /**
     * Fetch a package's full details, caching the result for the page lifetime.
     *
     * @param {Number} packageId The package ID.
     * @return {Promise} Resolved with the package details.
     */
    getPackage(packageId) {
        if (!this.detailCache[packageId]) {
            const versionId = packageId === this.currentPackageId ? this.currentVersionId : 0;
            this.detailCache[packageId] = Ajax.call([{
                methodname: 'mod_cmi5_library_get_package',
                args: {packageid: packageId, versionid: versionId, contextid: this.contextId},
            }])[0].catch((error) => {
                delete this.detailCache[packageId];
                throw error;
            });
        }
        return this.detailCache[packageId];
    }
}

/**
 * Initialise the content library picker.
 *
 * @param {Object} config Configuration: contextId, currentPackageId, currentVersionId, inputId, auInputId,
 * perPage, total, modalContext.
 */
export const init = (config) => {
    const root = document.querySelector(SELECTORS.root);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    new Picker(root, config).start();
};
