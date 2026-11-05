{{-- P1: the schema of «Сайдбар»: what the zone changes, in a line, and the working copy's filters in their order (a hidden one is marked in words). Admin only; no inline style. --}}
@php
    $names = app(\App\Storefront\Filters\FilterCatalog::class)->names();
    [$sidebarValues] = \App\Storefront\Sidebar\SidebarFilters::validate($this->tree['values'] ?? [], array_map('strval', array_keys($names)));
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>
    <p class="ds-note">{{ __('design.sidebar.schema') }}</p>
    <ol class="ds-note">
        @foreach ($sidebarValues['filters'] as $row)
            <li>{{ $names[$row['key']] ?? $row['key'] }}@unless ($row['show']) ({{ __('design.sidebar.hidden') }})@endunless</li>
        @endforeach
    </ol>
</section>
