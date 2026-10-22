<?php

namespace App\Content\HtmlBlocks;

use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A reusable piece of rich content (MENU-2): the picture next to a mega menu today, design slots later. The body is ALWAYS passed through HtmlBlockSanitizer on save
 * (whoever writes it: the admin form, the data migration or a script) and again when it is printed.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property ?list<array{media_asset_id: int, alt: string, url: string}> $pictures
 */
class HtmlBlockModel extends Model
{
    protected $table = 'html_blocks';

    protected $fillable = ['title', 'body', 'pictures'];

    protected $casts = ['pictures' => 'array'];

    protected static function booted(): void
    {
        static::saving(static function (HtmlBlockModel $block): void {
            $block->body = HtmlBlockSanitizer::clean((string) $block->body);
            // MENU-5: the pictures are kept as clean() leaves them (at most 4, plain alt, an acceptable address); an empty list is null.
            $block->pictures = HtmlBlockPictures::clean($block->pictures) ?: null;
        });

        // The last line of defence behind the admin message: a block a menu or the footer shows is never deleted.
        static::deleting(static fn (HtmlBlockModel $block): bool => ! HtmlBlockUsage::isUsed((int) $block->id));
    }

    /**
     * What every place that prints an HTML block prints (the mega menu, the footer, whatever comes): the pictures FIRST (stacked, in order, each with its own address), then the rich
     * body, the WHOLE result through HtmlBlockSanitizer::render() so the same filter runs on all of it. The one method; nobody builds the markup a second time.
     */
    public function renderedHtml(): string
    {
        return self::renderMany([(int) $this->id], [(int) $this->id => $this])[(int) $this->id] ?? '';
    }

    /**
     * The rendered HTML of several blocks by id with ONE query for the blocks (and one for the assets of all their pictures, when any has pictures): what the compilers use.
     *
     * @param  list<int>  $ids
     * @param  array<int, HtmlBlockModel>  $loaded  blocks already in memory (not read again)
     * @return array<int, string> id => HTML ('' for a block that is gone or empty)
     */
    public static function renderMany(array $ids, array $loaded = []): array
    {
        $rows = [];

        foreach (array_values(array_unique($ids)) as $id) {
            if (isset($loaded[$id])) {
                $rows[$id] = ['body' => (string) $loaded[$id]->body, 'pictures' => $loaded[$id]->pictures];
            }
        }

        $missing = array_values(array_diff(array_unique($ids), array_keys($rows)));

        if ($missing !== []) {
            foreach (DB::table('html_blocks')->whereIn('id', $missing)->get(['id', 'body', 'pictures']) as $row) {
                $decoded = is_string($row->pictures) ? json_decode($row->pictures, true) : null;
                $rows[(int) $row->id] = ['body' => (string) $row->body, 'pictures' => is_array($decoded) ? $decoded : null];
            }
        }

        $assetIds = [];

        foreach ($rows as $row) {
            foreach (HtmlBlockPictures::clean($row['pictures']) as $picture) {
                $assetIds[] = $picture['media_asset_id'];
            }
        }

        $assets = $assetIds === [] ? [] : MediaAssetModel::query()->whereIn('id', array_unique($assetIds))->get()->keyBy('id')->all();
        $out = [];

        foreach ($rows as $id => $row) {
            $out[$id] = HtmlBlockSanitizer::render(HtmlBlockPictures::html(is_array($row['pictures']) ? $row['pictures'] : [], $assets).$row['body']);
        }

        return $out;
    }
}
