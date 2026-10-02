// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * My assessments dashboard behaviour.
 *
 * Every hook is a data- attribute, never a styling class, so restyling can't
 * break behaviour. Summary counts arrive as data (per type, per option); this
 * module only shows the right number. Search and the type filter hide rows;
 * search never changes the summary counts.
 *
 * @module     block_my_assessments/dashboard
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {setUserPreference} from 'core_user/repository';

const SELECTORS = {
    view: '[data-region="ma-view"]',
    viewRoot: '[data-region="ma-view-root"]',
    loading: '[data-region="ma-loading"]',
    section: '[data-region="ma-section"]',
    row: '[data-region="ma-row"]',
    more: '[data-region="ma-more"]',
    box: '[data-region="ma-box"]',
    boxValue: '[data-region="ma-box-value"]',
    filterEmpty: '[data-region="ma-filter-empty"]',
    search: '[data-action="search"]',
    typeButton: '[data-action="filter-type"]',
};

/**
 * Per-dashboard state that survives a view being re-rendered.
 */
class Dashboard {
    /**
     * @param {HTMLElement} root
     */
    constructor(root) {
        this.root = root;
        this.type = 'both';
        this.search = '';
        this.expanded = new Set();
        this.collapsed = new Set();
        root.addEventListener('click', e => this.click(e));
        root.addEventListener('input', e => this.input(e));
        root.addEventListener('change', e => this.change(e));
        this.apply();
    }

    /**
     * The open view's root element.
     *
     * @returns {HTMLElement|null}
     */
    get viewRoot() {
        return this.root.querySelector(SELECTORS.viewRoot);
    }

    /**
     * The open view's key.
     *
     * @returns {string}
     */
    get viewKey() {
        const view = this.viewRoot;
        return view ? view.dataset.view : this.root.dataset.view;
    }

    /**
     * Click handler.
     *
     * @param {Event} e
     */
    click(e) {
        const target = e.target.closest('[data-action]');
        if (!target || !this.root.contains(target)) {
            return;
        }
        const action = target.dataset.action;
        if (action === 'filter-type') {
            this.type = target.dataset.type;
            setUserPreference('block_my_assessments_type', this.type).catch(Notification.exception);
            this.apply();
        } else if (action === 'toggle-section') {
            const key = target.closest(SELECTORS.section).dataset.section;
            if (this.collapsed.has(key)) {
                this.collapsed.delete(key);
            } else {
                this.collapsed.add(key);
            }
            this.apply();
        } else if (action === 'show-more') {
            const key = target.closest(SELECTORS.section).dataset.section;
            if (this.expanded.has(key)) {
                this.expanded.delete(key);
            } else {
                this.expanded.add(key);
            }
            this.apply();
        } else if (action === 'toggle-grades') {
            const view = this.viewRoot;
            const show = !view.classList.contains('ma-view--showgrades');
            view.classList.toggle('ma-view--showgrades', show);
            target.setAttribute('aria-pressed', show ? 'true' : 'false');
            setUserPreference('block_my_assessments_showgrades', show ? 1 : 0).catch(Notification.exception);
        } else if (action === 'switch-view') {
            this.load('block_my_assessments_get_view', target.dataset.view);
            setUserPreference('block_my_assessments_view', target.dataset.view).catch(Notification.exception);
        } else if (action === 'refresh') {
            this.load('block_my_assessments_refresh', this.viewKey, this.yearId());
        }
    }

    /**
     * Input handler (search).
     *
     * @param {Event} e
     */
    input(e) {
        if (e.target.matches(SELECTORS.search)) {
            this.search = e.target.value.trim().toLowerCase();
            this.apply();
        }
    }

    /**
     * Change handler (dropdowns).
     *
     * @param {Event} e
     */
    change(e) {
        const action = e.target.dataset.action;
        if (action === 'box-option') {
            const box = e.target.closest(SELECTORS.box);
            const pref = {duesoon: 'block_my_assessments_window', marked: 'block_my_assessments_range'}[box.dataset.box];
            if (pref) {
                setUserPreference(pref, e.target.value).catch(Notification.exception);
            }
            this.apply();
        } else if (action === 'year') {
            setUserPreference('block_my_assessments_year', e.target.value).catch(Notification.exception);
            this.load('block_my_assessments_get_view', this.viewKey, this.yearId());
        }
    }

