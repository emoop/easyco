// The checkout's ONE place field (COUR-2a.4, courier-integration-design.md 4.2). For Bulgaria the page's existing `city` input becomes an ARIA
// combobox over OUR settlements (GET /api/shipping/settlements): type-ahead after a pause, from 2 characters, by name (Cyrillic or Latin) or by
// postcode; every suggestion shows type (when known), name, oblast and postcode, e.g. «Харманли, обл. Хасково (6450)».
//   - for an office / locker of an integrated courier only the places where that courier has offerable points of that kind are offered;
//   - for a street address every settlement is offered.
// Choosing a suggestion hands it to the page (`onChoose`), which fills city, region and postcode once and runs the real quote. Typing again drops
// the choice (`onCleared`). NEVER A DEAD END: «Не намирам населеното си място» (or a failing endpoint) leaves the field a plain text field, exactly as
// before, and the server accepts it as it always did.
//
// Everything that comes from the API goes on the page with textContent, never as markup.
import { api, el, t } from './storefront-cart.js';

var MIN_CHARS = 2;
var DEBOUNCE_MS = 250;
var uid = 0;

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

    function choose(item) {
        chosen = item;
        input.value = item.name;
        items = [];
        render();
        say('');
        hooks.onChoose(item);
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

        var url = '/api/shipping/settlements?kind=' + encodeURIComponent(kind) + (carrier === null ? '' : '&carrier=' + encodeURIComponent(carrier)) + '&q=' + encodeURIComponent(q);
        var result;

        try {
            result = await api('GET', url);
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
            chosen = null;
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
                    chosen = null;
                }

                applyMode();
            }
        },

        /** The field is a search (not the plain text field): typing must not quote on every keystroke. */
        engaged: engaged,

        /** The place chosen from the suggestions, or null (typed text, or nothing). */
        chosen: function () {
            return chosen;
        },
    };
}
