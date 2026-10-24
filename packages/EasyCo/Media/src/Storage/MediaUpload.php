<?php

namespace EasyCo\Media\Storage;

use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Exceptions\ImageNotCleanableException;
use Illuminate\Validation\ValidationException;

/**
 * The one way an admin form stores an uploaded file (ML-0): through MediaStorageAdapter::store() (which strips the metadata of an image), with a picture that cannot be cleaned turned into a
 * FIELD ERROR of the form (a ValidationException on the field's state path) instead of an exception the person sees as a 500.
 */
final class MediaUpload
{
    /** The stored path. @throws ValidationException on the `$field` when the picture cannot be cleaned (nothing is stored then) */
    public static function path(string $content, string $originalFilename, string $field): string
    {
        try {
            return app(MediaStorageAdapter::class)->store($content, $originalFilename)->path;
        } catch (ImageNotCleanableException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
