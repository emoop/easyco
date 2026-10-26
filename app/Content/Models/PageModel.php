<?php

namespace App\Content\Models;

use App\Content\Exceptions\PageInUseException;
use App\Content\PageUsage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A content page (pages-design.md section 2): the table's read/write model. EVERY write of the text goes through App\Content\PageWriter
 * (sanitizing, the revision); this class only maps the table, and refuses to be deleted while the page is in use.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string $body SANITIZED html
 * @property string $status draft | published
 * @property int $revision
 */
class PageModel extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $table = 'pages';

    protected $fillable = [
        'slug', 'title', 'body', 'status', 'revision', 'meta_title', 'meta_description', 'noindex', 'published_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'noindex' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A page in use (the selected terms or privacy page, or the one an order's terms_version points at) is never deleted; the
        // restrict foreign key of the revisions refuses every other delete too (the history is evidence): archive instead.
        static::deleting(function (PageModel $page): void {
            $reason = app(PageUsage::class)->reason($page);

            if ($reason !== null) {
                throw new PageInUseException($reason);
            }
        });
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevisionModel::class, 'page_id')->orderByDesc('revision');
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }
}
