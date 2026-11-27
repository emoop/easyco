// The checkout's office / locker list (COUR-2a, COUR-2a.4; courier-integration-design.md 4.2). Plain JavaScript, no framework, no third-party
// request: it reads only our own endpoint GET /api/shipping/pickup-points.
//
// THE PLACE IS NOT ASKED HERE. The customer chooses the place once, in the page's one place field (storefront-place-search.js); the page hands it
// to `setSettlement`. This module then shows:
//   1. the courier's offices / lockers of that place as ONE radio list (a filter box over it when it is long), one choice only;
//   2. the chosen point as a summary with «Промени».
//
// NEVER A DEAD END: the list is only a convenience over the free-text office field. When the endpoint fails, or the customer says they cannot
// find the office, the page falls back to the free-text field exactly as before (`setManual`). What the order stores about a chosen point
// is decided by the server from OUR point id; nothing shown here is sent back except that id.
//
// Everything that comes from the API goes on the page with textContent, never as markup.
import { api, el, t } from './storefront-cart.js';

var FILTER_FROM = 12;
var uid = 0;

/** A text for the filter box: lower case, one space. */
function plain(text) {
    return String(text).toLowerCase().replace(/\s+/g, ' ').trim();
}

/**
 * @param {HTMLElement} host   the empty element the list builds itself in (inside the checkout form)
 * @param {{onChange: function(): void, onManual: function(boolean, string): void}} hooks
 */
