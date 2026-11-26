// The checkout's office / locker picker (COUR-2a, courier-integration-design.md 4.2). Plain JavaScript, no framework, no third-party
// request: it reads only our own two endpoints (GET /api/shipping/settlements and /api/shipping/pickup-points).
//
//   1. a settlement search (an ARIA combobox: type-ahead after a pause, from 2 characters, the oblast shown in every suggestion);
//   2. the points of the chosen settlement as ONE radio list (a filter box over it when it is long);
//   3. the chosen point as a summary with «Промени».
//
// NEVER A DEAD END: the picker is only a convenience over the free-text field. When an endpoint fails, or the customer says they cannot
// find the office, the page falls back to the free-text field exactly as before (`setManual`). What the order stores about a chosen point
// is decided by the server from OUR point id; nothing the picker shows is sent back except that id.
//
// Everything that comes from the API goes on the page with textContent, never as markup.
import { api, el, t } from './storefront-cart.js';

var MIN_CHARS = 2;
var DEBOUNCE_MS = 250;
var FILTER_FROM = 12;
var uid = 0;

/** A text for the filter box: lower case, one space. */
function plain(text) {
    return String(text).toLowerCase().replace(/\s+/g, ' ').trim();
}

/**
 * @param {HTMLElement} host   the empty element the picker builds itself in (inside the checkout form)
 * @param {{onChange: function(): void, onSettlement: function(string): void, onManual: function(boolean): void}} hooks
 */
