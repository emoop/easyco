{{-- The body of the picker's action modal (ML-2): the browser component and the one thing that has to happen outside it — the picked ids go into the field's state and the modal closes. --}}
@once
    @vite('resources/css/media-picker.css')
@endonce
<div x-data x-on:media-picked="
        const d = $event.detail;
        if (d.field !== @js($field)) return;
        @if ($multiple)
            const now = $wire.get(@js($field)) || [];
            $wire.set(@js($field), [...new Set([...now.map(Number), ...d.ids])].slice(0, @js($max)));
        @else
            $wire.set(@js($field), d.ids[0] ?? null);
        @endif
        $wire.unmountAction();
    " x-on:media-picker-cancel="$wire.unmountAction()">
    @livewire(\App\Livewire\MediaPickerBrowser::class, ['field' => $field, 'multiple' => $multiple, 'max' => $max, 'tab' => $tab], key('mp-'.$field.'-'.$tab))
</div>
