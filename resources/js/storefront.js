// The storefront's only script (stage S2c): about forty lines of plain JavaScript — no framework, no
// imports, no Livewire. It does two things and nothing else: (1) the mobile menu toggle and (2) the
// product gallery (since GL-1: modes, lightbox; and, since D2, the header's drawer, search row and accordion), (3) since PC-1 the second photo of a product card on hover, and (4) since PC-2 the sizes of the cards.
// All of them are progressive: without this script the pages still work —
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

    galleryClick(target, event);
});

// PC-1: a product card's second photo is requested on the FIRST pointer hover (a mouse) or keyboard focus of the card, never before. The browser fetches nothing on its own:
// the photo's URLs sit in data- attributes until then. Once per card: the data- attributes are removed, so a second hover finds nothing to do.
function revealSecondPhoto(target) {
    var card = target && target.closest ? target.closest('.sf-card') : null;
    var image = card ? card.querySelector('.sf-card-image-hover[data-src]') : null;

    if (image === null) {
        return;
    }

    image.removeAttribute('hidden');
    image.setAttribute('srcset', image.getAttribute('data-srcset'));
    image.setAttribute('sizes', image.getAttribute('data-sizes'));
    image.setAttribute('src', image.getAttribute('data-src'));
    image.removeAttribute('data-src');
    image.removeAttribute('data-srcset');
    image.removeAttribute('data-sizes');

    var show = function () { image.classList.add('is-loaded'); };
    image.addEventListener('load', show, { once: true });

    if (image.complete) {
        show();
    }
}

// pointerover bubbles (pointerenter does not), so one listener on the document serves every card. Touch never reaches here: pointerType is 'touch' or 'pen'.
document.addEventListener('pointerover', function (event) {
    if (event.pointerType === 'mouse') {
        revealSecondPhoto(event.target);
        requestCardSizes(); // PC-2: the first mouse hover over a card asks for the sizes of the whole page (once)
    }
});

// Keyboard focus on a card counts too, but a touch tap can also focus a link: only with a fine pointer, where the photo can be shown at all.
document.addEventListener('focusin', function (event) {
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        revealSecondPhoto(event.target);
    }
});

// PC-2: the SIZES of the cards (sold-out ones crossed out), in ONE request for the page. Desktop: asked at the first mouse hover over a card, shown by the hover.
// Touch (no hover): asked once, when the browser is idle after the page has loaded, and slid in under every photo. Data saver: never asked. Decided once, at load.
// Without matchMedia (an old browser, or a script host that has none) the mode is 'hover': the sizes then wait for a mouse hover, so nothing runs at load.
var sizesMode = typeof window.matchMedia !== 'function' || window.matchMedia('(hover: hover) and (pointer: fine)').matches ? 'hover' : 'touch';
var sizesRequested = false;
var sizesLogged = false;

function cardSizesWanted() {
    return ! (navigator.connection && navigator.connection.saveData === true);
}

function requestCardSizes() {
    if (sizesRequested || ! cardSizesWanted()) {
        return;
    }

    // the first 48 cards of the page, by their product id; a card outside them simply has no sizes
    var ids = [];
    var cards = document.querySelectorAll('article.sf-card[data-product-id]');

    for (var i = 0; i < cards.length && ids.length < 48; i++) {
        var id = cards[i].getAttribute('data-product-id');

        if (/^[1-9][0-9]{0,17}$/.test(id) && ids.indexOf(id) === -1) {
            ids.push(id);
        }
    }

    if (ids.length === 0) {
        return;
    }

    sizesRequested = true;

    fetch('/storefront/card-sizes?ids=' + ids.join(','), { headers: { Accept: 'application/json' }, credentials: 'omit' })
        .then(function (response) {
            if (! response.ok) {
                throw new Error('status ' + response.status);
            }

            return response.json();
        })
        .then(function (data) {
            fillCardSizes(data && data.sizes ? data.sizes : {});
        })
        .catch(function (error) {
            if (! sizesLogged) {
                sizesLogged = true;
                console.debug('card sizes unavailable', error);
            }
        });
}

