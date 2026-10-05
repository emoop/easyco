@php
    use Filament\Support\Icons\Heroicon;
@endphp

{{--
    The admin panel's own top-bar actions. Rendered by
    AdminPanelProvider's own ->renderHook(PanelsRenderHook::USER_MENU_BEFORE,
    ...) call, which places this partial in the top bar's right-hand cluster
    (.fi-topbar-end) immediately before <x-filament-panels::user-menu /> —
    i.e. immediately before the avatar, with or without a topbar-positioned
    global search or database notifications in between.

    Two actions live here:

      1. Filament's own light/dark/system switcher, reused unmodified. It is
         only re-positioned: out of the box Filament renders it inside the
         user-menu dropdown (user-menu.blade.php's hasThemeSwitcher() gate),
         which AdminPanelProvider switches off so there is exactly one copy.
         The choice itself stays client-side in localStorage and is applied
         by Filament's own dark-mode.js in response to the window-level
         'theme-changed' event these buttons dispatch, so it survives reloads
         with no server round trip and no site setting of our own.

      2. The "View store" link out to the storefront, opened in a new tab so
         an admin checking the shop never loses a half-finished edit.
--}}

@if (filament()->hasDarkMode() && (! filament()->hasDarkModeForced()))
    {{--
        Rendered only when dark mode is actually available, mirroring the
        identical guard on Filament's own dropdown copy (user-menu.blade.php,
        line 122) — the component carries no such check itself, so without
        this guard a later ->darkMode(false) would leave three buttons in the
        top bar that toggle nothing.

        The <div> below is a one-property Alpine scope, and the whole reason
        is that Filament's switcher markup is written for its dropdown
        context: each button's handler is `(theme = ...) && close()`, calling
        the filamentDropdown component's close() method, which does not exist
        in the top bar. Without this wrapper that lookup falls through the
        scope chain to the browser's global window.close() and every click
        logs "Scripts may close only the windows that were opened by them".
        The theme assignment itself, and the 'theme-changed' event
        dark-mode.js listens for on window, are left exactly as Filament
        wrote them — there is simply no menu to close here.
    --}}
    <div x-data="{ close: () => {} }">
        <x-filament-panels::theme-switcher />
    </div>
@endif

<x-filament::icon-button
    color="gray"
    icon-size="lg"
    :icon="Heroicon::OutlinedBuildingStorefront"
    :label="__('navigation.topbar.view_store')"
    tag="a"
    {{--
        `/` is the storefront's own home URL (storefront-frontend-design.md
        §7's route table opens with `GET / → HomeController@index`) and there
        is no named storefront route to link to yet — the storefront is not
        built, and `/` is still Laravel's stock welcome page. url('/') is
        therefore the honest address of where the storefront will live,
        rather than a guess at a route name; the sandbox at /_sandbox is
        deliberately NOT the target, since it is explicitly not the real
        storefront.

        getUrl-with-new-tab would normally come from Filament's own
        ->shouldOpenUrlInNewTab() helpers, which are not available to a raw
        icon-button: generate_href_html() emits `target="_blank"` for this
        case but no `rel`, so it is passed explicitly — rel="noopener
        noreferrer" is the reason the new tab cannot reach back into this
        panel via window.opener.
    --}}
    :href="url('/')"
    target="_blank"
    rel="noopener noreferrer"
/>
