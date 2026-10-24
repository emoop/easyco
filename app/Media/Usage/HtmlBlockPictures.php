<?php

namespace App\Media\Usage;

use App\Filament\Resources\HtmlBlockResource;
use Illuminate\Database\Eloquent\Builder;

/** `html_blocks.pictures`, a JSON list `[{media_asset_id, alt, url}]`: ONE read of every block (a shop has dozens), scanned in PHP. */
final class HtmlBlockPictures implements MediaUsageProvider
{
    /** @var list<array{block: int, title: string, position: int, id: int}>|null every picture of every block, read once per instance */
    private ?array $pictures = null;

    public function __construct(private readonly HtmlBlockRows $blocks = new HtmlBlockRows)
    {
    }

    public function counts(array $ids): array
    {
        $wanted = array_flip($ids);
        $counts = [];

        foreach ($this->pictures() as $picture) {
            isset($wanted[$picture['id']]) && $counts[$picture['id']] = ($counts[$picture['id']] ?? 0) + 1;
        }

        return $counts;
    }

    public function usagesOf(int $id): array
    {
        $usages = [];

        foreach ($this->pictures() as $picture) {
            $picture['id'] === $id && $usages[] = new MediaUsage('html_block', __('media.usage.html_block', ['name' => $picture['title'], 'n' => $picture['position']]), HtmlBlockResource::getUrl('edit', ['record' => $picture['block']]));
        }

        return $usages;
    }

    public function excludeUsed(Builder $query): void
    {
        $used = array_values(array_unique(array_column($this->pictures(), 'id')));

        $used !== [] && $query->whereNotIn('catalog_media.id', $used);
    }

    /** @return list<array{block: int, title: string, position: int, id: int}> */
    private function pictures(): array
    {
        if ($this->pictures !== null) {
            return $this->pictures;
        }

        $found = [];

        foreach ($this->blocks->all() as $block) {
            $list = is_string($block->pictures) ? json_decode($block->pictures, true) : null;

            foreach (is_array($list) ? array_values($list) : [] as $position => $picture) {
                $id = is_array($picture) ? ($picture['media_asset_id'] ?? null) : null;

                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $found[] = ['block' => (int) $block->id, 'title' => (string) $block->title, 'position' => $position + 1, 'id' => (int) $id];
                }
            }
        }

        return $this->pictures = $found;
    }
}
