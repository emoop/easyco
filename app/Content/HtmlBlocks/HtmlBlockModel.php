<?php

namespace App\Content\HtmlBlocks;

use App\Storefront\Conditions\ProductConditions;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A reusable piece of rich content (MENU-2): the picture next to a mega menu today, design slots later. The body is ALWAYS passed through HtmlBlockSanitizer on save
 * (whoever writes it: the admin form, the data migration or a script) and again when it is printed.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property ?list<array{media_asset_id: int, alt: string, url: string}> $pictures
 * @property ?array<string, mixed> $style HB-1: the «Стил на блока» (null = no style), normalised by HtmlBlockStyle on every save
 * @property string $kind HB-5: `text` (the block as it always was) or `card` («Снимка с текст»); set when the block is created and never changed
 * @property ?array<string, mixed> $card HB-5: the settings of a `card` block (null for a text block), normalised by HtmlBlockCard on every save
 * @property ?array<string, mixed> $conditions HB-2: «Показвай, когато…» (null = shown everywhere), normalised by ProductConditions on every save
 */
class HtmlBlockModel extends Model
{
    protected $table = 'html_blocks';

    protected $fillable = ['title', 'kind', 'body', 'pictures', 'style', 'conditions', 'card'];

    protected $casts = ['pictures' => 'array', 'style' => 'array', 'conditions' => 'array', 'card' => 'array'];

    /** HB-1: set by `saving`, read by `saved`: the stored style changed with this save (the token stylesheet must be rebuilt). HB-5: the same for a card's settings (its picture and custom colours are in the stylesheet). */
    private bool $styleChanged = false;

    protected static function booted(): void
    {
        static::saving(static function (HtmlBlockModel $block): void {
            // HB-5: the kind is chosen when the block is created and never changed (a change is ignored); an unknown kind is refused. A card keeps its settings in `card` and nothing in the text fields.
            $block->kind = $block->exists ? (string) $block->getOriginal('kind') : ((string) ($block->kind ?: 'text'));

            if (! in_array($block->kind, HtmlBlockCard::KINDS, true)) {
                throw new InvalidArgumentException('The kind of the block is not known.');
            }

            $cardChanged = false;

            if ($block->kind === 'card') {
                $cardChanged = $block->isDirty('card') || ! $block->exists;
                $block->card = HtmlBlockCard::storable($block->card);
                $block->body = '';
                $block->pictures = null;
                $block->style = null;
            } else {
                $block->card = null;
            }

            $block->body = HtmlBlockSanitizer::clean((string) $block->body);
            // MENU-5: the pictures are kept as clean() leaves them (at most 4, plain alt, an acceptable address); an empty list is null.
            $block->pictures = HtmlBlockPictures::clean($block->pictures) ?: null;

            // HB-1: the style is validated and stored normalised (only what differs from the defaults); all defaults = null. An invalid one is refused (the form validates first).
            $block->isDirty('style') && $block->style = HtmlBlockStyle::storable($block->style);
            // remembered for `saved`: did the stored style really change (a model that was created and is saved again in the same request is still «recently created»)?
            $block->styleChanged = ($block->isDirty('style') && ($block->style !== null || $block->getOriginal('style') !== null)) || $cardChanged;

            // HB-2: the «Показвай, когато…» conditions are validated (the ids must exist) and stored normalised; nothing set = null. The compiled right column follows through MenuRecompileListeners
            // (asked on EVERY save of a block), which carries the conditions together with the HTML.
            $block->isDirty('conditions') && $block->conditions = ProductConditions::storable($block->conditions);
        });

        // HB-1: a styled block's rule lives in the token stylesheet, so a change of the style (or a styled block that is gone) rebuilds it and replaces the reader's cache entry. The compiled copies that carry
        // the block's HTML (footer, right column, menus) follow through MenuRecompileListeners, which asks for their rebuild on EVERY save of a block (the wrapper is in the copies).
        static::saved(static function (HtmlBlockModel $block): void {
            $block->styleChanged && HtmlBlockStyle::rebuildStylesheet();
        });
        static::deleted(static function (HtmlBlockModel $block): void {
            ($block->style !== null || $block->kind === 'card') && HtmlBlockStyle::rebuildStylesheet();
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
                $rows[$id] = ['body' => (string) $loaded[$id]->body, 'pictures' => $loaded[$id]->pictures, 'style' => $loaded[$id]->style, 'kind' => (string) $loaded[$id]->kind, 'card' => $loaded[$id]->card];
            }
        }

        $missing = array_values(array_diff(array_unique($ids), array_keys($rows)));

        if ($missing !== []) {
            $columns = HtmlBlockStyle::hasColumn() ? ['id', 'body', 'pictures', 'style'] : ['id', 'body', 'pictures'];
            HtmlBlockCard::hasColumns() && array_push($columns, 'kind', 'card');

            foreach (DB::table('html_blocks')->whereIn('id', $missing)->get($columns) as $row) {
                $decoded = is_string($row->pictures) ? json_decode($row->pictures, true) : null;
                $rows[(int) $row->id] = ['body' => (string) $row->body, 'pictures' => is_array($decoded) ? $decoded : null, 'style' => $row->style ?? null, 'kind' => (string) ($row->kind ?? 'text'), 'card' => $row->card ?? null];
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
            // HB-5: a card is built from its validated settings (a card that is not valid prints nothing); its box is its wrapper, so it is NOT wrapped again
            if (($row['kind'] ?? 'text') === 'card') {
                $out[$id] = HtmlBlockSanitizer::render(HtmlBlockCard::html($id, $row['card'] ?? null));

                continue;
            }

            $html = HtmlBlockSanitizer::render(HtmlBlockPictures::html(is_array($row['pictures']) ? $row['pictures'] : [], $assets).$row['body']);
            // HB-1: THE one place a styled block gets its wrapper (`div.sf-hb.sf-hb-{id}`): the mega menu, the footer and the right column all print what this method returns. No style = no wrapper.
            $out[$id] = HtmlBlockStyle::active($row['style']) === null ? $html : HtmlBlockStyle::wrap($id, $html);
        }

        return $out;
    }
}