export function createPickupPicker(host, hooks) {
    uid += 1;

    var base = 'sf-pk' + uid;
    var carrier = null;       // the courier whose points are offered, or null (no list for this method)
    var kind = null;          // 'office' | 'locker': the destination the CUSTOMER chose, asked of the API as it is
    var key = '';             // what the list belongs to (method + kind): when it changes the chosen point is dropped
    var manual = false;       // the customer (or a failure) switched to the free-text field
    var settlement = null;    // the chosen place (an item of the settlement search), handed in by the page
    var listHint = false;     // COUR-2a.5: the page tried to resolve the place already in its field and could not: the status says to choose it from the list
    var points = [];
    var chosen = null;        // the chosen point
    var pointsSeq = 0;

    // ------------------------------------------------------------------------------------------ markup

    var wrap = el('div', 'sf-pickup');
    var status = el('p', 'sf-field-help');
    var idInput = el('input');

    status.setAttribute('id', base + '-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    // the ONE value that goes to the server: OUR id of the chosen point ('' = none)
    idInput.setAttribute('type', 'hidden');
    idInput.setAttribute('name', 'pickup_point_id');
    idInput.setAttribute('id', 'sf-pickup-point-id');
    idInput.value = '';

    var pointsBox = el('fieldset', 'sf-pickup-points');
    var legend = el('legend', 'sf-pickup-legend');
    var filterField = el('div', 'sf-field sf-float');
    var filterLabel = el('label', '', t('ck_pick_filter'));
    var filter = el('input');
    var radios = el('div', 'sf-choice-stack sf-pickup-radios');

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

    var summary = el('div', 'sf-pickup-summary');
    var summaryText = el('p', 'sf-pickup-summary-text');
    var change = el('button', 'sf-link-button', t('ck_pick_change'));

    summaryText.setAttribute('aria-live', 'polite');
    summary.setAttribute('data-sf-picker-summary', '');
    summaryText.setAttribute('data-sf-picker-summary-text', '');
    change.setAttribute('data-sf-picker-change', '');
    change.setAttribute('type', 'button');
    summary.hidden = true;
    summary.appendChild(summaryText);
    summary.appendChild(change);

    var manualButton = el('button', 'sf-link-button sf-pickup-manual', t('ck_pick_manual'));
    var backButton = el('button', 'sf-link-button sf-pickup-back', t('ck_pick_back'));
    var errorLine = el('p', 'sf-field-error');

    manualButton.setAttribute('type', 'button');
    backButton.setAttribute('type', 'button');
    errorLine.setAttribute('id', 'sf-err-pickup_point_id');
    errorLine.setAttribute('data-sf-error-for', 'pickup_point_id');
    errorLine.hidden = true;

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

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
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

    /** Everything about the place and the point is dropped (a new list context). */
    function resetAll() {
        pointsSeq += 1;
        settlement = null;
        points = [];
        clear(radios);
        filter.value = '';
        filterField.hidden = true;
        pointsBox.hidden = true;
        say('');
        clearPoint(true);
        errorLine.hidden = true;
        errorLine.textContent = '';
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

    /** What the status line says while there is no list: the place first. */
    function askForPlace() {
        say(settlement === null ? t(listHint ? 'ck_pick_choose_from_list' : 'ck_pick_choose_place') : '');
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
        pointsBox.hidden = points.length === 0 || chosen !== null;

        points.forEach(function (point) {
            if (needle !== '' && plain(point.name + ' ' + point.address).indexOf(needle) === -1) {
                return;
            }

            var label = el('label', 'sf-choice');
            var radio = el('input');
            var text = el('span', 'sf-pickup-point');

            radio.setAttribute('type', 'radio');
            radio.setAttribute('name', base + '-point');
            radio.setAttribute('value', String(point.id));
            radio.checked = chosen !== null && chosen.id === point.id;
            radio.addEventListener('change', function () {
                choosePoint(point);
            });

            text.appendChild(el('strong', 'sf-pickup-point-name', point.name));

            if (mixed) {
                text.appendChild(el('span', 'sf-pickup-badge', t(point.kind === 'locker' ? 'ck_pick_kind_locker' : 'ck_pick_kind_office')));
            }

            text.appendChild(el('span', 'sf-pickup-point-address', point.address + (point.postcode ? ', ' + point.postcode : '')));
            label.appendChild(radio);
            label.appendChild(text);
            radios.appendChild(label);
        });
    }

    function choosePoint(point) {
        chosen = point;
        idInput.value = String(point.id);
        pointsBox.hidden = true;
        summaryText.textContent = t('ck_pick_chosen', { name: point.name, address: point.address });
        summary.hidden = false;
        errorLine.hidden = true;
        errorLine.textContent = '';
        notify();
        change.focus();
    }

    function changePoint() {
        clearPoint(false);
        pointsBox.hidden = points.length === 0;
        renderPoints();

        var first = radios.querySelector('input');

        if (first) {
            first.focus();
        }
    }

    // ------------------------------------------------------------------------------------------ events

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

        if (settlement !== null && points.length === 0) {
            loadPoints();
        }
    });

    // ------------------------------------------------------------------------------------------ the page's side

    return {
        /** The button that returns from the free-text field to the list; the page puts it where the free-text field is. */
        backButton: backButton,

        /**
         * @param {?string} courier  the courier whose points the method offers, or null: no list for this method
         * @param {?string} wantedKind  'office' | 'locker', the destination the customer chose
         * @param {string} identity  method + kind: a change drops the chosen point (the place is kept: the page hands it in again)
         */
        configure: function (courier, wantedKind, identity) {
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
                askForPlace();
            }

            host.hidden = false;
        },

        /**
         * The page's place field made a choice (an item of the settlement search) or lost it (null). A new place drops the chosen point;
         * the SAME place changes nothing, so a re-quote never resets the choice.
         */
        setSettlement: function (item) {
            if (carrier === null) {
                return;
            }

            if (item !== null && settlement !== null && String(item.id) === String(settlement.id)) {
                return;
            }

            pointsSeq += 1;
            settlement = item;
            points = [];
            clear(radios);
            pointsBox.hidden = true;
            filter.value = '';
            clearPoint(false);

            if (item === null) {
                askForPlace();

                return;
            }

            if (! manual) {
                loadPoints();
            }
        },

        /** The page could not resolve the place that was already in its field (or has resolved it, or it was edited): the status line says what to do. */
        setPlaceHint: function (on) {
            listHint = on === true;

            if (carrier !== null && settlement === null) {
                askForPlace();
            }
        },

        /** The list (not the free-text field) is what the customer fills in now. */
        active: function () {
            return carrier !== null && ! manual;
        },

        /** The method has a list, whether or not the customer switched to the free-text field. */
        available: function () {
            return carrier !== null;
        },

        /** Switch to the free-text field (the place could not be chosen from the list either). */
        useFreeText: function () {
            if (carrier !== null && ! manual) {
                manualNote = '';
                setManual(true);
            }
        },

        chosenId: function () {
            return idInput.value;
        },

        /** The server refused the chosen point: forget it and show the list again. */
        invalidate: function (text) {
            clearPoint(true);
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
