<div class="sf-hdr-row sf-hdr-row-{{ $rowName }}{{ ! empty($row['text_size']) ? ' sf-ts-'.$row['text_size'] : '' }}{{ ! empty($row['fg']) ? ' sf-hdr-has-fg' : '' }}">
    <div class="sf-hdr-grid">
        @foreach (['left', 'center', 'right'] as $slot)
            <div class="sf-hdr-slot sf-hdr-{{ $slot }}">
                @foreach ($row[$slot] as $index => $element)
                    @include($element['view'], ['settings' => $element['settings'], 'menu' => $element['menu'] ?? null, 'context' => $context, 'uid' => $context.'-'.$rowName.'-'.$slot.'-'.$index])
                @endforeach
            </div>
        @endforeach
    </div>
</div>
