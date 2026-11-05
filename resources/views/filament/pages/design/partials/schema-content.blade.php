{{-- P3: the schema of «Съдържание»: what the zone changes, in a line, and the pages that have their own template (by kind). Admin only; no inline style. --}}
@php
    $set = \App\Storefront\Content\ContentSet::read($this->tree['values'] ?? []);
    $kinds = \App\Storefront\Content\ContentTemplate::KINDS;
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>
    <p class="ds-note">{{ __('design.content.schema') }}</p>
    <ul class="ds-note">
        @foreach (array_keys($kinds) as $kind)
            <li>{{ __('design.content.kinds.'.$kind) }}: {{ $set[$kind]['default'] === null ? __('design.content.no_default') : __('design.content.has_default') }}, {{ __('design.content.own_count', ['count' => count($set[$kind]['own'])]) }}</li>
        @endforeach
    </ul>
</section>
