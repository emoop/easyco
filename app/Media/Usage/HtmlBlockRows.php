<?php

namespace App\Media\Usage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Every HTML block (`id`, `title`, `pictures`, `body`) read ONCE for the two providers that look into blocks (HtmlBlockPictures: the picture list; HtmlBlockBody: the pictures inside the text). */
final class HtmlBlockRows
{
    /** @var Collection<int, object>|null */
    private ?Collection $rows = null;

    /** @return Collection<int, object> */
    public function all(): Collection
    {
        // HB-4: every column, so the style (a JSON with the background picture's id) rides on the same read when the column exists (and the read is still ONE query)
        return $this->rows ??= DB::table('html_blocks')->orderBy('id')->get();
    }
}
