<?php

namespace App\Providers;

use App\Http\Controllers\Api\MediaController;
use Illuminate\Support\ServiceProvider;

/**
 * Contextual-binds MediaController's three primitive config values —
 * same "config is read only at the wiring boundary, never inside the
 * class body" principle EasyCo\Media\Providers\MediaServiceProvider
 * already establishes for ProductMediaCountGuard/LaravelMediaStorageAdapter/
 * LaravelMediaImageProcessor, applied here instead of there because
 * MediaController is a root-app class (app/Http/Controllers/Api/) —
 * the Media package itself never references App\* classes (CLAUDE.md
 * rule 9's package/app boundary, unbroken by this).
 */
class MediaControllerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(MediaController::class)
            ->needs('$maxImageSizeKb')
            ->give(fn () => (int) config('services.media.max_image_size_kb', 10240));

        $this->app->when(MediaController::class)
            ->needs('$maxVideoSizeKb')
            ->give(fn () => (int) config('services.media.max_video_size_kb', 204800));

        $this->app->when(MediaController::class)
            ->needs('$minImageDimensionPx')
            ->give(fn () => (int) config('services.media.min_image_dimension_px', 600));
    }
}
