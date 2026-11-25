// CC-1 (cookie-consent.md): the cookie / ads consent banner. Plain JavaScript, no framework, no dependency.
//
// The banner is part of the SAME cached HTML for every visitor; ONLY this script reads the cookie `easyco_consent` and decides whether to show it. The server never reads it.
//   cookie value: v=<text_version>&ads=<0|1>&t=<unix seconds>   Path=/; SameSite=Lax; Secure on https; NOT HttpOnly; Max-Age = months * 30 days
//   It is written ONLY on a click (accept all, only necessary, save). A cookie with another `v`, a damaged value or none = no choice yet: the banner shows, `ads` is NOT granted.
//
// Public API (nothing else is exposed; there is no script loader and no pixel here):
//   window.EasyCoConsent = { get(), has(category), open(), onChange(fn) }
//   document event `easyco:consent`, detail {ads, changed}: fired when a choice is made (changed: true) and once on load when a valid choice already exists (changed: false)
//
// CC-2: three buttons of equal weight (accept all, reject = the same save as «settings» with everything off, settings), a «×» (closes WITHOUT a choice: nothing is saved, no storage is written, the banner is
// back on the next page) and Escape = the «×». On the policy page (the root carries `data-policy-page`, the same for every visitor of that URL) a visitor with no choice sees a thin NON-modal bar at the bottom
// (no focus trap, no scroll lock, no inert page) and the page gets the bar's height as bottom padding; «Настройки» turns it into the normal dialog.
//
// INVARIANT: before a choice, after «Отказвам» and after «×», nothing optional is set or loaded: this file only reads and writes its own cookie, and only from decide().

const COOKIE = 'easyco_consent';
const CATEGORIES = ['ads'];

