{{--
    The "Needs attention" page (shipping-domain-design.md §7.2.20 §6): one section per source, each with its own
    count and its own pagination, every row a link to the order it is about. The page DECIDES NOTHING — every
    string it draws was built by the row itself (the sources' facts, already translated and formatted), and it
    adds no words of its own beyond the headings, the column names and the wait.

    Read-only by design (§7.2.7): no action, no colour, no severity, no threshold, no badge. Plain markup and
    plain CSS — no theme build, no JavaScript of its own. The pagination links are ordinary GETs, not Livewire
    calls: see the page class's own docblock for the wire:key collision that rules Filament's pagination
    component out for a component with more than one paginator, and for why a fresh GET is honest here.
--}}
<x-filament-panels::page>
    <style>
        .na-intro { opacity: .75; font-size: .9rem; margin-bottom: 1.25rem; }
        .na-section + .na-section { margin-top: 2rem; }
        .na-heading { display: flex; align-items: baseline; gap: .4rem; font-weight: 700; font-size: 1.05rem; margin-bottom: .5rem; }
        .na-count { font-weight: 400; font-size: .85rem; opacity: .7; }
        .na-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .na-table th, .na-table td { padding: .4rem .5rem; text-align: left; border-bottom: 1px solid rgba(128, 128, 128, .25); vertical-align: top; }
        .na-table th { font-weight: 600; white-space: nowrap; }
        .na-table tbody tr:last-child td { border-bottom: 0; }
        .na-right { text-align: right; white-space: nowrap; }
        .na-link { text-decoration: underline; font-weight: 600; }
        .na-note { opacity: .75; font-size: .9rem; }
        .na-pagination { display: flex; align-items: center; gap: .75rem; margin-top: .5rem; font-size: .875rem; }
        .na-step { text-decoration: underline; }
        .na-off { opacity: .4; }
    </style>

    <p class="na-intro">{{ __('needs_attention.intro') }}</p>

    @if ($empty)
        <p>{{ __('needs_attention.empty') }}</p>
    @else
        @foreach ($sections as $section)
            <section class="na-section" aria-labelledby="na-section-{{ $section['key'] }}">
                <h2 class="na-heading" id="na-section-{{ $section['key'] }}">
                    {{ $section['label'] }}
                    <span class="na-count">({{ $section['count'] }})</span>
                </h2>

                @if ($section['rows']->isEmpty())
                    <p class="na-note">{{ __('needs_attention.section_empty') }}</p>
                @else
                    <table class="na-table">
                        <thead>
                            <tr>
                                <th>{{ __('needs_attention.columns.order') }}</th>
                                <th>{{ __('needs_attention.columns.fact') }}</th>
                                <th class="na-right">{{ __('needs_attention.columns.amount') }}</th>
                                <th class="na-right">{{ __('needs_attention.columns.waiting') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($section['rows'] as $row)
                                <tr>
                                    <td><a class="na-link" href="{{ $row['url'] }}">{{ $row['order'] }}</a></td>
                                    <td>{{ $row['fact'] }}</td>
                                    <td class="na-right">{{ $row['amount'] }}</td>
                                    <td class="na-right" title="{{ $row['started_on'] }}">{{ $row['waiting'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if ($section['rows']->hasPages())
                        <nav class="na-pagination" aria-label="{{ $section['label'] }}">
                            @if ($section['rows']->onFirstPage())
                                <span class="na-off">{{ __('needs_attention.pagination.previous') }}</span>
                            @else
                                <a class="na-step" rel="prev" href="{{ $section['rows']->previousPageUrl() }}">{{ __('needs_attention.pagination.previous') }}</a>
                            @endif

                            <span>{{ __('needs_attention.pagination.status', ['page' => $section['rows']->currentPage(), 'last' => $section['rows']->lastPage()]) }}</span>

                            @if ($section['rows']->hasMorePages())
                                <a class="na-step" rel="next" href="{{ $section['rows']->nextPageUrl() }}">{{ __('needs_attention.pagination.next') }}</a>
                            @else
                                <span class="na-off">{{ __('needs_attention.pagination.next') }}</span>
                            @endif
                        </nav>
                    @endif
                @endif
            </section>
        @endforeach
    @endif
</x-filament-panels::page>
