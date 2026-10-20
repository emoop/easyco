{{-- Footer block `menu` (FT-1): an optional heading and the TOP-LEVEL links of the chosen menu (no sub-levels, no mega; badges shown). The links are compiled (published pages, shown categories). --}}
<nav class="sf-ft-block sf-ft-menu"@if ($settings['heading'] !== '') aria-labelledby="sf-ft-h-{{ $uid }}"@else aria-label="{{ __('storefront.footer_pages') }}"@endif>
    @if ($settings['heading'] !== '')
        <p class="sf-ft-heading" id="sf-ft-h-{{ $uid }}">{{ $settings['heading'] }}</p>
    @endif
    <ul class="sf-ft-list">
        @foreach ($data['items'] as $item)
            <li><a href="{{ $item['url'] }}"@if ($item['new_tab']) target="_blank" rel="noopener"@endif>{{ $item['label'] }}@include('storefront.partials.menu-badge', ['badge' => $item['badge']])</a></li>
        @endforeach
    </ul>
</nav>
