{{-- The body of the text editor's picture action (ML-2): the picker in `withSize` mode. The chosen id and size go into the action's hidden fields and the action is submitted; it inserts the <img>. --}}
@once
    @vite('resources/css/media-picker.css')
@endonce
<div x-data x-on:media-picked="
        const d = $event.detail;
        if (d.field !== 'editor') return;
        $wire.set('mountedActions.0.data.media_id', d.ids[0] ?? null);
        $wire.set('mountedActions.0.data.size', d.size);
        $wire.callMountedAction();
    " x-on:media-picker-cancel="$wire.unmountAction()">
    @livewire(\App\Livewire\MediaPickerBrowser::class, ['field' => 'editor', 'multiple' => false, 'max' => 1, 'withSize' => true, 'tab' => 'library'], key('mp-editor'))
</div>
