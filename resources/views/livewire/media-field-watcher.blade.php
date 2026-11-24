{{-- MEDIA-UP-3: the watcher of a picture field (app/Livewire/MediaFieldWatcher.php). Its own poll, only while a picture of the field waits; it renders nothing else. --}}
<div class="mpf-poll" aria-hidden="true" @unless ($settled) wire:poll.visible.2s="check" @endunless></div>
