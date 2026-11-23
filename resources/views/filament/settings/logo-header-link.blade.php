{{-- LOGO-1: where the logo of the shop HEADER is set (it stays a component in «Дизайн»: placement, size and picture are decided there). A navigation link, not an explanation of a field. --}}
<p class="lg-header-link" style="font-size: .8125rem; margin: .25rem 0 0">
    {{ __('logo_admin.header_link') }}
    <a href="{{ \App\Filament\Pages\Design\DesignScreen::getUrl() }}" style="text-decoration: underline">{{ __('logo_admin.header_link_label') }}</a>
</p>
