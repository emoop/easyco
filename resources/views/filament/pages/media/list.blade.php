{{-- «Медия» (ML-1a): the grid of every uploaded picture and video (app/Filament/Resources/MediaResource.php). The page only adds its small stylesheet to Filament's own list. --}}
<x-filament-panels::page>
    @vite('resources/css/media-library.css')

    <div class="ml">
        {{ $this->content }}
    </div>
</x-filament-panels::page>
