<?php print("\x01"); ?>{{-- SC-1 / P4b: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- P4b, right column component `accordion`: the product's sections («Секции на продукта») resolved for THIS product (SectionResolver: a section with no text is not here), as native <details> — independent (no `name`: opening one does not close another), closed by default, the first one open when the component's `first_open` is on. No script. The text is the description's own sanitised HTML. Nothing is printed without a section. --}}
@if (($sections ?? []) !== [])
                @php($firstOpen = ($component['settings']['first_open'] ?? false) === true)
                <div class="sf-psec-list">
                    @foreach ($sections as $section)
                        <details class="sf-psec"{{ $firstOpen && $loop->first ? ' open' : '' }}>
                            <summary class="sf-psec-title">{{ $section['title'] }}</summary>
                            <div class="sf-psec-body">{!! $section['html'] !!}</div>
                        </details>
                    @endforeach
                </div>
@endif