function init() {
    const root = document.querySelector('[data-sf-cc]');

    if (root === null || window.EasyCoConsent !== undefined) {
        return;
    }

    const version = String(root.dataset.version ?? '1');
    const months = Math.min(13, Math.max(1, parseInt(root.dataset.months ?? '12', 10) || 12));
    const initialView = root.dataset.view === 'details' ? 'details' : 'summary';
    const modal = root.dataset.modal === '1';
    const policyPage = root.dataset.policyPage === '1';
    const dialog = root.querySelector('[data-sf-cc-dialog]');
    const bar = document.querySelector('[data-sf-cc-bar]');
    const layers = { summary: root.querySelector('[data-sf-cc-layer="summary"]'), details: root.querySelector('[data-sf-cc-layer="details"]') };
    const back = root.querySelector('[data-sf-cc-action="back"]');
    const switches = [...root.querySelectorAll('[data-sf-cc-switch]')];
    const listeners = [];
    let choice = readCookie();
    let layer = 'summary';
    let returnFocus = null;
    let inerted = [];
    let barMode = false;
    let barObserver = null;

    // ---- the cookie ------------------------------------------------------------------------------------------------------------------

    function readCookie() {
        for (const part of document.cookie.split(';')) {
            const index = part.indexOf('=');

            if (index === -1 || part.slice(0, index).trim() !== COOKIE) {
                continue;
            }

            let raw = part.slice(index + 1).trim();

            try {
                raw = decodeURIComponent(raw);
            } catch (e) {
                return null;
            }

            const params = new URLSearchParams(raw);
            const ads = params.get('ads');
            const time = params.get('t');

            if (params.get('v') !== version || (ads !== '0' && ads !== '1') || time === null || ! /^\d{1,12}$/.test(time)) {
                return null;
            }

            return { version, ads: ads === '1', time: parseInt(time, 10) };
        }

        return null;
    }

    function writeCookie(ads) {
        const time = Math.floor(Date.now() / 1000);
        const secure = window.location.protocol === 'https:' ? '; Secure' : '';

        document.cookie = `${COOKIE}=v=${version}&ads=${ads ? 1 : 0}&t=${time}; Path=/; Max-Age=${months * 30 * 86400}; SameSite=Lax${secure}`;

        return { version, ads, time };
    }

    // ---- the banner ------------------------------------------------------------------------------------------------------------------

    function showLayer(name, withBack) {
        layer = name;
        layers.summary.hidden = name !== 'summary';
        layers.details.hidden = name !== 'details';
        back.hidden = ! (name === 'details' && withBack);
        dialog.setAttribute('aria-labelledby', `sf-cc-title-${name}`);

        // the switch shows the CURRENT choice (off when there is none)
        for (const toggle of switches) {
            toggle.setAttribute('aria-checked', choice !== null && choice[toggle.dataset.sfCcSwitch] === true ? 'true' : 'false');
        }
    }

    function isModal() {
        return modal && ! barMode;
    }

    // the thin bar on the policy page: not modal; the page keeps the bar's height as bottom padding so its last lines stay readable
    function setBar(on) {
        barMode = on;
        root.classList.toggle('sf-cc-is-bar', on);
        on ? dialog.setAttribute('aria-modal', 'false') : (modal ? dialog.setAttribute('aria-modal', 'true') : dialog.removeAttribute('aria-modal'));

        const html = document.documentElement;
        barObserver !== null && barObserver.disconnect();
        barObserver = null;

        if (! on) {
            html.classList.remove('sf-cc-has-bar');
            html.style.removeProperty('--sf-cc-bar-h');

            return;
        }

        const sync = () => html.style.setProperty('--sf-cc-bar-h', `${Math.ceil(dialog.getBoundingClientRect().height)}px`);

        html.classList.add('sf-cc-has-bar');
        sync();

        if (typeof ResizeObserver === 'function') {
            barObserver = new ResizeObserver(sync);
            barObserver.observe(dialog);
        }
    }

    function lockPage() {
        if (! isModal()) {
            return;
        }

        document.documentElement.classList.add('sf-cc-lock');
        inerted = [...document.body.children].filter((child) => child !== root && ! child.hasAttribute('inert'));
        inerted.forEach((child) => child.setAttribute('inert', ''));
    }

    function unlockPage() {
        document.documentElement.classList.remove('sf-cc-lock');
        inerted.forEach((child) => child.removeAttribute('inert'));
        inerted = [];
    }

    function open(name, withBack, asBar = false) {
        returnFocus = document.activeElement instanceof HTMLElement && ! root.contains(document.activeElement) ? document.activeElement : returnFocus;
        showLayer(name, withBack);
        root.hidden = false;
        setBar(asBar);
        lockPage();

        if (isModal()) {
            // the first choice, not the «×» (it is first in the DOM order only because it sits in the corner)
            const first = visibleFocusables().find((el) => ! el.classList.contains('sf-cc-close')) ?? dialog;
            first.focus();
        }
    }

    function close() {
        root.hidden = true;
        setBar(false);
        unlockPage();

        if (returnFocus !== null && document.contains(returnFocus)) {
            returnFocus.focus();
        }

        returnFocus = null;
    }

    function visibleFocusables() {
        return [...dialog.querySelectorAll('button, a[href]')].filter((el) => ! el.closest('[hidden]') && ! el.disabled);
    }

    function decide(ads) {
        choice = writeCookie(ads);
        close();
        announce(true);
    }

    function announce(changed) {
        const detail = { ads: choice !== null && choice.ads === true, changed };

        document.dispatchEvent(new CustomEvent('easyco:consent', { detail }));
        listeners.forEach((fn) => {
            try {
                fn(detail);
            } catch (e) {
                // a listener's error never breaks the banner
            }
        });
    }

    // ---- events ----------------------------------------------------------------------------------------------------------------------

    document.addEventListener('click', (event) => {
        const control = event.target instanceof Element ? event.target.closest('[data-sf-cc-action], [data-sf-cc-switch], [data-consent-open]') : null;

        if (control === null) {
            return;
        }

        // a menu item «Настройки за бисквитки» (any menu): layer 2 with the current choice and no «← Назад»; without JavaScript its href leads to the policy page
        if (control.hasAttribute('data-consent-open')) {
            event.preventDefault();
            open('details', false);

            return;
        }

        if (! root.contains(control) && ! (bar !== null && bar.contains(control))) {
            return;
        }

        if (control.hasAttribute('data-sf-cc-switch')) {
            control.setAttribute('aria-checked', control.getAttribute('aria-checked') === 'true' ? 'false' : 'true');

            return;
        }

        switch (control.dataset.sfCcAction) {
            case 'accept':
                decide(true);
                break;
            case 'reject':
                decide(false);
                break;
            case 'save':
                decide(switches.some((toggle) => toggle.dataset.sfCcSwitch === 'ads' && toggle.getAttribute('aria-checked') === 'true'));
                break;
            case 'dismiss':
                // «×»: closes without a choice. Nothing is written (no cookie, no storage): the banner is back on the next page
                close();
                break;
            case 'details':
                // from the thin bar the settings are the normal dialog (the layer needs room)
                if (barMode) {
                    setBar(false);
                    lockPage();
                }

                showLayer('details', true);
                isModal() && visibleFocusables().find((el) => ! el.classList.contains('sf-cc-close'))?.focus();
                break;
            case 'back':
                showLayer('summary', false);
                isModal() && visibleFocusables().find((el) => ! el.classList.contains('sf-cc-close'))?.focus();
                break;
            case 'open':
                // the footer link: layer 2 with the current choice and no «← Назад»
                open('details', false);
                break;
        }
    });

    // keyboard: Escape = the «×» (closes without a choice; only while the focus is inside the banner, so the page's own Escape is never taken); Tab stays inside a MODAL dialog (not in the thin bar)
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && ! root.hidden) {
            event.preventDefault();
            close();

            return;
        }

        if (event.key !== 'Tab' || ! isModal() || root.hidden) {
            return;
        }

        const items = visibleFocusables();

        if (items.length === 0) {
            event.preventDefault();

            return;
        }

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
            event.preventDefault();
            last.focus();
        } else if (! event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    // ---- the API -----------------------------------------------------------------------------------------------------------------------

    window.EasyCoConsent = Object.freeze({
        get: () => (choice === null ? null : { ...choice }),
        has: (category) => category === 'necessary' || (CATEGORIES.includes(category) && choice !== null && choice[category] === true),
        open: () => open('details', false),
        onChange: (fn) => {
            typeof fn === 'function' && listeners.push(fn);
        },
    });

    // ---- start -----------------------------------------------------------------------------------------------------------------------

    bar !== null && (bar.hidden = false);

    if (choice === null) {
        open(initialView, false, policyPage && initialView === 'summary');
    } else {
        announce(false);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
