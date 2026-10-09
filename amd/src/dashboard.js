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
 * @module     block_le_dashboard/dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString} from 'core/str';
import {setUserPreference} from 'core_user/repository';
import {localise} from './localtime';

/** Milliseconds the pointer rests on a tip trigger before its tip opens, as a tooltip does. */
const HOVER_DELAY = 500;

/** Milliseconds a hover-opened tip stays after the pointer leaves, to reach a link in it. */
const HOVER_GRACE = 200;

const SELECTORS = {
    view: '[data-region="ma-view"]',
    mitigate: '[data-region="ma-mitigate"]',
    viewRoot: '[data-region="ma-view-root"]',
    loading: '[data-region="ma-loading"]',
    status: '[data-region="ma-status"]',
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
        // Each load gets a number; a response from a load that a later one superseded is dropped.
        this.seq = 0;
        this.refreshing = false;
        root.addEventListener('click', e => this.click(e));
        // Opening a Mitigate menu again puts an unconfirmed Delete mitigation back. Captured, because Bootstrap
        // stops the toggle's click once its dropdown has opened.
        root.addEventListener('click', e => {
            const toggle = e.target.closest && e.target.closest('[data-action="mitigate"]');
            if (toggle) {
                this.disarm(toggle.closest(SELECTORS.mitigate));
            }
        }, true);
        root.addEventListener('input', e => this.input(e));
        root.addEventListener('change', e => this.change(e));
        root.addEventListener('focusin', e => this.focusin(e));
        root.addEventListener('focusout', e => this.focusout(e));
        root.addEventListener('keydown', e => this.keydown(e));
        root.addEventListener('mouseover', e => this.hoverIn(e));
        root.addEventListener('mouseout', e => this.hoverOut(e));
        // Escape with focus outside the block closes a tip a hover opened.
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && !this.root.contains(e.target)) {
                this.cancelHover();
                this.closeTips();
            }
        });
        // Like Moodle's help icons: a click anywhere else closes an open tip.
        document.addEventListener('click', e => {
            if (!e.target.closest || !e.target.closest('.ma-tip-wrap')) {
                this.closeTips();
            }
        });
        this.apply();
        localise(root).catch(Notification.exception);
    }

    /**
     * Puts unconfirmed Delete mitigation items back to their label.
     *
     * @param {HTMLElement|null} where
     */
    disarm(where) {
        (where || this.root).querySelectorAll('[data-action="mitigate-delete"][data-armed]').forEach(item => {
            const label = item.querySelector('[data-region="ma-delete-label"]');
            if (label && item.dataset.label) {
                label.textContent = item.dataset.label;
            }
            delete item.dataset.armed;
        });
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
        if (action === 'tip') {
            this.cancelHover();
            // A click on a tip a hover opened keeps it open; otherwise it toggles.
            const open = target.dataset.opened === 'hover' || target.getAttribute('aria-expanded') !== 'true';
            this.closeTips(target);
            this.setTip(target, open);
        } else if (action === 'filter-type') {
            this.type = target.dataset.type;
            setUserPreference('block_le_dashboard_type', this.type).catch(Notification.exception);
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
            setUserPreference('block_le_dashboard_showgrades', show ? 1 : 0).catch(Notification.exception);
        } else if (action === 'mitigate-delete') {
            e.preventDefault();
            const label = target.querySelector('[data-region="ma-delete-label"]');
            if (label && !target.dataset.armed) {
                // The first click asks for a second, in the same place; the menu stays open (Pierre, 2026-10-09).
                e.stopPropagation();
                target.dataset.armed = '1';
                target.dataset.label = label.textContent;
                getString('mitigate_deleteconfirm', 'block_le_dashboard').then(text => {
                    label.textContent = text;
                    this.root.querySelector(SELECTORS.status).textContent = text;
                    return text;
                }).catch(Notification.exception);
                return;
            }
            // As rc6: block_rhb's delete link is followed in the background, then the page reloads.
            const href = target.getAttribute('href');
            if (href) {
                fetch(href, {method: 'GET', credentials: 'same-origin'})
                    .then(() => window.location.reload())
                    .catch(() => {
                        window.location.href = href;
                    });
            }
        } else if (action === 'switch-view') {
            this.load('block_le_dashboard_get_view', target.dataset.view);
            setUserPreference('block_le_dashboard_view', target.dataset.view).catch(Notification.exception);
        } else if (action === 'refresh') {
            this.load('block_le_dashboard_refresh', this.viewKey, this.yearId());
        }
    }

    /**
     * The tip a trigger controls.
     *
     * @param {HTMLElement} trigger
     * @returns {HTMLElement|null}
     */
    tipFor(trigger) {
        return document.getElementById(trigger.getAttribute('aria-controls'));
    }

    /**
     * Opens or closes one tip.
     *
     * @param {HTMLElement} trigger
     * @param {boolean} open
     * @param {string} how What opened it: click (or keyboard focus) or hover
     */
    setTip(trigger, open, how = 'click') {
        const tip = this.tipFor(trigger);
        if (tip) {
            tip.hidden = !open;
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                trigger.dataset.opened = how;
            } else {
                delete trigger.dataset.opened;
            }
        }
    }

    /**
     * The pointer resting on a tip trigger opens its tip after a moment, like a tooltip, unless
     * another tip was opened on purpose (a click or the keyboard). Moving back into a tip or its
     * trigger keeps a hover-opened tip open.
     *
     * @param {MouseEvent} e
     */
    hoverIn(e) {
        const wrap = e.target.closest('.ma-tip-wrap');
        if (!wrap) {
            return;
        }
        clearTimeout(this.leaveTimer);
        const trigger = e.target.closest('[data-action="tip"]');
        if (!trigger || trigger.getAttribute('aria-expanded') === 'true' || this.hoverTrigger === trigger) {
            return;
        }
        this.cancelHover();
        this.hoverTrigger = trigger;
        this.hoverTimer = setTimeout(() => {
            this.hoverTrigger = null;
            const pinned = this.root.querySelector('[data-action="tip"][aria-expanded="true"]:not([data-opened="hover"])');
            if (!pinned && trigger.isConnected && trigger.getAttribute('aria-expanded') !== 'true') {
                this.closeTips(trigger);
                this.setTip(trigger, true, 'hover');
            }
        }, HOVER_DELAY);
    }

    /**
     * Leaving a trigger cancels its pending hover; leaving a tip a hover opened, and its trigger,
     * closes it after a short grace, so the pointer can cross the gap to a link in it.
     *
     * @param {MouseEvent} e
     */
    hoverOut(e) {
        const wrap = e.target.closest('.ma-tip-wrap');
        if (!wrap || (e.relatedTarget && wrap.contains(e.relatedTarget))) {
            return;
        }
        if (this.hoverTrigger && wrap.contains(this.hoverTrigger)) {
            this.cancelHover();
        }
        const trigger = wrap.querySelector('[data-action="tip"][data-opened="hover"]');
        if (trigger) {
            clearTimeout(this.leaveTimer);
            this.leaveTimer = setTimeout(() => {
                if (trigger.dataset.opened === 'hover') {
                    this.setTip(trigger, false);
                }
            }, HOVER_GRACE);
        }
    }

    /**
     * Cancels a hover that hasn't opened its tip yet.
     */
    cancelHover() {
        clearTimeout(this.hoverTimer);
        this.hoverTrigger = null;
    }

    /**
     * Closes every open tip, except the one a trigger controls.
     *
     * @param {HTMLElement|null} except
     */
    closeTips(except = null) {
        this.root.querySelectorAll('[data-action="tip"][aria-expanded="true"]').forEach(trigger => {
            if (trigger !== except) {
                this.setTip(trigger, false);
            }
        });
    }

    /**
     * Keyboard focus on a tip trigger opens its tip (a mouse click is handled by click()).
     *
     * @param {FocusEvent} e
     */
    focusin(e) {
        const trigger = e.target.closest('[data-action="tip"]');
        if (trigger && trigger.matches(':focus-visible') && trigger !== this.escaped) {
            this.closeTips(trigger);
            this.setTip(trigger, true);
        }
    }

    /**
     * A tip closes when focus leaves both its trigger and the tip itself.
     *
     * @param {FocusEvent} e
     */
    focusout(e) {
        const wrap = e.target.closest('.ma-tip-wrap');
        if (wrap && !(e.relatedTarget && wrap.contains(e.relatedTarget))) {
            const trigger = wrap.querySelector('[data-action="tip"]');
            if (trigger) {
                this.setTip(trigger, false);
            }
        }
    }

    /**
     * Escape closes an open tip and returns focus to its trigger.
     *
     * @param {KeyboardEvent} e
     */
    keydown(e) {
        if (e.key !== 'Escape') {
            return;
        }
        const wrap = e.target.closest('.ma-tip-wrap');
        const trigger = wrap ? wrap.querySelector('[data-action="tip"][aria-expanded="true"]') : null;
        if (!trigger) {
            // Focus is elsewhere (a tip a hover opened): close whatever is open.
            this.cancelHover();
            this.closeTips();
            return;
        }
        this.setTip(trigger, false);
        // Focus goes back to the trigger without reopening the tip.
        this.escaped = trigger;
        trigger.focus();
        this.escaped = null;
        e.preventDefault();
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
            const pref = {duesoon: 'block_le_dashboard_window', marked: 'block_le_dashboard_range'}[box.dataset.box];
            if (pref) {
                setUserPreference(pref, e.target.value).catch(Notification.exception);
            }
            this.apply();
        } else if (action === 'year') {
            setUserPreference('block_le_dashboard_year', e.target.value).catch(Notification.exception);
            this.load('block_le_dashboard_get_view', this.viewKey, this.yearId());
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
        const isrefresh = methodname === 'block_le_dashboard_refresh';
        if (isrefresh && this.refreshing) {
            // A second press while the first is running would only re-index the same again.
            return;
        }
        const seq = ++this.seq;
        this.refreshing = this.refreshing || isrefresh;
        const loading = this.root.querySelector(SELECTORS.loading);
        const status = this.root.querySelector(SELECTORS.status);
        const container = this.root.querySelector(SELECTORS.view);
        // The control that started this, to give focus back to once the view is replaced.
        const focused = document.activeElement;
        const action = focused && this.root.contains(focused) ? focused.dataset.action : null;
        status.textContent = '';
        loading.hidden = false;
        container.setAttribute('aria-busy', 'true');
        this.root.querySelectorAll('[data-action="refresh"]').forEach(button => {
            button.disabled = true;
        });
        try {
            const [result] = Ajax.call([{methodname, args: {view, yearid}}]);
            const response = await result;
            if (seq !== this.seq) {
                // A later tab, year or Refresh has replaced this one.
                return;
            }
            container.innerHTML = response.html;
            localise(this.root).catch(Notification.exception);
            this.root.dataset.view = view;
            let label = '';
            this.root.querySelectorAll('[data-action="switch-view"]').forEach(tab => {
                const active = tab.dataset.view === view;
                tab.classList.toggle('active', active);
                if (active) {
                    tab.setAttribute('aria-current', 'page');
                    label = tab.textContent.trim();
                } else {
                    tab.removeAttribute('aria-current');
                }
            });
            this.expanded.clear();
            this.collapsed.clear();
            const search = this.root.querySelector(SELECTORS.search);
            if (search) {
                search.value = this.search;
            }
            this.apply();
            if (!focused || !focused.isConnected) {
                this.restoreFocus(action);
            }
            // A short message for screen readers, not the whole view.
            status.textContent = isrefresh
                ? await getString('statusrefreshed', 'block_le_dashboard')
                : await getString('statusloaded', 'block_le_dashboard', label || this.viewKey);
        } catch (error) {
            Notification.exception(error);
        } finally {
            if (isrefresh) {
                this.refreshing = false;
            }
            if (seq === this.seq) {
                loading.hidden = true;
                container.removeAttribute('aria-busy');
                this.root.querySelectorAll('[data-action="refresh"]').forEach(button => {
                    button.disabled = false;
                });
            }
        }
    }

    /**
     * Puts focus on the control with the same action in the new view, or on the view
     * itself, when the control that had focus was replaced.
     *
     * @param {string|null} action
     */
    restoreFocus(action) {
        if (!action) {
            return;
        }
        const target = this.root.querySelector(`[data-action="${action}"]:not([hidden])`) || this.viewRoot;
        if (target && document.activeElement !== target) {
            target.focus();
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
