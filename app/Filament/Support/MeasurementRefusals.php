<?php

namespace App\Filament\Support;

use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Exceptions\MeasurementRefusedException;
use Filament\Notifications\Notification;
use Throwable;

/**
 * MEAS-3: the one place where a refusal of the measurements data layer becomes the merchant's text. Both exception classes carry a stable `reason`; every reason has a key under
 * `measurements.refusals.*` (a test reads the two classes and fails if a reason has none), so a refusal is never shown as a raw English message.
 */
final class MeasurementRefusals
{
    public static function isRefusal(Throwable $e): bool
    {
        return $e instanceof InvalidMeasurementValueException || $e instanceof MeasurementRefusedException;
    }

    public static function reason(Throwable $e): string
    {
        return $e instanceof InvalidMeasurementValueException || $e instanceof MeasurementRefusedException ? $e->reason : 'generic';
    }

    public static function message(Throwable $e): string
    {
        $key = 'measurements.refusals.'.self::reason($e);
        $text = __($key);

        return $text === $key ? __('measurements.refusals.generic') : $text;
    }

    /** The red notice used by the row actions and by the pages for a refusal that has no field of its own. */
    public static function notify(Throwable $e, string $titleKey): void
    {
        Notification::make()->title(__($titleKey))->body(self::message($e))->danger()->send();
    }
}