// Fills the list of every card that has sizes: one <li> per size, with textContent only (never innerHTML); sold-out sizes get .is-sold-out.
function fillCardSizes(sizesByProduct) {
    var filled = [];

    document.querySelectorAll('article.sf-card[data-product-id]').forEach(function (card) {
        var sizes = sizesByProduct[card.getAttribute('data-product-id')];
        var list = card.querySelector('.sf-card-sizes');

        if (! list || ! Array.isArray(sizes) || sizes.length === 0) {
            return;
        }

        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }

        sizes.forEach(function (size) {
            var item = document.createElement('li');
            item.textContent = String(size.label);

            if (! size.in_stock) {
                item.className = 'is-sold-out';
            }

            list.appendChild(item);
        });

        list.removeAttribute('hidden');
        filled.push(list);
    });

    if (sizesMode === 'touch') {
        revealSizeStrips(filled);
    }
}

// Touch: the strips slide in on the next two frames (so the transition runs), one after another for the first 12 cards (40 ms apart).
function revealSizeStrips(lists) {
    lists.forEach(function (list, index) {
        list.style.setProperty('--sf-sizes-delay', (index < 12 ? index * 40 : 0) + 'ms');
    });

    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            lists.forEach(function (list) {
                list.classList.add('is-ready');
            });
        });
    });
}

// Touch mode asks once, when the browser is idle after the load (2 s at most; 1.2 s where requestIdleCallback is missing).
if (sizesMode === 'touch') {
    window.addEventListener('load', function () {
        var ask = function () {
            requestCardSizes();
        };

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(ask, { timeout: 2000 });
        } else {
            setTimeout(ask, 1200);
        }
    });
}


// GL-1: the product page gallery. One delegated click handler (called from the page's click listener above) for the thumbnails, the carousel arrow and the
// lightbox links; the lightbox's own events are attached ONCE, when it first opens. Nothing runs on a page without a gallery. No HTML strings: attributes,
// classes and textContent only. The zoom / pan state of the lightbox lives in CSS variables on its image.
var GALLERY_STEP = 0.5;
var GALLERY_MAX_ZOOM = 4;
var GALLERY_DOUBLE_ZOOM = 2.5;
var GALLERY_SWIPE = 50;
var GALLERY_DESKTOP = '(min-width: 64rem)'; // the storefront's own breakpoint (where .sf-product turns two-column)

var lightbox = null;

function pauseVideos(gallery, except) {
    gallery.querySelectorAll('video').forEach(function (video) {
        if (! except || ! except.contains(video)) {
            video.pause();
        }
    });
}

// A big image that waits in data-src is given its src: all of them where the layout shows every photo at once (grid, carousel), the shown one in a thumbnail mode.
function fillGalleryImage(figure) {
    var image = figure ? figure.querySelector('img[data-src]') : null;

    if (! image) {
        return;
    }

    if (image.hasAttribute('data-srcset')) {
        image.setAttribute('srcset', image.getAttribute('data-srcset'));
        image.removeAttribute('data-srcset');
    }

    image.setAttribute('src', image.getAttribute('data-src'));
    image.removeAttribute('data-src');
}

function fillGalleryImages(gallery) {
    var wide = typeof window.matchMedia !== 'function' || window.matchMedia(GALLERY_DESKTOP).matches;
    var mode = gallery.getAttribute(wide ? 'data-gal-desktop' : 'data-gal-mobile');

    gallery.querySelectorAll(mode === 'grid' || mode === 'carousel' ? '.sf-gal-item' : '.sf-gal-item.is-active').forEach(fillGalleryImage);
}

function activateGalleryItem(gallery, index) {
    var items = gallery.querySelectorAll('.sf-gal-item');
    var thumbs = gallery.querySelectorAll('.sf-gal-thumb');
    var active = items[index];

    if (! active) {
        return;
    }

    pauseVideos(gallery, active);
    fillGalleryImage(active);

    items.forEach(function (item, i) {
        item.classList.toggle('is-active', i === index);
    });

    thumbs.forEach(function (thumb, i) {
        thumb.classList.toggle('is-active', i === index);
        thumb.setAttribute('aria-current', i === index ? 'true' : 'false');
    });
}

function updateCarouselArrow(gallery) {
    var main = gallery.querySelector('.sf-gal-main');
    var next = gallery.querySelector('.sf-gal-next');

    if (main && next) {
        next.classList.toggle('is-end', main.scrollLeft + main.clientWidth >= main.scrollWidth - 2);
    }
}

