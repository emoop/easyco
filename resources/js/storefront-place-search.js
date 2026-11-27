// The checkout's ONE place field (COUR-2a.4, courier-integration-design.md 4.2). For Bulgaria the page's existing `city` input becomes an ARIA
// combobox over OUR settlements (GET /api/shipping/settlements): type-ahead after a pause, from 2 characters, by name (Cyrillic or Latin) or by
// postcode; every suggestion shows type (when known), name, oblast and postcode, e.g. «Харманли, обл. Хасково (6450)».
//   - for an office / locker of an integrated courier only the places where that courier has offerable points of that kind are offered;
//   - for a street address every settlement is offered.
// Choosing a suggestion hands it to the page (`onChoose`), which fills city, region and postcode once and runs the real quote. Typing again drops
// the choice (`onCleared`). NEVER A DEAD END: «Не намирам населеното си място» (or a failing endpoint) leaves the field a plain text field, exactly as
// before, and the server accepts it as it always did.
//
// COUR-2a.5: a place that is ALREADY in the field (a saved address, the browser's autofill, a restored page) has no resolved settlement either. `resolve`
// runs the same search ONCE for that text and, when exactly one place is a confident match, chooses it as if the customer had; otherwise it guesses
// nothing, opens the list for the typed text and says so. `sync` is the other half: a text changed by the PAGE (not typed) drops a choice that no longer fits.
//
// Everything that comes from the API goes on the page with textContent, never as markup.
import { api, el, t } from './storefront-cart.js';

var MIN_CHARS = 2;
var DEBOUNCE_MS = 250;
var uid = 0;

var LATIN = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
    'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sht',
    'ъ': 'a', 'ь': 'y', 'ю': 'yu', 'я': 'ya',
};

/**
 * The same comparison key the server uses for a place name (App\Support\BgPostcodes::normalise): lower case, a leading «гр.» / «с.» / «град» / «село»
 * dropped, Bulgarian streamlined transliteration, everything but letters and digits removed. «Харманли», «гр. Харманли» and «Harmanli» are one key.
 */
export function placeKey(text) {
    var name = String(text === null || text === undefined ? '' : text).trim().toLowerCase();

    name = name.replace(/^(гр\.|гр\s|с\.|град\s|село\s)\s*/, '');
    name = name.replace(/ия(?=[\s-]|$)/g, 'ia');
    name = name.replace(/[а-я]/g, function (letter) { return LATIN[letter] || ''; });

    return name.replace(/[^a-z0-9]+/g, '');
}

/** A region typed or stored as «област Хасково», «обл. Хасково», «Haskovo oblast»: the name part, as a key. */
function regionKey(text) {
    var region = String(text === null || text === undefined ? '' : text).trim().toLowerCase();

    region = region.replace(/(^|\s)(област|обл\.?|oblast|obl\.?|region|province)(?=\s|$)/g, ' ');

    return placeKey(region);
}

/**
 * The ONE confident match among the settlements found for `text`, or null (never a guess). The name must be the same place name; when several places
 * carry it the postcode decides, then the region. A single place with that name is confident even when the saved postcode is missing.
 *
 * @param {Array<object>} found  items of the settlement search
 * @param {string} text  what the field holds
 * @param {{postcode: string, region: string}} wanted  what the form already holds besides the place
 */
export function confidentMatch(found, text, wanted) {
    var key = placeKey(text);

    if (key === '') {
        return null;
    }

    var named = found.filter(function (item) { return placeKey(item.name) === key; });

    if (named.length === 1) {
        return named[0];
    }

    if (named.length === 0) {
        return null;
    }

    var pool = named;
    var postcode = String(wanted && wanted.postcode ? wanted.postcode : '').trim();

    if (postcode !== '') {
        var byPostcode = pool.filter(function (item) { return String(item.postcode || '') === postcode; });

        if (byPostcode.length === 1) {
            return byPostcode[0];
        }

        pool = byPostcode.length > 1 ? byPostcode : pool;
    }

    var region = regionKey(wanted && wanted.region ? wanted.region : '');

    if (region !== '') {
        var byRegion = pool.filter(function (item) { return item.region && regionKey(item.region) === region; });

        if (byRegion.length === 1) {
            return byRegion[0];
        }
    }

    return null;
}

/**
 * @param {HTMLInputElement} input  the page's `city` input
 * @param {{onChoose: function(object): void, onCleared: function(): void, onManual: function(boolean): void}} hooks
 */
