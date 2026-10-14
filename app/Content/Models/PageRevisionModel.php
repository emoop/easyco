<?php

namespace App\Content\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One saved text of a page (append-only, pages-design.md P-D2): never updated, never deleted. The writer inserts; anything else throws.
 *
 * @property int $page_id
 * @property int $revision
 * @property string $title
 * @property string $body SANITIZED html, exactly what was saved
 * @property ?int $staff_id
 */
class PageRevisionModel extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'page_revisions';

    protected $fillable = ['page_id', 'revision', 'title', 'body', 'staff_id'];

    protected $casts = ['revision' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A page revision is never updated.'));
        static::deleting(fn () => throw new LogicException('A page revision is never deleted.'));
    }

    public function page(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PageModel::class, 'page_id');
    }
}
