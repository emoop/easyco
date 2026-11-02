<?php

namespace App\Content\HtmlBlocks;

use EasyCo\Media\Events\MediaAssetReady;
use Illuminate\Support\Facades\DB;

/**
 * HB-4.1: when a media asset becomes ready, the stylesheet of the HTML blocks is rebuilt IF a block's background picture is that asset (a block whose picture was not ready when it was compiled has no picture
 * in its rule). One query over the blocks whose style names a picture at all; the id is compared after the style is decoded, never as text. A ready asset that no block uses costs nothing more.
 */
final class RecompileBlockPicturesOnReady
{
    public function handle(MediaAssetReady $event): void
    {
        if (! HtmlBlockStyle::hasColumn()) {
            return;
        }

        $id = (int) $event->mediaAssetId;

        $uses = DB::table('html_blocks')->whereNotNull('style')->where('style', 'like', '%bg_image%')->pluck('style')
            ->contains(static fn (mixed $style): bool => (HtmlBlockStyle::active($style)['bg_image'] ?? null) === $id);

        $uses && HtmlBlockStyle::rebuildStylesheet();
    }
}