    /**
     * The selected academic year, or -1 for the saved or default one.
     *
     * @returns {number}
     */
    yearId() {
        const select = this.root.querySelector('[data-action="year"]');
        return select ? parseInt(select.value, 10) : -1;
    }

    /**
     * Fetches a rendered view and puts it in place.
     *
     * @param {string} methodname
     * @param {string} view
     * @param {number} yearid
     */
    async load(methodname, view, yearid = -1) {
        const loading = this.root.querySelector(SELECTORS.loading);
        const container = this.root.querySelector(SELECTORS.view);
        loading.hidden = false;
        container.setAttribute('aria-busy', 'true');
        try {
            const [result] = Ajax.call([{methodname, args: {view, yearid}}]);
            const response = await result;
            container.innerHTML = response.html;
            this.root.dataset.view = view;
            this.root.querySelectorAll('[data-action="switch-view"]').forEach(tab => {
                const active = tab.dataset.view === view;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            this.expanded.clear();
            this.collapsed.clear();
            const search = this.root.querySelector(SELECTORS.search);
            if (search) {
                search.value = this.search;
            }
            this.apply();
        } catch (error) {
            Notification.exception(error);
        } finally {
            loading.hidden = true;
            container.removeAttribute('aria-busy');
        }
    }

    /**
     * Shows the rows that match the filter and search, the right counts, and Show more.
     */
    apply() {
        const view = this.viewRoot;
        if (!view) {
            return;
        }
        view.querySelectorAll(SELECTORS.typeButton).forEach(button => {
            const active = button.dataset.type === this.type;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        // Summary boxes: numbers from data, for the type and the chosen option.
        view.querySelectorAll(SELECTORS.box).forEach(box => {
            const value = box.querySelector(SELECTORS.boxValue);
            if (!value || !box.dataset.counts) {
                return;
            }
            let counts = JSON.parse(box.dataset.counts);
            const select = box.querySelector('select');
            if (select) {
                counts = counts[select.value] || {};
            }
            value.textContent = counts[this.type] ?? 0;
        });

        const filtering = this.type !== 'both' || this.search !== '';
        let anyMatch = false;
        view.querySelectorAll(SELECTORS.section).forEach(section => {
            const key = section.dataset.section;
            const limit = parseInt(section.dataset.visibleRows, 10) || 5;
            const expanded = this.expanded.has(key);
            const collapsed = this.collapsed.has(key);
            let matches = 0;
            section.querySelectorAll(SELECTORS.row).forEach(row => {
                const match = (this.type === 'both' || row.dataset.assesstype === this.type) &&
                    (this.search === '' || (row.dataset.search || '').includes(this.search));
                if (match) {
                    matches++;
                }
                row.hidden = !match || collapsed || (!expanded && matches > limit);
            });
            anyMatch = anyMatch || matches > 0;
            // A section with nothing in it isn't shown at all.
            section.hidden = matches === 0;
            const count = section.querySelector('[data-region="ma-section-count"]');
            if (count) {
                count.textContent = matches;
            }
            const toggle = section.querySelector('[data-action="toggle-section"]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }
            section.classList.toggle('ma-section--collapsed', collapsed);
            const more = section.querySelector(SELECTORS.more);
            if (more) {
                more.hidden = collapsed || matches <= limit;
                const button = more.querySelector('[data-action="show-more"]');
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
        });
        const empty = view.querySelector(SELECTORS.filterEmpty);
        if (empty) {
            empty.hidden = !filtering || anyMatch;
        }
    }
}

/**
 * Starts the dashboard.
 *
 * @param {string} uniqid The dashboard root's id.
 * @param {string} type The saved assessment type filter.
 */
export const init = (uniqid, type = 'both') => {
    const root = document.getElementById(uniqid);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    const dashboard = new Dashboard(root);
    if (type !== 'both') {
        dashboard.type = type;
        dashboard.apply();
    }
};
