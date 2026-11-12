{{-- P7-1: the schema of «Значки»: what the zone changes, in a line, and the working copy's badges (switched on or off, in words). Admin only; no inline style. --}}
@php
    [$badgeValues] = \App\Storefront\Badges\BadgeSet::validate($this->tree['values'] ?? []);
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>
    <p class="ds-note">{{ __('design.badges.schema') }}</p>
    <ol class="ds-note">
        @foreach ($badgeValues['badges'] as $row)
            <li>{{ __('design.badges.kinds.'.$row['kind']) }} ({{ $row['enabled'] ? __('design.badges.on') : __('design.badges.off') }})</li>
        @endforeach
    </ol>
</section>
