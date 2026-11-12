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
 * The hint popovers ("tips"): one icon that opens its text on a click, keyboard focus or a short hover,
 * closes on Escape or a click elsewhere, and stays on screen at every width. Shared by every dashboard
 * that renders block_le_dashboard/hint (My assessments, the Hub dashboard), so the behaviour is written once.
 *
 * Every hook is a data- attribute, never a styling class.
 *
 * @module     block_le_dashboard/tips
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Milliseconds the pointer rests on a tip trigger before its tip opens, as a tooltip does. */
const HOVER_DELAY = 500;

/** Milliseconds a hover-opened tip stays after the pointer leaves, to reach a link in it. */
const HOVER_GRACE = 200;

/** Pixels an open tip keeps from the window's edges. */
const EDGE = 8;

const TRIGGER = '[data-action="tip"]';
const WRAP = '.ma-tip-wrap';

/**
 * Slides an open tip sideways back into the window when its anchor would put it past an edge, so it
 * stays next to its control at every width (beta23 anchoring check).
 *
 * @param {HTMLElement} tip
 */
export const keepOnScreen = (tip) => {
    const box = tip.getBoundingClientRect();
    const width = document.documentElement.clientWidth;
    let shift = 0;
    if (box.right > width - EDGE) {
        shift = width - EDGE - box.right;
    }
    if (box.left + shift < EDGE) {
        shift = EDGE - box.left;
    }
    if (shift) {
        tip.style.transform = `translateX(${Math.round(shift)}px)`;
    }
};

/**
 * The tips of one root element.
 */
export class Tips {
    /**
     * Attaches the behaviour to a root. A click on a trigger is handled by click(), which the root's own click
     * handler calls, so one delegated listener serves every action.
     *
     * @param {HTMLElement} root
     */
    constructor(root) {
        this.root = root;
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
            if (!e.target.closest || !e.target.closest(WRAP)) {
                this.closeTips();
            }
        });
    }

    /**
     * A click on a trigger: a tip a hover opened stays open; otherwise it toggles.
     *
     * @param {HTMLElement} trigger
     */
    click(trigger) {
        this.cancelHover();
        const open = trigger.dataset.opened === 'hover' || trigger.getAttribute('aria-expanded') !== 'true';
        this.closeTips(trigger);
        this.setTip(trigger, open);
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
            tip.style.transform = '';
            tip.hidden = !open;
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                trigger.dataset.opened = how;
                keepOnScreen(tip);
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
        const wrap = e.target.closest(WRAP);
        // Off, a tip opens only on click or keyboard focus (beta27 hints audit).
        if (!wrap || this.root.dataset.nohover) {
            return;
        }
        clearTimeout(this.leaveTimer);
        const trigger = e.target.closest(TRIGGER);
        if (!trigger || trigger.getAttribute('aria-expanded') === 'true' || this.hoverTrigger === trigger) {
            return;
        }
        this.cancelHover();
        this.hoverTrigger = trigger;
        this.hoverTimer = setTimeout(() => {
            this.hoverTrigger = null;
            const pinned = this.root.querySelector(`${TRIGGER}[aria-expanded="true"]:not([data-opened="hover"])`);
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
        const wrap = e.target.closest(WRAP);
        if (!wrap || (e.relatedTarget && wrap.contains(e.relatedTarget))) {
            return;
        }
        if (this.hoverTrigger && wrap.contains(this.hoverTrigger)) {
            this.cancelHover();
        }
        const trigger = wrap.querySelector(`${TRIGGER}[data-opened="hover"]`);
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
        this.root.querySelectorAll(`${TRIGGER}[aria-expanded="true"]`).forEach(trigger => {
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
        const trigger = e.target.closest(TRIGGER);
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
        const wrap = e.target.closest(WRAP);
        if (wrap && !(e.relatedTarget && wrap.contains(e.relatedTarget))) {
            const trigger = wrap.querySelector(TRIGGER);
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
        const wrap = e.target.closest(WRAP);
        const trigger = wrap ? wrap.querySelector(`${TRIGGER}[aria-expanded="true"]`) : null;
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
}
