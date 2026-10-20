{{-- THE FOOTER (FT-1, storefront-customization-design.md 18). With a PUBLISHED footer layout ($appearance['footer'], already validated and prepared by FooterView: blocks with no output are
     gone) it prints two levels: the upper one (rows of equal columns, each column a list of blocks) and the lower one (one line of text; the year token is replaced HERE, by the clock).
     Colours, the border and the padding are CLASSES and CSS variables of the token stylesheet, never `style=`. With nothing published it prints the OLD footer unchanged (shop name, a
     link home and the `footer` menu), so the shop looks the same until the merchant publishes a footer. --}}
@php($footer = $appearance['footer'] ?? null)
@if ($footer === null)
    <footer class="sf-footer">
        <div class="sf-footer-inner">
            <div class="sf-footer-brand">
                <span class="sf-footer-name">{{ config('app.name') }}</span>
                <a class="sf-footer-home" href="{{ route('storefront.home', [], false) }}">{{ __('storefront.home') }}</a>
            </div>
            {{-- The `footer` menu (D3): flat links, compiled (published pages and shown categories only), labels escaped. --}}
            @if (! empty($appearance['footerMenu']))
                <nav class="sf-footer-pages" aria-label="{{ __('storefront.footer_pages') }}">
                    <ul class="sf-footer-list">
                        @foreach ($appearance['footerMenu'] as $footerLink)
                            @if ($footerLink['type'] === 'link')
                                <li><a href="{{ $footerLink['url'] }}"@if ($footerLink['new_tab']) target="_blank" rel="noopener"@endif>{{ $footerLink['label'] }}</a></li>
                            @endif
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>
    </footer>
@elseif ($footer['upper'] !== null || $footer['lower'] !== null)
    <footer class="sf-footer sf-ft">
        @if ($footer['upper'] !== null)
            <div class="sf-ft-upper sf-ft-pad-{{ $footer['upper']['padding'] }}">
                <div class="sf-ft-inner">
                    @foreach ($footer['upper']['rows'] as $rowIndex => $row)
                        <div class="sf-ft-row sf-ft-units-{{ $row['units'] }}">
                            @foreach ($row['columns'] as $columnIndex => $column)
                                <div class="sf-ft-col sf-ft-span-{{ $column['span'] }} sf-ft-align-{{ $column['align'] }}">
                                    @foreach ($column['blocks'] as $blockIndex => $block)
                                        @include($block['view'], ['settings' => $block['settings'], 'data' => $block['data'], 'uid' => 'r'.$rowIndex.'c'.$columnIndex.'b'.$blockIndex])
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if ($footer['lower'] !== null)
            <div class="sf-ft-lower sf-ft-pad-{{ $footer['lower']['padding'] }}">
                <p class="sf-ft-line sf-ft-align-{{ $footer['lower']['align'] }}">{{ \App\Storefront\Footer\FooterView::line($footer['lower']['template']) }}</p>
            </div>
        @endif
    </footer>
@endif
