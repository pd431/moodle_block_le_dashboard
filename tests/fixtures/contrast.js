// Contrast check for the dashboard's Behat steps (tests/behat/behat_block_le_dashboard.php).
// Evaluated in the browser as one function expression: (check, selector) => failures.
// check "all": every visible text, icon, progress bar and control outline under selector.
// check "focus": the focus indicator of the focused element, against what is behind it.
// check "text:body:90:7" or "text:secondary:75:4.5": text drawn in the block's body (--ma-text) or secondary
// (--ma-text-muted) colour reaches that APCA Lc and ratio. "gap:14": body text is that many Lc brighter than
// secondary text on the block's surface. "underlines": every link underline is 1px, dimmer than its link and
// reaches Lc 45 and 3:1. "underlines-full": every link underline is its link's own colour. "no-underline": no link
// has an underline. "header-lines": no section header has a line under it.
// Targets: text APCA Lc 75 and WCAG 4.5:1; icons, bars, outlines and focus Lc 45 and 3:1.
// APCA is SAPC 0.0.98G-4g, the version the block's targets were set with.
(check, selector) => {
    // Measure the settled look: Moodle 4.5's Bootstrap fades a focus ring in over 0.15s, so a check run straight
    // after Tab reads it near transparent.
    document.getAnimations().filter(a => a instanceof CSSTransition).forEach(a => a.finish());
    const cv =document.createElement('canvas').getContext('2d', {willReadFrequently: true});
    const col = s => {
        cv.clearRect(0, 0, 1, 1);
        cv.fillStyle = '#000';
        cv.fillStyle = s;
        cv.fillRect(0, 0, 1, 1);
        const d = cv.getImageData(0, 0, 1, 1).data;
        return {r: d[0], g: d[1], b: d[2], a: d[3] / 255};
    };
    const blend = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
    const lin = v => (v /= 255) <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    const lum = c => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
    const wcag = (a, b) => (Math.max(lum(a), lum(b)) + 0.05) / (Math.min(lum(a), lum(b)) + 0.05);
    const apca = (t, b) => {
        const y = c => 0.2126729 * Math.pow(c.r / 255, 2.4) + 0.7151522 * Math.pow(c.g / 255, 2.4) + 0.072175 * Math.pow(c.b / 255, 2.4);
        const clamp = v => v < 0.022 ? v + Math.pow(0.022 - v, 1.414) : v;
        const yt = clamp(y(t)), yb = clamp(y(b));
        if (Math.abs(yb - yt) < 0.0005) {
            return 0;
        }
        const s = yb > yt ? (Math.pow(yb, 0.56) - Math.pow(yt, 0.57)) * 1.14 : (Math.pow(yb, 0.65) - Math.pow(yt, 0.62)) * 1.14;
        return Math.abs(s) < 0.1 ? 0 : (Math.abs(s) - 0.027) * 100;
    };
    // The opaque colour behind an element: its own and its ancestors' backgrounds, composited.
    const behind = el => {
        const layers = [];
        for (let e = el; e; e = e.parentElement) {
            const c = col(getComputedStyle(e).backgroundColor);
            if (c.a > 0) {
                layers.push(c);
                if (c.a >= 1) {
                    break;
                }
            }
        }
        let b = {r: 255, g: 255, b: 255, a: 1};
        for (let i = layers.length - 1; i >= 0; i--) {
            b = blend(layers[i], b);
        }
        return b;
    };
    const hidden = el => {
        for (let e = el; e; e = e.parentElement) {
            const cs = getComputedStyle(e);
            if (e.hidden || cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0' ||
                    /\b(accesshide|sr-only|visually-hidden)\b/.test(e.className && e.className.toString())) {
                return true;
            }
        }
        const r = el.getBoundingClientRect();
        return r.width < 2 || r.height < 2;
    };
    const hex = c => '#' + [c.r, c.g, c.b].map(v => Math.round(v).toString(16).padStart(2, '0')).join('');
    const label = el => (el.className && el.className.toString().split(/\s+/).filter(x => x.startsWith('ma-'))[0]) ||
        el.tagName.toLowerCase();
    const failures = [];
    const test = (kind, el, fg, bg, lc, cr) => {
        const l = apca(fg, bg), w = wcag(fg, bg);
        if (l < lc - 0.05 || w < cr - 0.005) {
            const text = (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30);
            failures.push(`${kind} ${label(el)}${text ? ` "${text}"` : ''}: ${hex(fg)} on ${hex(bg)} is Lc ${l.toFixed(1)}, ` +
                `${w.toFixed(2)}:1 (needs Lc ${lc}, ${cr}:1)`);
        }
    };
    const scope = document.querySelector(selector);
    if (!scope) {
        return [`nothing matches ${selector}`];
    }

    // The block's colour for a custom property, as the browser resolves it inside the block's content.
    const token = name => {
        const probe = document.createElement('span');
        probe.style.color = `var(${name})`;
        (scope.querySelector('.block_le_dashboard_dashboard') || scope).appendChild(probe);
        const c = col(getComputedStyle(probe).color);
        probe.remove();
        return c;
    };
    const same = (a, b) => Math.abs(a.r - b.r) + Math.abs(a.g - b.g) + Math.abs(a.b - b.b) < 3;
    const [kind, ...args] = check.split(':');

    if (kind === 'text') {
        const want = token(args[0] === 'body' ? '--ma-text' : '--ma-text-muted');
        const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
        const seen = new Set();
        while (walker.nextNode()) {
            const el = walker.currentNode.parentElement;
            if (!walker.currentNode.textContent.trim() || seen.has(el) || hidden(el)) {
                continue;
            }
            seen.add(el);
            const fg = col(getComputedStyle(el).color);
            if (fg.a >= 1 && same(fg, want)) {
                test(`${args[0]} text`, el, fg, behind(el), +args[1], +args[2]);
            }
        }
        return seen.size ? failures : ['no text in the block'];
    }

    if (kind === 'gap') {
        const surface = token('--ma-surface');
        const body = apca(token('--ma-text'), surface), secondary = apca(token('--ma-text-muted'), surface);
        return body - secondary >= +args[0] - 0.05 ? [] :
            [`body text is Lc ${body.toFixed(1)} and secondary text Lc ${secondary.toFixed(1)} on ${hex(surface)}, ` +
                `${(body - secondary).toFixed(1)} apart (needs ${args[0]})`];
    }

    if (kind === 'underlines' || kind === 'underlines-full') {
        const links = [...(scope.matches('a') ? [scope] : []), ...scope.querySelectorAll('a')].filter(a => !hidden(a) &&
            getComputedStyle(a).textDecorationLine.includes('underline'));
        for (const a of links) {
            const cs = getComputedStyle(a);
            const bg = behind(a);
            const text = blend(col(cs.color), bg);
            const line = blend(col(cs.textDecorationColor), bg);
            const name = `underline of "${a.textContent.trim().slice(0, 30)}"`;
            if (kind === 'underlines-full') {
                if (!same(line, text)) {
                    failures.push(`${name} is ${hex(line)}, not its link's ${hex(text)}`);
                }
                continue;
            }
            if (cs.textDecorationThickness !== '1px') {
                failures.push(`${name} is ${cs.textDecorationThickness} thick, not 1px`);
            }
            if (apca(line, bg) >= apca(text, bg)) {
                failures.push(`${name} (${hex(line)}) is no dimmer than its link (${hex(text)})`);
            }
            test('underline', a, line, bg, 45, 3);
        }
        return links.length ? failures : ['no underlined links in the block'];
    }

    if (kind === 'no-underline') {
        const links = [...(scope.matches('a') ? [scope] : []), ...scope.querySelectorAll('a')].filter(a => !hidden(a));
        for (const a of links) {
            if (getComputedStyle(a).textDecorationLine.includes('underline')) {
                failures.push(`"${a.textContent.trim().slice(0, 30)}" is underlined`);
            }
        }
        return links.length ? failures : ['no link to check'];
    }

    if (kind === 'header-lines') {
        for (const h of scope.querySelectorAll('.ma-section-header')) {
            if (!hidden(h) && parseFloat(getComputedStyle(h).borderBottomWidth) > 0) {
                failures.push(`header "${h.textContent.trim().replace(/\s+/g, ' ').slice(0, 30)}" has a line under it`);
            }
        }
        return failures;
    }

    if (check === 'focus') {
        const el = document.activeElement;
        // The ring is on the element, or on the input group for the search box (:focus-within).
        for (let e = el, i = 0; e && i < 3; e = e.parentElement, i++) {
            const cs = getComputedStyle(e);
            const outside = behind(e.parentElement);
            const shadow = cs.boxShadow !== 'none' && cs.boxShadow.match(/(rgba?|color|oklab|oklch)\([^)]*\)|#[0-9a-f]{3,8}/gi);
            if (shadow) {
                test('focus', el, blend(col(shadow[shadow.length - 1]), outside), outside, 45, 3);
                return failures;
            }
            if (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) {
                test('focus', el, blend(col(cs.outlineColor), outside), outside, 45, 3);
                return failures;
            }
        }
        return [`focus ${label(el)} "${(el.textContent || '').trim().slice(0, 30)}": no focus indicator`];
    }

    const seen = new Set();
    const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const el = walker.currentNode.parentElement;
        if (!walker.currentNode.textContent.trim() || seen.has(el) || hidden(el)) {
            continue;
        }
        seen.add(el);
        const bg = behind(el);
        test('text', el, blend(col(getComputedStyle(el).color), bg), bg, 75, 4.5);
    }
    for (const el of scope.querySelectorAll('i.fa-solid, i.fa, i.icon')) {
        if (!hidden(el)) {
            const bg = behind(el.parentElement);
            test('icon', el, blend(col(getComputedStyle(el).color), bg), bg, 45, 3);
        }
    }
    for (const el of scope.querySelectorAll('.ma-bar-fill')) {
        if (!hidden(el)) {
            const bg = behind(el.parentElement);
            test('bar', el, blend(col(getComputedStyle(el).backgroundColor), bg), bg, 45, 3);
        }
    }
    for (const el of scope.querySelectorAll('.ma-search .form-control, .ma-filter-btn')) {
        if (!hidden(el)) {
            const bg = behind(el.parentElement.parentElement);
            test('outline', el, blend(col(getComputedStyle(el).borderTopColor), bg), bg, 45, 3);
        }
    }
    return failures;
}