export function createPlaceSearch(input, hooks) {
    uid += 1;

    var base = 'sf-pl' + uid;
    var enabled = false;      // BG and a courier list exists: the field is a combobox
    var carrier = null;       // the courier whose places are asked for (office / locker), or null = every settlement
    var kind = 'address';
    var manual = false;       // «Не намирам населеното си място»: a plain text field again
    var chosen = null;
    var items = [];
    var activeIndex = -1;
    var seq = 0;
    var timer = null;

    var field = input.parentNode;
    var list = el('ul', 'sf-pickup-list');
    var status = el('p', 'sf-field-help');
    var manualButton = el('button', 'sf-link-button sf-pickup-manual', t('ck_place_manual'));
    var backButton = el('button', 'sf-link-button sf-pickup-back', t('ck_place_back'));

    list.setAttribute('id', base + '-list');
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', t('ck_pick_settlement'));
    list.hidden = true;
    status.setAttribute('id', base + '-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    manualButton.setAttribute('type', 'button');
    backButton.setAttribute('type', 'button');
    manualButton.hidden = true;
    backButton.hidden = true;

    field.appendChild(list);
    field.appendChild(status);
    field.appendChild(manualButton);
    field.appendChild(backButton);

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function say(text) {
        status.textContent = text;
    }

    function closeList() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    }

    function engaged() {
        return enabled && ! manual;
    }

    /** Switches the input between a combobox and the plain text field it always was. */
    function applyMode() {
        if (engaged()) {
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-controls', base + '-list');
            input.setAttribute('aria-describedby', base + '-status');
            input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
            input.setAttribute('autocomplete', 'off');
        } else {
            ['role', 'aria-autocomplete', 'aria-controls', 'aria-describedby', 'aria-expanded', 'aria-activedescendant'].forEach(function (name) {
                input.removeAttribute(name);
            });
            input.setAttribute('autocomplete', 'address-level2');
            clear(list);
            closeList();
            items = [];
            say('');
        }

        manualButton.hidden = ! engaged();
        backButton.hidden = ! (enabled && manual);
    }

    function setManual(on, note) {
        manual = on;
        applyMode();
        say(on && note ? note : '');
        hooks.onManual(on);
    }

    function label(item) {
        var text = item.name;

        if (item.type) {
            text = item.type + ' ' + text;
        }

        if (item.region) {
            text += ', ' + t('ck_pick_region', { region: item.region });
        }

        if (item.postcode) {
            text += ' (' + item.postcode + ')';
        }

        return text;
    }

    function setActive(index) {
        var options = list.children;

        activeIndex = index;

        for (var i = 0; i < options.length; i++) {
            options[i].setAttribute('aria-selected', i === index ? 'true' : 'false');
            options[i].className = i === index ? 'sf-pickup-option sf-pickup-option-active' : 'sf-pickup-option';
        }

        if (index >= 0 && options[index]) {
            input.setAttribute('aria-activedescendant', options[index].getAttribute('id'));

            if (typeof options[index].scrollIntoView === 'function') {
                options[index].scrollIntoView({ block: 'nearest' });
            }
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function render() {
        clear(list);

        items.forEach(function (item, index) {
            var option = el('li', 'sf-pickup-option', label(item));

            option.setAttribute('id', base + '-o' + index);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            // mousedown, not click: the input must not lose focus (and close the list) before the choice is read
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
                choose(item);
            });
            list.appendChild(option);
        });

        list.hidden = items.length === 0;
        input.setAttribute('aria-expanded', items.length === 0 ? 'false' : 'true');
        setActive(-1);
    }

    /** «обл. Хасково, 6450»: what the chosen place is, shown under the field (the postcode field itself is not shown for an office or a locker). */
    function summary(item) {
        return [item.region ? t('ck_pick_region', { region: item.region }) : '', item.postcode || ''].filter(Boolean).join(', ');
    }

    /** `keep`: the place was resolved from the text already in the field: the customer's own text stays, only the choice is made. */
    function choose(item, keep) {
        chosen = item;

        if (! keep) {
            input.value = item.name;
        }

        input.setAttribute('data-settlement-id', String(item.id));
        items = [];
        render();
        say(summary(item));
        hooks.onChoose(item, keep === true);
    }

    function forget() {
        chosen = null;
        input.removeAttribute('data-settlement-id');
    }

    function searchUrl(q) {
        return '/api/shipping/settlements?kind=' + encodeURIComponent(kind) + (carrier === null ? '' : '&carrier=' + encodeURIComponent(carrier)) + '&q=' + encodeURIComponent(q);
    }

    async function run() {
        var q = input.value.trim();
        var mine = ++seq;

        if (q.length < MIN_CHARS) {
            items = [];
            render();
            say('');

            return;
        }

        say(t('ck_pick_loading'));

        var result;

        try {
            result = await api('GET', searchUrl(q));
        } catch (e) {
            result = null;
        }

        if (mine !== seq) {
            return; // an older answer: the customer has typed on
        }

        if (result === null || result.status < 200 || result.status >= 300 || ! result.data || ! Array.isArray(result.data.data)) {
            setManual(true, t('ck_place_failed'));

            return;
        }

        items = result.data.data;
        render();
        say(items.length === 0 ? t(carrier === null ? 'ck_place_no_match' : 'ck_pick_no_match') : '');
    }

    input.addEventListener('input', function () {
        if (! engaged()) {
            return;
        }

        // a new text means a new place: what was chosen under the old one is dropped
        if (chosen !== null) {
            forget();
            hooks.onCleared();
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(run, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', function (event) {
        if (! engaged()) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (items.length === 0) {
                return;
            }

            event.preventDefault();
            setActive(((event.key === 'ArrowDown' ? activeIndex + 1 : activeIndex - 1) + items.length) % items.length);
        } else if (event.key === 'Enter') {
            // never submits the checkout form; with a suggestion highlighted it is the choice
            if (! list.hidden) {
                event.preventDefault();

                if (activeIndex >= 0 && items[activeIndex]) {
                    choose(items[activeIndex]);
                } else if (items.length === 1) {
                    choose(items[0]);
                }
            }
        } else if (event.key === 'Escape') {
            if (! list.hidden) {
                event.preventDefault();
                closeList();
            }
        }
    });

    input.addEventListener('blur', function () {
        // after the mousedown of an option has been handled
        window.setTimeout(closeList, 150);
    });

    manualButton.addEventListener('click', function () {
        setManual(true, '');
    });
    backButton.addEventListener('click', function () {
        setManual(false, '');
        input.focus();
    });

    return {
        /**
         * @param {boolean} on  BG and the shop has courier lists: the field is a combobox
         * @param {?string} courier  the courier of the chosen destination when it is an office / locker with a list, else null
         * @param {string} wantedKind  'office' | 'locker' | 'address'
         */
        configure: function (on, courier, wantedKind) {
            var was = enabled;

            enabled = on;
            carrier = courier;
            kind = wantedKind;

            if (was !== on) {
                if (! on) {
                    forget();
                }

                applyMode();
            }
        },

        /** The field is a search (not the plain text field): typing must not quote on every keystroke. */
        engaged: engaged,

        /** The place chosen from the suggestions (or resolved from the text that was already there), or null (typed text, or nothing). */
        chosen: function () {
            return chosen;
        },

        /**
         * The text was changed by the PAGE (a saved address filled in, the address book switched), not typed: a choice that no longer names the same
         * place is dropped, and whatever list was open for the old text is closed. The same place (the same key) keeps its choice.
         */
        sync: function () {
            seq += 1;
            window.clearTimeout(timer);

            if (chosen !== null && placeKey(chosen.name) !== placeKey(input.value)) {
                forget();
                say('');
                hooks.onCleared();
            }

            if (chosen === null) {
                items = [];
                render();
                say('');
            }
        },

        /**
         * Resolves the place that is ALREADY in the field, once (COUR-2a.5). One confident match is chosen as if the customer had chosen it (the text,
         * the postcode and the region the form already holds are kept). Several or none: nothing is guessed, the list opens for the typed text and the
         * hint says what to do. Never throws; a failing endpoint leaves today's plain text field.
         *
         * @param {{postcode: string, region: string}} wanted
         * @returns {Promise<string>} 'resolved' | 'hint' | 'skipped' | 'stale' | 'failed'
         */
        resolve: async function (wanted) {
            var q = input.value.trim();

            if (! engaged() || chosen !== null || q.length < MIN_CHARS) {
                return 'skipped';
            }

            var mine = ++seq;

            window.clearTimeout(timer);
            say(t('ck_pick_loading'));

            var result;

            try {
                result = await api('GET', searchUrl(q));
            } catch (e) {
                result = null;
            }

            if (mine !== seq || chosen !== null || input.value.trim() !== q) {
                return 'stale'; // the customer typed on, or chose, while it ran
            }

            if (result === null || result.status < 200 || result.status >= 300 || ! result.data || ! Array.isArray(result.data.data)) {
                setManual(true, t('ck_place_failed'));

                return 'failed';
            }

            var match = confidentMatch(result.data.data, q, wanted);

            if (match !== null) {
                choose(match, true);

                return 'resolved';
            }

            items = result.data.data;
            render();
            say(t(items.length === 0 ? (carrier === null ? 'ck_place_no_match' : 'ck_pick_no_match') : 'ck_pick_choose_from_list'));

            return 'hint';
        },
    };
}