function carouselNext(gallery) {
    var main = gallery.querySelector('.sf-gal-main');
    var items = main ? main.querySelectorAll('.sf-gal-item') : [];

    if (items.length < 2) {
        return;
    }

    pauseVideos(gallery, null);

    var step = items[1].offsetLeft - items[0].offsetLeft;
    var calm = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    main.scrollBy({ left: step, behavior: calm ? 'auto' : 'smooth' });
}

function galleryClick(target, event) {
    var thumb = target.closest('.sf-gal-thumb');
    var arrow = thumb ? null : target.closest('.sf-gal-next');
    var link = thumb || arrow ? null : target.closest('[data-lightbox]');
    var gallery = (thumb || arrow || link) ? (thumb || arrow || link).closest('[data-sf-gallery]') : null;

    if (! gallery) {
        return;
    }

    if (thumb) {
        activateGalleryItem(gallery, [].indexOf.call(gallery.querySelectorAll('.sf-gal-thumb'), thumb));
    } else if (arrow) {
        carouselNext(gallery);
    } else if (link && typeof HTMLDialogElement === 'function' && typeof HTMLDialogElement.prototype.showModal === 'function') {
        event.preventDefault();
        openLightbox(gallery, link);
    }
}

// The arrow is hidden at the last slide: the one scroll listener, on the one gallery of the page (found with a single querySelector).
(function () {
    var gallery = document.querySelector('[data-sf-gallery]');
    var main = gallery ? gallery.querySelector('.sf-gal-main') : null;

    if (main) {
        main.addEventListener('scroll', function () { updateCarouselArrow(gallery); }, { passive: true });
        updateCarouselArrow(gallery);
        fillGalleryImages(gallery);

        // the layout may change from one mode to another when the window is resized across the breakpoint
        var wide = typeof window.matchMedia === 'function' ? window.matchMedia(GALLERY_DESKTOP) : null;

        if (wide && typeof wide.addEventListener === 'function') {
            wide.addEventListener('change', function () { fillGalleryImages(gallery); updateCarouselArrow(gallery); });
        }
    }
})();

function openLightbox(gallery, link) {
    var dialog = gallery.querySelector('.sf-lightbox');

    if (! dialog) {
        return;
    }

    if (! lightbox || lightbox.dialog !== dialog) {
        lightbox = {
            dialog: dialog,
            image: dialog.querySelector('.sf-lightbox-img'),
            stage: dialog.querySelector('.sf-lightbox-stage'),
            count: dialog.querySelector('.sf-lightbox-count'),
            prev: dialog.querySelector('.sf-lightbox-prev'),
            next: dialog.querySelector('.sf-lightbox-next'),
            close: dialog.querySelector('.sf-lightbox-close'),
            links: [].slice.call(gallery.querySelectorAll('[data-lightbox]')),
            index: 0,
            scale: 1,
            x: 0,
            y: 0,
            opener: null,
            pointers: {},
            pointerCount: 0,
            dragged: false,
            downOnStage: false,
            startX: 0,
            startY: 0
        };

        bindLightbox(lightbox);
    }

    lightbox.opener = link;
    showLightboxImage(lightbox, lightbox.links.indexOf(link));
    dialog.showModal();
    lightbox.close.focus();
}

function showLightboxImage(box, index) {
    var link = box.links[index];

    if (! link) {
        return;
    }

    var thumb = link.querySelector('img');

    box.index = index;
    box.image.setAttribute('src', link.getAttribute('href'));
    box.image.setAttribute('alt', thumb ? thumb.getAttribute('alt') : '');
    box.prev.disabled = index === 0;
    box.next.disabled = index === box.links.length - 1;
    box.count.textContent = box.dialog.getAttribute('data-counter').replace(':n', String(index + 1)).replace(':total', String(box.links.length));
    setLightboxZoom(box, 1, 0, 0);
}

function setLightboxZoom(box, scale, x, y) {
    box.scale = scale;
    box.x = scale === 1 ? 0 : x;
    box.y = scale === 1 ? 0 : y;
    box.image.style.setProperty('--sf-lb-scale', String(box.scale));
    box.image.style.setProperty('--sf-lb-x', box.x + 'px');
    box.image.style.setProperty('--sf-lb-y', box.y + 'px');
}

