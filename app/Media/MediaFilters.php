<?php

namespace App\Media;

use App\Media\Usage\MediaUsageRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The filters of the «Медия» grid as plain query fragments (kind, use, state, month), in ONE place: the table's filters call them, and so does the «delete all unused of this filter» job, which
 * re-runs the same query from the stored filter VALUES (not from a list of ids). Sorting is not here: it does not change which rows match.
 */
final class MediaFilters
{
    /** @param  array<string, mixed>  $values  `kind`, `use`, `state`, `month` (all optional) */
    public static function query(array $values): Builder
    {
        $query = MediaAssetModel::query();

        self::kind($query, self::value($values, 'kind'));
        self::use($query, self::value($values, 'use'));
        self::state($query, self::value($values, 'state'));
        self::month($query, (string) self::value($values, 'month'));

        return $query;
    }

    public static function kind(Builder $query, ?string $kind): Builder
    {
        return match ($kind) {
            'image' => $query->whereIn('catalog_media.type', ['image', 'social_image']),
            'video' => $query->whereIn('catalog_media.type', ['video', 'social_video']),
            default => $query,
        };
    }

    public static function use(Builder $query, ?string $use): Builder
    {
        $registry = new MediaUsageRegistry;

        match ($use) {
            'used' => $registry->onlyUsed($query),
            'unused' => $registry->excludeUsed($query),
            default => null,
        };

        return $query;
    }

    public static function month(Builder $query, string $month): Builder
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return $query;
        }

        $start = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00');

        return $query->where('catalog_media.created_at', '>=', $start)->where('catalog_media.created_at', '<', $start->copy()->addMonth());
    }

    /** The «състояние» filter in SQL, mirroring MediaResource::stateOf(): the rule is the same, written once for the rows and once for the query. */
    public static function state(Builder $query, ?string $state): Builder
    {
        $noVariants = fn (Builder $q): Builder => $q->where(fn ($i) => $i->whereNull('catalog_media.variants')->orWhere('catalog_media.variants', '[]'));
        $notVariantFile = fn (Builder $q): Builder => $q->where('catalog_media.path', 'not like', '%-thumbnail.webp')->where('catalog_media.path', 'not like', '%-medium.webp')->where('catalog_media.path', 'not like', '%-large.webp')->where('catalog_media.path', 'not like', '%-admin_grid.webp');

        return match ($state) {
            'processing' => $query->whereNull('catalog_media.missing_at')->whereIn('catalog_media.processing_status', ['pending', 'processing'])->whereIn('catalog_media.type', ['image', 'social_image']),
            'failed' => $query->whereNull('catalog_media.missing_at')->where('catalog_media.processing_status', 'failed')->whereIn('catalog_media.type', ['image', 'social_image']),
            'missing' => $query->where(fn ($q) => $q
                ->whereNotNull('catalog_media.missing_at')
                ->orWhere(fn ($i) => $i->whereIn('catalog_media.type', ['image', 'social_image'])->where('catalog_media.processing_status', 'ready')->where(fn ($v) => $noVariants($v))->where(fn ($v) => $notVariantFile($v)))),
            'ready' => $query->whereNull('catalog_media.missing_at')->where('catalog_media.processing_status', 'ready')
                ->where(fn ($q) => $q->whereIn('catalog_media.type', ['video', 'social_video'])->orWhere(fn ($i) => $i->whereNotNull('catalog_media.variants')->where('catalog_media.variants', '!=', '[]')->orWhere(fn ($v) => $v->where('catalog_media.path', 'like', '%-thumbnail.webp')->orWhere('catalog_media.path', 'like', '%-medium.webp')->orWhere('catalog_media.path', 'like', '%-large.webp')->orWhere('catalog_media.path', 'like', '%-admin_grid.webp')))),
            default => $query,
        };
    }

    /** @param  array<string, mixed>  $values */
    private static function value(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
