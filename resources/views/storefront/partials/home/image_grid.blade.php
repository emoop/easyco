{{-- HP-2, home section `image_grid`: HTML blocks laid out in the cells of a scheme (CSS grid with named areas, one class per choice: scheme, proportion, gap, radius; no inline style, no script). The blocks are the compiled,
     sanitised HTML (the same path as the `html_block` section), a cell with no block is not printed. Below 48rem the grid is hidden and the phone list (up to 4 blocks, one under another) is shown instead; a grid with
     no phone block shows nothing on a phone. $item['grid'] = {class, cells[{name, html}], phone_class, phone[html]}. --}}
@php $grid = $item['grid']; @endphp
@if ($grid['cells'] !== [])
    <div class="{{ $grid['class'] }}">
        @foreach ($grid['cells'] as $cell)
            <div class="sf-hp-cell sf-hp-a-{{ $cell['name'] }}">{!! $cell['html'] !!}</div>
        @endforeach
    </div>
@endif
@if ($grid['phone'] !== [])
    <div class="{{ $grid['phone_class'] }}">
        @foreach ($grid['phone'] as $html)
            <div class="sf-hp-pcell">{!! $html !!}</div>
        @endforeach
    </div>
@endif