// Zooms to `scale` keeping the point under the cursor where it is: the image scales around its own centre, so the centre moves by d * (1 - scale / old).
function zoomLightboxAt(box, scale, clientX, clientY) {
    scale = Math.max(1, Math.min(GALLERY_MAX_ZOOM, scale));

    var rect = box.image.getBoundingClientRect();
    var dx = clientX - (rect.left + rect.width / 2);
    var dy = clientY - (rect.top + rect.height / 2);
    var ratio = 1 - scale / box.scale;

    setLightboxZoom(box, scale, box.x + dx * ratio, box.y + dy * ratio);
}

function stepLightbox(box, by) {
    var index = box.index + by;

    if (index >= 0 && index < box.links.length) {
        showLightboxImage(box, index);
    }
}

function bindLightbox(box) {
    var dialog = box.dialog;

    dialog.addEventListener('click', function (event) {
        var target = event.target;

        if (target.closest('.sf-lightbox-close')) {
            dialog.close();
        } else if (target.closest('.sf-lightbox-prev')) {
            stepLightbox(box, -1);
        } else if (target.closest('.sf-lightbox-next')) {
            stepLightbox(box, 1);
        } else if (! box.dragged && box.downOnStage && (target === dialog || target === box.stage)) {
            // the dark area: only when the press STARTED there (a pointer captured by the stage while panning retargets the click to it)
            dialog.close();
        }

        box.dragged = false;
        box.downOnStage = false;
    });

    dialog.addEventListener('close', function () {
        if (box.opener) {
            box.opener.focus();
        }
    });

    dialog.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowLeft') {
            stepLightbox(box, -1);
        } else if (event.key === 'ArrowRight') {
            stepLightbox(box, 1);
        }
    });

    box.stage.addEventListener('wheel', function (event) {
        event.preventDefault();
        zoomLightboxAt(box, box.scale + (event.deltaY < 0 ? GALLERY_STEP : -GALLERY_STEP), event.clientX, event.clientY);
    }, { passive: false });

    box.stage.addEventListener('dblclick', function (event) {
        zoomLightboxAt(box, box.scale > 1 ? 1 : GALLERY_DOUBLE_ZOOM, event.clientX, event.clientY);
    });

    box.stage.addEventListener('pointerdown', function (event) {
        box.pointers[event.pointerId] = true;
        box.pointerCount = Object.keys(box.pointers).length;
        box.startX = event.clientX;
        box.startY = event.clientY;
        box.dragged = false;
        box.downOnStage = event.target === box.stage;
        box.panX = box.x;
        box.panY = box.y;

        if (box.scale > 1 && event.pointerType !== 'touch') {
            box.stage.setPointerCapture(event.pointerId);
        }
    });

    box.stage.addEventListener('pointermove', function (event) {
        if (! box.pointers[event.pointerId] || box.scale === 1 || event.pointerType === 'touch') {
            return;
        }

        if (Math.abs(event.clientX - box.startX) + Math.abs(event.clientY - box.startY) > 3) {
            box.dragged = true;
        }

        setLightboxZoom(box, box.scale, box.panX + event.clientX - box.startX, box.panY + event.clientY - box.startY);
    });

    var release = function (event) {
        var wasSingle = box.pointerCount === 1;

        delete box.pointers[event.pointerId];
        box.pointerCount = Object.keys(box.pointers).length;

        // A swipe of the finger (touch, not zoomed, not pinching): the next / previous image.
        if (event.type === 'pointerup' && event.pointerType === 'touch' && wasSingle && box.scale === 1
            && ! (window.visualViewport && window.visualViewport.scale > 1.01)) {
            var dx = event.clientX - box.startX;
            var dy = event.clientY - box.startY;

            if (Math.abs(dx) > GALLERY_SWIPE && Math.abs(dx) > Math.abs(dy)) {
                box.dragged = true;
                stepLightbox(box, dx < 0 ? 1 : -1);
            }
        }
    };

    box.stage.addEventListener('pointerup', release);
    box.stage.addEventListener('pointercancel', release);
}
