<?php

namespace App\Media\Usage;

use EasyCo\Extensibility\Hook;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Every place that can hold a picture, asked together (media-library-design.md §3, ML-D2): the built-in providers plus whatever an extension adds through the `media.usages` FILTER hook
 * `(list<MediaUsageProvider> $providers): list<MediaUsageProvider>`. Something in the list that is not a provider is skipped and logged ONCE per process, never a failure. The hook is applied
 * once per registry instance (the container keeps one per request).
 */
final class MediaUsageRegistry
{
    public const HOOK = 'media.usages';

    /** @var list<MediaUsageProvider>|null */
    private ?array $providers = null;

    /** @var array<string, true> */
    private static array $logged = [];

    /** @return list<MediaUsageProvider> */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        // the two providers that look into HTML blocks share ONE read of the blocks
        $blocks = new HtmlBlockRows;
        $builtIn = [new ProductPhotos, new VariationPhotos, new BrandLogo, new CategoryPictures, new AttributeValuePictures, new HtmlBlockPictures($blocks), new HtmlBlockBody($blocks), new DesignZones];
        $filtered = Hook::apply(self::HOOK, $builtIn);
        $valid = [];

        foreach (is_array($filtered) ? $filtered : [] as $provider) {
            if ($provider instanceof MediaUsageProvider) {
                $valid[] = $provider;

                continue;
            }

            $what = is_object($provider) ? $provider::class : get_debug_type($provider);
            if (! isset(self::$logged[$what])) {
                self::$logged[$what] = true;
                Log::warning('media.usages: a registered provider is not a MediaUsageProvider and was skipped', ['got' => $what]);
            }
        }

        return $this->providers = $valid;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> id => total uses over all providers (only the used ids); ONE query (or JSON read) per provider
     */
    public function counts(array $ids): array
    {
        $total = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->counts($ids) as $id => $n) {
                $total[(int) $id] = ($total[(int) $id] ?? 0) + (int) $n;
            }
        }

        return $total;
    }

    /** @return list<MediaUsage> */
    public function usagesOf(int $id): array
    {
        $usages = [];

        foreach ($this->providers() as $provider) {
            array_push($usages, ...$provider->usagesOf($id));
        }

        return $usages;
    }

    /** Narrows the query to the pictures NO provider uses. */
    public function excludeUsed(Builder $query): void
    {
        foreach ($this->providers() as $provider) {
            $provider->excludeUsed($query);
        }
    }

    /** Narrows the query to the pictures SOME provider uses: the complement of excludeUsed(), so no provider needs a second implementation. */
    public function onlyUsed(Builder $query): void
    {
        $unused = MediaAssetModel::query()->select('catalog_media.id');
        $this->excludeUsed($unused);

        $query->whereNotIn('catalog_media.id', $unused->toBase());
    }
}
