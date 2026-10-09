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
 * Row dates in the viewer's own time zone (Pierre, 2026-10-09). Moodle draws them in the user's Moodle time
 * zone; where the browser's zone puts a date at a different clock time, the date shows local time and gains
 * a globe hint giving the time Moodle drew. Without JavaScript the dates stay as Moodle drew them.
 *
 * @module     block_le_dashboard/localtime
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import Templates from 'core/templates';
import {getStrings} from 'core/str';

/** Date keys that are deadlines; the rest are plain dates (feedback expected, feedback received). */
const DEADLINES = ['due', 'extendeddeadline', 'submissiondeadline', 'markingdue'];

/** The globe for each part of the world, by the zone's first part; anything else gets fa-globe. */
const GLOBES = {
    America: 'fa-earth-americas',
    Europe: 'fa-earth-europe',
    Africa: 'fa-earth-africa',
    Asia: 'fa-earth-asia',
    Australia: 'fa-earth-oceania',
    Pacific: 'fa-earth-oceania',
};

/**
 * The browser's time zone, or null when it can't say.
 *
 * @returns {string|null}
 */
const browserZone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
    } catch (e) {
        return null;
    }
};

/**
 * A time as the block's strftimedue draws it ("%d %b %Y, %H:%M"), in a time zone.
 *
 * @param {Date} date
 * @param {string} zone
 * @param {string} lang
 * @returns {string}
 */
const format = (date, zone, lang) => {
    const parts = {};
    new Intl.DateTimeFormat(lang, {
        timeZone: zone, day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date).forEach(part => {
        parts[part.type] = part.value;
    });
    return `${parts.day} ${parts.month} ${parts.year}, ${parts.hour}:${parts.minute}`;
};

/** A day, in milliseconds. */
const DAY = 86400000;

/**
 * A time's calendar date in a time zone, as a number that counts days (midnight UTC of that date).
 *
 * @param {Date} date
 * @param {string} zone
 * @returns {number}
 */
const day = (date, zone) => {
    const parts = {};
    new Intl.DateTimeFormat('en-GB', {timeZone: zone, year: 'numeric', month: 'numeric', day: 'numeric'})
        .formatToParts(date).forEach(part => {
            parts[part.type] = part.value;
        });
    return Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day));
};

/**
 * Shows the dates under a root in the browser's time zone, where it differs from Moodle's: the row dates, dates
 * inside sentences, and the day counts of "Due in" notes, so they agree with the dates shown (Pierre, 2026-10-09:
 * "all times, calculations, in N days, all of it should look like the server was in china").
 *
 * @param {HTMLElement} root The dashboard, holding data-timezone (the zone Moodle drew dates in).
 */
export const localise = async(root) => {
    const local = browserZone();
    const moodle = root.dataset.timezone;
    if (!local || !moodle || local === moodle) {
        return;
    }
    const pending = new Pending('block_le_dashboard/localtime');
    try {
        await Promise.all([dates(root, local, moodle), intext(root, local, moodle), countdowns(root, local)]);
    } finally {
        pending.resolve();
    }
};

/**
 * Dates inside sentences (a Mitigate reason, a staff hint, "Updated ..."): local time, with Moodle's in their title.
 *
 * @param {HTMLElement} root
 * @param {string} local The browser's zone.
 * @param {string} moodle Moodle's zone.
 */
const intext = async(root, local, moodle) => {
    const lang = document.documentElement.lang || undefined;
    const found = [];
    root.querySelectorAll('time[data-intext][datetime]:not([data-localised])').forEach(time => {
        const date = new Date(time.getAttribute('datetime'));
        try {
            const ours = format(date, local, lang);
            if (!isNaN(date.getTime()) && ours !== format(date, moodle, lang)) {
                found.push({time, ours});
            }
        } catch (e) {
            // A zone this browser doesn't know.
        }
    });
    if (!found.length) {
        return;
    }
    const titles = await getStrings(found.map(({time}) => ({
        key: 'tz_date_moodle', component: 'block_le_dashboard', param: {zone: moodle, time: time.textContent.trim()},
    })));
    found.forEach(({time, ours}, i) => {
        time.dataset.localised = '1';
        time.title = titles[i];
        time.textContent = ours;
    });
};

