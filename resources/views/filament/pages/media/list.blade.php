{{-- «Медия» (ML-1a, ML-1b): the grid of every uploaded picture and video (app/Filament/Resources/MediaResource.php). The page adds its small stylesheet to Filament's own list, the progress line of a
     «delete all unused» run and the two polls — each only while there is something to wait for: the run, or a card that still says «обработва се». --}}
<x-filament-panels::page>
    @vite('resources/css/media-library.css')

    <div class="ml">
        @php($progress = $this->progress())

        @if ($progress !== null)
            <div class="ml-progress" role="status" wire:poll.3s="refreshBulkProgress">
                {{ __('media.library.delete.progress', ['done' => number_format($progress['done'], 0, ',', ' '), 'total' => number_format($progress['total'], 0, ',', ' ')]) }}
                @if ($progress['skipped'] > 0)
                    {{ __('media.library.delete.progress_skipped', ['skipped' => $progress['skipped']]) }}
                @endif
            </div>
        @elseif ($this->somethingIsProcessing())
            <div wire:poll.3s class="ml-poll" aria-hidden="true"></div>
        @endif

        {{ $this->content }}
    </div>
</x-filament-panels::page>
