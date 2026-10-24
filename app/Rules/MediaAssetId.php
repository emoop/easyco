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

    public function __construct(private readonly ?int $max = null)
    {
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

        $found = MediaAssetModel::query()->whereIn('id', $clean)->whereIn('type', self::TYPES)->count();

        if ($found !== count($clean)) {
            $fail(__('media.picker.errors.not_a_picture'));
        }
    }
}
