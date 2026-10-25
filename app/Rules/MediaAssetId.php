<?php

namespace App\Rules;

use Closure;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The server-side check of a picture field of the media picker (ML-2, rule 5): the browser only ever sends MEDIA IDS, and each one must be a positive integer, name an existing row and a
 * PICTURE (`image` / `social_image`: a video id is refused for a picture field). A `multiple()` field sends a list: not more than `$max`, no id twice. Nothing else the client sends (a path,
 * an address, the size of a list) is trusted. An empty value passes: whether the field is required is the field's own `required()`.
 */
final class MediaAssetId implements ValidationRule
{
    public const TYPES = ['image', 'social_image'];

    /** The product video field (ML-3): a picture id is refused there as a video id is refused in a picture field. */
    public const VIDEO_TYPES = ['video'];

    /**
     * @param  list<string>  $types  the asset types the field accepts
     * @param  int|null  $minWidth  ML-3: the smallest accepted picture; a picture with no stored size yet (not backfilled) is refused too
     * @param  list<int|string>  $exempt  ids NOT checked against the minimum (pictures the record already has: saving a product must not fail because of an old small photo)
     */
    public function __construct(
        private readonly ?int $max = null,
        private readonly array $types = self::TYPES,
        private readonly ?int $minWidth = null,
        private readonly ?int $minHeight = null,
        private readonly array $exempt = [],
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $ids = $this->max === null ? [$value] : $value;

        if (! is_array($ids) || ($this->max !== null && count($ids) > $this->max)) {
            $fail(__('media.picker.errors.too_many', ['max' => $this->max ?? 1]));

            return;
        }

        $clean = [];

        foreach ($ids as $id) {
            if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
                $fail(__('media.picker.errors.invalid'));

                return;
            }

            $clean[] = (int) $id;
        }

        if (count(array_unique($clean)) !== count($clean)) {
            $fail(__('media.picker.errors.invalid'));

            return;
        }

        $found = MediaAssetModel::query()->whereIn('id', $clean)->whereIn('type', $this->types)->count();

        if ($found !== count($clean)) {
            $fail(__($this->types === self::VIDEO_TYPES ? 'media.picker.errors.not_a_video' : 'media.picker.errors.not_a_picture'));

            return;
        }

        $this->checkMinimum(array_values(array_diff($clean, array_map('intval', $this->exempt))), $fail);
    }

    /** @param  list<int>  $ids */
    private function checkMinimum(array $ids, Closure $fail): void
    {
        if ($ids === [] || ($this->minWidth === null && $this->minHeight === null)) {
            return;
        }

        foreach (MediaAssetModel::query()->whereIn('id', $ids)->get(['id', 'width', 'height']) as $row) {
            if ($row->width === null || $row->height === null) {
                $fail(__('media.picker.errors.unknown_size'));

                return;
            }

            if ((int) $row->width < (int) $this->minWidth || (int) $row->height < (int) $this->minHeight) {
                $fail(__('media.picker.errors.too_small', ['w' => $row->width, 'h' => $row->height, 'min_w' => $this->minWidth, 'min_h' => $this->minHeight]));

                return;
            }
        }
    }
}
