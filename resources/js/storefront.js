// The storefront's only script (stage S2c): about forty lines of plain JavaScript — no framework, no
// imports, no Livewire. It does two things and nothing else: (1) the mobile menu toggle and (2) the
// product gallery's thumbnail swap. BOTH are progressive: without this script the pages still work —
// the nav is shown by default and the first gallery image is the main one (see the `.js` rules in
// storefront.css). The file is loaded only from the guarded @vite in
// resources/views/storefront/layout.blade.php, so a checkout without a build gets no script at all.
document.documentElement.classList.add('js');

document.addEventListener('click', function (event) {
    var target = event.target;

    if (! target || ! target.closest) {
        return;
    }

    var toggle = target.closest('[data-sf-menu-toggle]');

    if (toggle) {
        var nav = document.getElementById('sf-menu');

        if (nav) {
            var open = nav.classList.toggle('sf-nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
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

