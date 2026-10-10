// The storefront's only script (stage S2b). It boots Alpine — the project's own Alpine, already bundled
// inside Livewire — so the small inline x-data in the storefront views (the mobile menu and the product
// gallery) works. No framework is added: this is the Alpine the admin panel already ships.
//
// window.livewireScriptConfig is set first so that merely importing Livewire does NOT auto-start Livewire
// itself: a storefront page has no Livewire component, only Alpine directives. The file is loaded only
// from the guarded @vite in resources/views/storefront/layout.blade.php, so a fresh checkout (or a test
// request) with no build gets no script at all.
window.livewireScriptConfig = {};

import('../../vendor/livewire/livewire/dist/livewire.esm.js').then(({ Alpine }) => {
    if (document.querySelector('[x-data]')) {
        Alpine.start();
    }
});
