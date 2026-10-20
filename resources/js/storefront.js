// The storefront's only script (stage S2c): about forty lines of plain JavaScript — no framework, no
// imports, no Livewire. It does two things and nothing else: (1) the mobile menu toggle and (2) the
// product gallery's thumbnail swap (and, since D2, the header's drawer, search row and accordion). BOTH are progressive: without this script the pages still work —
// the nav is shown by default and the first gallery image is the main one (see the `.js` rules in
// storefront.css). The file is loaded only from the guarded @vite in
// resources/views/storefront/layout.blade.php, so a checkout without a build gets no script at all.
// S3 adds the cart (add to cart, header count, cart page) in storefront-cart.js, imported below and bundled into this file.
import './storefront-cart.js';
import './storefront-checkout.js';

document.documentElement.classList.add('js');

// ---- the header (D2): the drawer, the search row and the category accordion --------------------------------------------------------------------------------------------
// Plain JavaScript, no dependency. The toggles carry aria-expanded and aria-controls; the drawer traps focus while open, Esc / the overlay / the close button close it and
// focus returns to the toggle that opened it. Without this script the drawer is a plain block under the header and the search row is a plain form (see storefront.css).
var drawer = document.querySelector('[data-sf-drawer]');
var searchRow = document.querySelector('[data-sf-search-row]');
var openedBy = null;
var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

function setExpanded(selector, value) {
    var nodes = document.querySelectorAll(selector);

    for (var i = 0; i < nodes.length; i++) {
        nodes[i].setAttribute('aria-expanded', value ? 'true' : 'false');
    }
}

function openDrawer(from) {
    if (! drawer) {
        return;
    }

    openedBy = from;
    drawer.classList.add('sf-drawer-open');
    document.documentElement.classList.add('sf-locked');
    setExpanded('[data-sf-menu-toggle]', true);
    var panel = drawer.querySelector('.sf-drawer-panel');
    // the close button takes the focus, not the search field above it: a field in focus would raise the phone's keyboard over the menu
    var first = panel ? (panel.querySelector('.sf-drawer-close') || panel.querySelector(FOCUSABLE)) : null;
    (first || panel).focus();
}

function closeDrawer() {
    if (! drawer || ! drawer.classList.contains('sf-drawer-open')) {
        return;
    }

    drawer.classList.remove('sf-drawer-open');
    document.documentElement.classList.remove('sf-locked');
    setExpanded('[data-sf-menu-toggle]', false);

    if (openedBy) {
        openedBy.focus();
    }
}

function toggleSearch(from) {
    if (! searchRow) {
        return;
    }

    var open = searchRow.hasAttribute('hidden');

    if (open) {
        searchRow.removeAttribute('hidden');
        openedBy = from;
        var field = searchRow.querySelector('input');

        if (field) {
            field.focus();
        }
    } else {
        searchRow.setAttribute('hidden', '');

        if (openedBy) {
            openedBy.focus();
        }
    }

    setExpanded('[data-sf-search-toggle]', open);
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        if (openSub) {
            var back = openSub.contains(document.activeElement) ? openSub.querySelector('[data-sf-sub-toggle]') : null;
            setSub(openSub, false);
            back && back.focus();
        } else if (drawer && drawer.classList.contains('sf-drawer-open')) {
            closeDrawer();
        } else if (searchRow && ! searchRow.hasAttribute('hidden')) {
            toggleSearch(openedBy);
        }

        return;
    }

    if (event.key !== 'Tab' || ! drawer || ! drawer.classList.contains('sf-drawer-open')) {
        return;
    }

    // Trap the focus inside the open drawer.
    var items = drawer.querySelectorAll('.sf-drawer-panel ' + FOCUSABLE.split(', ').join(', .sf-drawer-panel '));

    if (items.length === 0) {
        event.preventDefault();

        return;
    }

    var first = items[0];
    var last = items[items.length - 1];

    if (event.shiftKey && (document.activeElement === first || ! drawer.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
    } else if (! event.shiftKey && (document.activeElement === last || ! drawer.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
    }
});

// The cart count is filled by the cart script into the FIRST count it finds; the header holds one per layout, so the others copy it (cache-safe, no extra request).
var counts = document.querySelectorAll('[data-sf-cart-count]');

if (counts.length > 1 && typeof MutationObserver === 'function') {
    var mirror = function () {
        for (var i = 1; i < counts.length; i++) {
            counts[i].textContent = counts[0].textContent;
            counts[i].hidden = counts[0].hidden;
        }

        var links = document.querySelectorAll('[data-sf-cart-link]');
        var label = links[0] ? links[0].getAttribute('aria-label') : null;

        for (var j = 1; j < links.length; j++) {
            label === null ? links[j].removeAttribute('aria-label') : links[j].setAttribute('aria-label', label);
        }
    };

    new MutationObserver(mirror).observe(counts[0], { childList: true, characterData: true, attributes: true });
    var firstLink = document.querySelector('[data-sf-cart-link]');

    if (firstLink) {
        new MutationObserver(mirror).observe(firstLink, { attributes: true, attributeFilter: ['aria-label'] });
    }
}

