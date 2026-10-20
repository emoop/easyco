<?php

namespace App\Content\HtmlBlocks;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable piece of rich content (MENU-2): the picture next to a mega menu today, design slots later. The body is ALWAYS passed through HtmlBlockSanitizer on save
 * (whoever writes it: the admin form, the data migration or a script) and again when it is printed.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 */
class HtmlBlockModel extends Model
{
    protected $table = 'html_blocks';

    protected $fillable = ['title', 'body'];

    protected static function booted(): void
    {
        static::saving(static function (HtmlBlockModel $block): void {
            $block->body = HtmlBlockSanitizer::clean((string) $block->body);
        });

        // The last line of defence behind the admin message: a block a menu shows is never deleted.
        static::deleting(static fn (HtmlBlockModel $block): bool => HtmlBlockUsage::menus((int) $block->id) === []);
    }
}
