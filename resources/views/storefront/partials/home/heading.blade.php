{{-- HP-1, home section `heading`: «Заглавие с линии» — a line, the text, a line (or the text only). The text is a REAL <h2>/<h3>/<h4> (the level is from the closed list the validator checked) and is escaped; the lines are
     decorative empty spans (aria-hidden). $item['settings'] = {text, level, lines, thickness, show, spacing}. --}}
@php
    $level = in_array($item['settings']['level'] ?? null, \App\Storefront\Home\HomeCatalog::LEVELS, true) ? $item['settings']['level'] : 'h2';
    $withLines = ($item['settings']['lines'] ?? 'lines') === 'lines';
    $thick = ($item['settings']['thickness'] ?? '1') === '2' ? '2' : '1';
@endphp
<div class="sf-hp-heading-row sf-hp-{{ $level }} {{ $withLines ? 'sf-hp-lines sf-hp-l'.$thick : 'sf-hp-plain' }}">
    @if ($withLines)<span class="sf-hp-line" aria-hidden="true"></span>@endif
    <{{ $level }} class="sf-hp-heading-text">{{ $item['settings']['text'] ?? '' }}</{{ $level }}>
    @if ($withLines)<span class="sf-hp-line" aria-hidden="true"></span>@endif
</div>
