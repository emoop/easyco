<?php

namespace App\Media\Usage;

use App\Filament\Resources\HtmlBlockResource;
use Illuminate\Database\Eloquent\Builder;

/** `html_blocks.card.picture` (HB-5): the picture of a «Снимка с текст» block, a media id inside the card's JSON. ONE read of the styled blocks, scanned in PHP (a shop has dozens). */
final class HtmlBlockCards implements MediaUsageProvider
{
    /** @var list<array{block: int, title: string, id: int}>|null */
    private ?array $uses = null;

    public function __construct(private readonly HtmlBlockRows $blocks = new HtmlBlockRows)
    {
    }

    public function counts(array $ids): array
    {
        $wanted = array_flip($ids);
        $counts = [];

        foreach ($this->uses() as $use) {
            isset($wanted[$use['id']]) && $counts[$use['id']] = ($counts[$use['id']] ?? 0) + 1;
        }

        return $counts;
    }

    public function usagesOf(int $id): array
    {
        $usages = [];

        foreach ($this->uses() as $use) {
            $use['id'] === $id && $usages[] = new MediaUsage('html_block_card', __('media.usage.html_block_card', ['name' => $use['title']]), HtmlBlockResource::getUrl('edit', ['record' => $use['block']]));
        }

        return $usages;
    }

    public function excludeUsed(Builder $query): void
    {
        $used = array_values(array_unique(array_column($this->uses(), 'id')));

        $used !== [] && $query->whereNotIn('catalog_media.id', $used);
    }

    /** @return list<array{block: int, title: string, id: int}> */
    private function uses(): array
    {
        if ($this->uses !== null) {
            return $this->uses;
        }

        $found = [];

        foreach ($this->blocks->all() as $block) {
            $card = ($block->kind ?? 'text') === 'card' && is_string($block->card ?? null) ? json_decode($block->card, true) : null;
            $id = is_array($card) ? ($card['picture'] ?? null) : null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $found[] = ['block' => (int) $block->id, 'title' => (string) $block->title, 'id' => (int) $id];
            }
        }

        return $this->uses = $found;
    }
}