// ---- the menu bar (D3) ------------------------------------------------------------------------------------------------------------------------------------------------
// An item with a submenu has its own link AND a chevron button (aria-expanded / aria-controls) that opens its dropdown or mega panel: hover opens after 150ms and closes 300ms
// after leaving, a click / Enter / Space on the button toggles, Esc closes and returns focus to the button, one panel is open at a time. A mega panel's pictures get their
// src / srcset the first time it opens. What does not fit the menu's column moves into "More" (a ResizeObserver refits it); without this script the list simply wraps.
var openSub = null;
var hoverTimer = null;

function setSub(item, open) {
    var button = item.querySelector('[data-sf-sub-toggle]');
    var panel = item.querySelector('[data-sf-sub]');

    if (! button || ! panel) {
        return;
    }

    if (open) {
        if (openSub && openSub !== item) {
            setSub(openSub, false);
        }

        openSub = item;
        var pictures = panel.querySelectorAll('img[data-sf-src]');

        for (var p = 0; p < pictures.length; p++) {
            var set = pictures[p].getAttribute('data-sf-srcset');

            if (set) {
                pictures[p].setAttribute('srcset', set);
            }

            pictures[p].setAttribute('src', pictures[p].getAttribute('data-sf-src'));
            pictures[p].removeAttribute('data-sf-src');
            pictures[p].removeAttribute('data-sf-srcset');
        }
    } else if (openSub === item) {
        openSub = null;
    }

    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.classList.toggle('sf-sub-open', open);
}

function hover(target, over) {
    var item = target && target.closest ? target.closest('[data-sf-bar-item]') : null;

    // An item that was moved into "More" is part of More's own panel.
    if (item && item.parentNode && item.parentNode.hasAttribute('data-sf-more-list')) {
        item = item.closest('[data-sf-more]');
    }

    var has = item && item.querySelector('[data-sf-sub]');

    clearTimeout(hoverTimer);

    if (over && has) {
        hoverTimer = setTimeout(function () { setSub(item, true); }, 150);
    } else if (openSub) {
        hoverTimer = setTimeout(function () { openSub && setSub(openSub, false); }, 300);
    }
}

document.addEventListener('mouseover', function (event) { hover(event.target, true); });
document.addEventListener('mouseout', function (event) { hover(event.target, false); });

function fitBar(menu) {
    var more = menu.querySelector('[data-sf-more]');
    var list = menu.querySelector('[data-sf-more-list]');
    var row = more ? more.parentNode : null;

    if (! row || ! list) {
        return;
    }

    openSub && setSub(openSub, false);

    while (list.children.length) {
        row.insertBefore(list.children[0], more);
    }

    more.setAttribute('hidden', '');

    if (row.scrollWidth <= row.clientWidth) {
        return;
    }

    var items = [].filter.call(row.children, function (li) { return li !== more && li.hasAttribute('data-sf-bar-item'); });
    var last = items.length;

    more.removeAttribute('hidden');

    while (last > 0 && row.scrollWidth > row.clientWidth) {
        last--;
        list.insertBefore(items[last], list.children[0] || null);
    }
}

var bars = document.querySelectorAll('[data-sf-menubar]');

for (var b = 0; b < bars.length; b++) {
    (function (menu) {
        fitBar(menu);

        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(function () { fitBar(menu); }).observe(menu);
        }

        if (window.addEventListener) {
            window.addEventListener('load', function () { fitBar(menu); });
        }
    })(bars[b]);
}

document.addEventListener('click', function (event) {
    var target = event.target;

    if (! target || ! target.closest) {
        return;
    }

    var subToggle = target.closest('[data-sf-sub-toggle]');

    if (openSub && ! openSub.contains(target)) {
        setSub(openSub, false);
    }

    if (subToggle) {
        var owner = subToggle.closest('[data-sf-bar-item]');
        owner && setSub(owner, openSub !== owner);

        return;
    }

    var toggle = target.closest('[data-sf-menu-toggle]');

    if (toggle) {
        drawer && drawer.classList.contains('sf-drawer-open') ? closeDrawer() : openDrawer(toggle);

        return;
    }

    if (target.closest('[data-sf-drawer-close]')) {
        closeDrawer();

        return;
    }

    var search = target.closest('[data-sf-search-toggle]');

    if (search) {
        toggleSearch(search);

        return;
    }

    var accordion = target.closest('[data-sf-acc-toggle]');

    if (accordion) {
        var panel = document.getElementById(accordion.getAttribute('aria-controls'));
        var expanded = accordion.getAttribute('aria-expanded') !== 'true';
        accordion.setAttribute('aria-expanded', expanded ? 'true' : 'false');

        if (panel) {
            panel.classList.toggle('sf-acc-open', expanded);
        }

        return;
    }

    var thumb = target.closest('[data-sf-gallery-index]');
    var gallery = thumb ? thumb.closest('[data-sf-gallery]') : null;

    if (! thumb || ! gallery) {
        return;
    }

    var index = thumb.getAttribute('data-sf-gallery-index');
    var items = gallery.querySelectorAll('.sf-gallery-item');
    var thumbs = gallery.querySelectorAll('.sf-gallery-thumb');
    var i;

    for (i = 0; i < items.length; i++) {
        items[i].classList.toggle('sf-gallery-active', String(i) === index);
    }

    for (i = 0; i < thumbs.length; i++) {
        var current = String(i) === index;
        thumbs[i].classList.toggle('sf-gallery-thumb-active', current);

        if (current) {
            thumbs[i].setAttribute('aria-current', 'true');
        } else {
            thumbs[i].removeAttribute('aria-current');
        }
    }
});