export function createPickupPicker(host, hooks) {
    uid += 1;

    var base = 'sf-pk' + uid;
    var carrier = null;       // the courier whose points are offered, or null (no picker for this method)
    var kind = null;          // 'office' | 'locker': the customer's own choice, asked of the API as it is
    var key = '';             // what the picker belongs to (method + kind): when it changes everything chosen is dropped
    var manual = false;       // the customer (or a failure) switched to the free-text field
    var settlement = null;    // the chosen settlement item
    var points = [];
    var chosen = null;        // the chosen point
    var items = [];           // the suggestions on screen
    var activeIndex = -1;
    var searchSeq = 0;
    var pointsSeq = 0;
    var timer = null;

    // ------------------------------------------------------------------------------------------ markup

    var wrap = el('div', 'sf-picker');

    var searchField = el('div', 'sf-field sf-float');
    var searchLabel = el('label', '', t('ck_pick_settlement'));
    var search = el('input');
    var list = el('ul', 'sf-picker-list');
    var status = el('p', 'sf-field-help');
    var idInput = el('input');

    search.setAttribute('id', base + '-q');
    searchLabel.setAttribute('for', base + '-q');
    search.setAttribute('type', 'text');
    search.setAttribute('role', 'combobox');
    search.setAttribute('autocomplete', 'off');
    search.setAttribute('maxlength', '60');
    search.setAttribute('placeholder', ' ');
    search.setAttribute('aria-autocomplete', 'list');
    search.setAttribute('aria-expanded', 'false');
    search.setAttribute('aria-controls', base + '-list');
    search.setAttribute('aria-describedby', base + '-status');
    list.setAttribute('id', base + '-list');
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', t('ck_pick_settlement'));
    list.hidden = true;
    status.setAttribute('id', base + '-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    // the ONE value that goes to the server: OUR id of the chosen point ('' = none)
    idInput.setAttribute('type', 'hidden');
    idInput.setAttribute('name', 'pickup_point_id');
    idInput.setAttribute('id', 'sf-pickup-point-id');
    idInput.value = '';

    searchField.appendChild(searchLabel);
    searchField.appendChild(search);
    searchField.appendChild(list);

    var pointsBox = el('fieldset', 'sf-picker-points');
    var legend = el('legend', 'sf-picker-legend');
    var filterField = el('div', 'sf-field sf-float');
    var filterLabel = el('label', '', t('ck_pick_filter'));
    var filter = el('input');
    var radios = el('div', 'sf-choice-stack sf-picker-radios');

    filter.setAttribute('id', base + '-f');
    filterLabel.setAttribute('for', base + '-f');
    filter.setAttribute('type', 'text');
    filter.setAttribute('autocomplete', 'off');
    filter.setAttribute('maxlength', '60');
    filter.setAttribute('placeholder', ' ');
    filterField.appendChild(filterLabel);
    filterField.appendChild(filter);
    filterField.hidden = true;
    pointsBox.hidden = true;
    pointsBox.appendChild(legend);
    pointsBox.appendChild(filterField);
    pointsBox.appendChild(radios);

    var summary = el('div', 'sf-picker-summary');
    var summaryText = el('p', 'sf-picker-summary-text');
    var change = el('button', 'sf-link-button', t('ck_pick_change'));

    summaryText.setAttribute('aria-live', 'polite');
    summary.setAttribute('data-sf-picker-summary', '');
    summaryText.setAttribute('data-sf-picker-summary-text', '');
    change.setAttribute('data-sf-picker-change', '');
    change.setAttribute('type', 'button');
    summary.hidden = true;
    summary.appendChild(summaryText);
    summary.appendChild(change);

    var manualButton = el('button', 'sf-link-button sf-picker-manual', t('ck_pick_manual'));
    var backButton = el('button', 'sf-link-button sf-picker-back', t('ck_pick_back'));
    var errorLine = el('p', 'sf-field-error');

    manualButton.setAttribute('type', 'button');
    backButton.setAttribute('type', 'button');
    errorLine.setAttribute('id', 'sf-err-pickup_point_id');
    errorLine.setAttribute('data-sf-error-for', 'pickup_point_id');
    errorLine.hidden = true;

    wrap.appendChild(searchField);
    wrap.appendChild(status);
    wrap.appendChild(pointsBox);
    wrap.appendChild(summary);
    wrap.appendChild(errorLine);
    wrap.appendChild(manualButton);
    wrap.appendChild(idInput);
    host.appendChild(wrap);
    host.hidden = true;

    // ------------------------------------------------------------------------------------------ state

    function say(text) {
        status.textContent = text;
    }

    function notify() {
        hooks.onChange();
    }

    function clearPoint(silent) {
        var had = chosen !== null;

        chosen = null;
        idInput.value = '';
        summary.hidden = true;
        summaryText.textContent = '';

        if (had && ! silent) {
            notify();
        }
    }

    function closeList() {
        list.hidden = true;
        search.setAttribute('aria-expanded', 'false');
        search.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    }

    function resetAll() {
        window.clearTimeout(timer);
        searchSeq += 1;
        pointsSeq += 1;
        settlement = null;
        points = [];
        items = [];
        clear(list);
        closeList();
        clear(radios);
        filter.value = '';
        filterField.hidden = true;
        pointsBox.hidden = true;
        search.value = '';
        searchField.hidden = false;
        say('');
        clearPoint(true);
        errorLine.hidden = true;
        errorLine.textContent = '';
    }

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    var manualNote = '';

    function failed() {
        // the list could not be read: the free-text field takes over, and says why
        manualNote = t('ck_pick_failed');
        setManual(true);
    }

    function setManual(on) {
        manual = on;
        wrap.hidden = on;
        manualNote = on ? manualNote : '';
        hooks.onManual(on, manualNote);
    }

    // ------------------------------------------------------------------------------------------ settlement search

    function renderSuggestions() {
        clear(list);

        items.forEach(function (item, index) {
            var option = el('li', 'sf-picker-option');
            var main = el('span', 'sf-picker-option-name', item.name);
            var more = [];

            option.setAttribute('id', base + '-o' + index);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.appendChild(main);

            if (item.region) {
                more.push(t('ck_pick_region', { region: item.region }));
            }

            if (item.is_split && item.postcode) {
                more.push(item.postcode);
            }

            if (more.length > 0) {
                option.appendChild(el('span', 'sf-picker-option-more', ' (' + more.join(', ') + ')'));
            }

            // mousedown, not click: the input must not lose focus (and close the list) before the choice is read
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
                chooseSettlement(item);
            });
            list.appendChild(option);
        });

        var open = items.length > 0;

        list.hidden = ! open;
        search.setAttribute('aria-expanded', open ? 'true' : 'false');
        setActive(-1);
    }

    function setActive(index) {
        var options = list.children;

        activeIndex = index;

        for (var i = 0; i < options.length; i++) {
            options[i].setAttribute('aria-selected', i === index ? 'true' : 'false');
            options[i].className = i === index ? 'sf-picker-option sf-picker-option-active' : 'sf-picker-option';
        }

        if (index >= 0 && options[index]) {
            search.setAttribute('aria-activedescendant', options[index].getAttribute('id'));

            if (typeof options[index].scrollIntoView === 'function') {
                options[index].scrollIntoView({ block: 'nearest' });
            }
        } else {
            search.removeAttribute('aria-activedescendant');
        }
    }

    async function runSearch() {
        var q = search.value.trim();
        var mine = ++searchSeq;

        if (q.length < MIN_CHARS) {
            items = [];
            renderSuggestions();
            say('');

            return;
        }

        say(t('ck_pick_loading'));

        var result;

        try {
            result = await api('GET', '/api/shipping/settlements?carrier=' + encodeURIComponent(carrier) + '&kind=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(q));
        } catch (e) {
            result = null;
        }

        if (mine !== searchSeq) {
            return; // an older answer: the customer has typed on
        }

        if (result === null || result.status < 200 || result.status >= 300 || ! result.data || ! Array.isArray(result.data.data)) {
            failed();

            return;
        }

        items = result.data.data;
        renderSuggestions();
        say(items.length === 0 ? t('ck_pick_no_match') : '');
    }

    function chooseSettlement(item) {
        settlement = item;
        clearPoint(false);
        search.value = item.name;
        items = [];
        closeList();
        renderSuggestions();
        say('');
        hooks.onSettlement(item.name);
        loadPoints();
    }

    // ------------------------------------------------------------------------------------------ the points

    async function loadPoints() {
        var mine = ++pointsSeq;

        clear(radios);
        pointsBox.hidden = true;
        say(t('ck_pick_loading'));

        var result;

        try {
            result = await api('GET', '/api/shipping/pickup-points?carrier=' + encodeURIComponent(carrier) + '&kind=' + encodeURIComponent(kind) + '&settlement=' + encodeURIComponent(settlement.id));
        } catch (e) {
            result = null;
        }

        if (mine !== pointsSeq) {
            return;
        }

        if (result === null || result.status < 200 || result.status >= 300 || ! result.data || ! Array.isArray(result.data.data)) {
            failed();

            return;
        }

        points = result.data.data;
        say(points.length === 0 ? t('ck_pick_none') : '');
        renderPoints();
    }

    function renderPoints() {
        var needle = plain(filter.value);
        var mixed = points.some(function (point) { return point.kind !== points[0].kind; });

        clear(radios);
        legend.textContent = t(kind === 'locker' ? 'ck_pick_legend_locker' : 'ck_pick_legend_office');
        filterField.hidden = points.length < FILTER_FROM;
        pointsBox.hidden = points.length === 0;

        points.forEach(function (point) {
            if (needle !== '' && plain(point.name + ' ' + point.address).indexOf(needle) === -1) {
                return;
            }

            var label = el('label', 'sf-choice');
            var radio = el('input');
            var text = el('span', 'sf-picker-point');

            radio.setAttribute('type', 'radio');
            radio.setAttribute('name', base + '-point');
            radio.setAttribute('value', String(point.id));
            radio.checked = chosen !== null && chosen.id === point.id;
            radio.addEventListener('change', function () {
                choosePoint(point);
            });

            text.appendChild(el('strong', 'sf-picker-point-name', point.name));

            if (mixed) {
                text.appendChild(el('span', 'sf-picker-badge', t(point.kind === 'locker' ? 'ck_pick_kind_locker' : 'ck_pick_kind_office')));
            }

            text.appendChild(el('span', 'sf-picker-point-address', point.address + (point.postcode ? ', ' + point.postcode : '')));
            label.appendChild(radio);
            label.appendChild(text);
            radios.appendChild(label);
        });
    }

    function choosePoint(point) {
        chosen = point;
        idInput.value = String(point.id);
        pointsBox.hidden = true;
        searchField.hidden = true;
        summaryText.textContent = t('ck_pick_chosen', { name: point.name, address: point.address });
        summary.hidden = false;
        errorLine.hidden = true;
        errorLine.textContent = '';
        notify();
        change.focus();
    }

    function changePoint() {
        clearPoint(false);
        searchField.hidden = false;
        pointsBox.hidden = points.length === 0;
        renderPoints();

        var first = radios.querySelector('input');

        if (first) {
            first.focus();
        } else {
            search.focus();
        }
    }

    // ------------------------------------------------------------------------------------------ events

    search.addEventListener('input', function () {
        // a new text means a new settlement: what was chosen under the old one is dropped
        if (settlement !== null) {
            settlement = null;
            points = [];
            pointsSeq += 1;
            clear(radios);
            pointsBox.hidden = true;
            clearPoint(false);
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(runSearch, DEBOUNCE_MS);
    });

    search.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (items.length === 0) {
                return;
            }

            event.preventDefault();

            var next = event.key === 'ArrowDown' ? activeIndex + 1 : activeIndex - 1;

            setActive((next + items.length) % items.length);
        } else if (event.key === 'Enter') {
            // never submits the checkout form; with a suggestion highlighted it is the choice
            event.preventDefault();

            if (activeIndex >= 0 && items[activeIndex]) {
                chooseSettlement(items[activeIndex]);
            } else if (items.length === 1) {
                chooseSettlement(items[0]);
            }
        } else if (event.key === 'Escape') {
            if (! list.hidden) {
                event.preventDefault();
                closeList();
            }
        }
    });

    search.addEventListener('blur', function () {
        // after the mousedown of an option has been handled
        window.setTimeout(closeList, 100);
    });

    filter.addEventListener('input', renderPoints);
    filter.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
    });
    change.addEventListener('click', changePoint);
    manualButton.addEventListener('click', function () {
        manualNote = '';
        setManual(true);
    });
    backButton.addEventListener('click', function () {
        setManual(false);
        search.focus();
    });

    // ------------------------------------------------------------------------------------------ the page's side

    return {
        /** The button that returns from the free-text field to the list; the page puts it where the free-text field is. */
        backButton: backButton,

        /**
         * @param {?string} courier  the courier whose points the method offers, or null: no picker for this method
         * @param {?string} wantedKind  'office' | 'locker'
         * @param {string} identity  method + kind: a change drops everything chosen
         * @param {string} hint  the place the customer typed above, offered as the starting text
         */
        configure: function (courier, wantedKind, identity, hint) {
            var changed = identity !== key || courier !== carrier;

            carrier = courier;
            kind = wantedKind;

            if (courier === null) {
                key = '';
                resetAll();
                manual = false;
                host.hidden = true;

                return;
            }

            if (changed) {
                key = identity;
                resetAll();
                manual = false;
                wrap.hidden = false;

                if (typeof hint === 'string' && hint.trim() !== '') {
                    search.value = hint.trim();
                }
            }

            host.hidden = false;
        },

        /** The picker (not the free-text field) is what the customer fills in now. */
        active: function () {
            return carrier !== null && ! manual;
        },

        /** The method has a picker, whether or not the customer switched to the free-text field. */
        available: function () {
            return carrier !== null;
        },

        chosenId: function () {
            return idInput.value;
        },

        /** The server refused the chosen point: forget it and show the list again. */
        invalidate: function (text) {
            clearPoint(true);
            searchField.hidden = false;
            // the page re-checks its button first (it also clears this field's old error), then the message is put back
            notify();
            errorLine.textContent = text;
            errorLine.hidden = false;

            if (settlement !== null) {
                loadPoints();
            }
        },
    };
}