/**
 * "Due in 2 days", "Due tomorrow": from a day out the count is calendar days to the deadline's date, so it's
 * recounted on the dates of the browser's zone. Under a day it's the time left, the same everywhere.
 *
 * @param {HTMLElement} root
 * @param {string} local The browser's zone.
 */
const countdowns = async(root, local) => {
    const found = [];
    root.querySelectorAll('.ma-row-note[data-until][data-now]:not([data-localised])').forEach(note => {
        const until = new Date(note.dataset.until);
        const now = new Date(note.dataset.now);
        if (isNaN(until.getTime()) || isNaN(now.getTime()) || until - now < DAY) {
            return;
        }
        try {
            found.push({note, days: Math.max(1, Math.round((day(until, local) - day(now, local)) / DAY))});
        } catch (e) {
            // A zone this browser doesn't know.
        }
    });
    if (!found.length) {
        return;
    }
    const texts = await getStrings(found.map(({note, days}) => days === 1
        ? {key: `${note.dataset.note}_tomorrow`, component: 'block_le_dashboard'}
        : {key: 'duration_days', component: 'block_le_dashboard', param: days}));
    const sentences = await getStrings(found.map(({note, days}, i) => days === 1
        ? {key: `${note.dataset.note}_tomorrow`, component: 'block_le_dashboard'}
        : {key: note.dataset.note, component: 'block_le_dashboard', param: texts[i]}));
    found.forEach(({note}, i) => {
        note.dataset.localised = '1';
        note.textContent = sentences[i];
    });
};

/**
 * The row dates: local time, each with a globe hint giving Moodle's.
 *
 * @param {HTMLElement} root
 * @param {string} local The browser's zone.
 * @param {string} moodle Moodle's zone.
 */
const dates = async(root, local, moodle) => {
    const lang = document.documentElement.lang || undefined;
    const found = [];
    root.querySelectorAll('[data-date] > time[datetime]:not([data-localised])').forEach(time => {
        const date = new Date(time.getAttribute('datetime'));
        if (isNaN(date.getTime())) {
            return;
        }
        let ours, theirs;
        try {
            ours = format(date, local, lang);
            theirs = format(date, moodle, lang);
        } catch (e) {
            // A zone this browser doesn't know.
            return;
        }
        if (ours !== theirs) {
            found.push({time, ours});
        }
    });
    if (found.length) {
        await show(root, found, local, moodle);
    }
};

/**
 * Puts local times in place, each with its globe hint.
 *
 * @param {HTMLElement} root
 * @param {Array} dates The time elements and their local text.
 * @param {string} local The browser's zone.
 * @param {string} moodle Moodle's zone.
 */
const show = async(root, dates, local, moodle) => {
    const strings = await getStrings([
        {key: 'tz_deadline', component: 'block_le_dashboard', param: local},
        {key: 'tz_date', component: 'block_le_dashboard', param: local},
    ]);
    const original = await getStrings(dates.map(({time}) => ({
        key: DEADLINES.includes(time.parentNode.dataset.date) ? 'tz_deadline_moodle' : 'tz_date_moodle',
        component: 'block_le_dashboard',
        param: {zone: moodle, time: time.textContent.trim()},
    })));
    const icon = GLOBES[local.split('/')[0]] || 'fa-globe';
    await Promise.all(dates.map(async({time, ours}, i) => {
        const key = time.parentNode.dataset.date;
        const row = time.closest('[data-region="ma-row"]');
        const {html} = await Templates.renderForPromise('block_le_dashboard/hint', {
            id: `${row ? row.id : root.id}-tz-${key}`,
            key: 'timezone',
            icon,
            tone: 'grey',
            glyph: true,
            title: strings[DEADLINES.includes(key) ? 0 : 1],
            text: original[i],
        });
        time.dataset.localised = '1';
        time.textContent = ours;
        time.insertAdjacentHTML('afterend', html);
    }));
};
