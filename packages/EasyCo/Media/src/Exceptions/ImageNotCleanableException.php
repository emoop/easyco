<?php

namespace EasyCo\Media\Exceptions;

use RuntimeException;

/**
 * Thrown by MetadataStripper (and so by MediaStorageAdapter::store()) for an image that cannot be cleaned: a format that is not handled losslessly (HEIC, AVIF ...) and cannot be decoded on
 * this server, so it cannot be re-encoded without its metadata either. An image we cannot clean is never stored (ML-0, media-library-design.md ML-D8). The callers turn it into a
 * validation message (a form field error, an API 422), never a 500. The message is the shop's own text (`lang/{bg,en}/media.php`, key `not_cleanable`) when the app has one, else the Bulgarian default.
 */
final class ImageNotCleanableException extends RuntimeException
{
    public const MESSAGE_BG = 'Този формат не се поддържа на този сървър — инсталирайте ImageMagick или конвертирайте снимката.';

    public const MALFORMED_BG = 'Файлът е повреден или непълен — качете го отново.';

    /** An image whose structure could not be walked (a truncated JPEG ...): it cannot be cleaned, so it is never stored. */
    public static function malformed(): self
    {
        return new self(self::translated('media.malformed', self::MALFORMED_BG));
    }

    public static function unsupportedFormat(): self
    {
        return new self(self::translated('media.not_cleanable', self::MESSAGE_BG));
    }

    private static function translated(string $key, string $default): string
    {
        $translated = function_exists('app') && app()->bound('translator') ? trans($key) : null;

        return is_string($translated) && $translated !== $key ? $translated : $default;
    }
}
