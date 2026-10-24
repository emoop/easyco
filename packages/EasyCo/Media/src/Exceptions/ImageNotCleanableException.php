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

    public static function unsupportedFormat(): self
    {
        $translated = function_exists('app') && app()->bound('translator') ? trans('media.not_cleanable') : null;

        return new self(is_string($translated) && $translated !== 'media.not_cleanable' ? $translated : self::MESSAGE_BG);
    }
}
