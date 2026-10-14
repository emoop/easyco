<?php

namespace Tests\Feature;

use App\Enums\AnalyticsConsent;
use App\Enums\AttributionCaptureStatus;
use App\Enums\AttributionSourceType;
use App\Enums\ConsentMode;
use App\Enums\ConsentSource;
use App\Enums\DeviceType;
use App\Enums\InvoiceRecipientType;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Order context, stage C2-A (order-context-design.md 2.1.1): the seven enums each have ONE label group per language that holds exactly their
 * values in enum order (the shape OrderEventTypeLabelsTest pins for the event types), so a label can never be missing in one language, and a
 * new case cannot ship without its words.
 */
class OrderContextEnumLabelsTest extends TestCase
{
    private const LOCALES = ['en', 'bg'];

    /** @return array<string, array{0: class-string<\BackedEnum>, 1: string}> */
    public static function enums(): array
    {
        return [
            'source' => [AttributionSourceType::class, 'orders.attribution_source_options'],
            'capture status' => [AttributionCaptureStatus::class, 'orders.attribution_capture_options'],
            'consent mode' => [ConsentMode::class, 'orders.consent_mode_options'],
            'analytics consent' => [AnalyticsConsent::class, 'orders.analytics_consent_options'],
            'consent source' => [ConsentSource::class, 'orders.consent_source_options'],
            'device type' => [DeviceType::class, 'orders.device_type_options'],
            'invoice recipient' => [InvoiceRecipientType::class, 'orders.invoice_recipient_options'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('enums')]
    public function test_every_case_has_a_label_in_both_languages_and_the_group_holds_exactly_the_cases(string $enum, string $group): void
    {
        $expected = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());

        foreach (self::LOCALES as $locale) {
            $labels = Lang::get($group, [], $locale, false);

            $this->assertIsArray($labels, "lang/{$locale}/orders.php must carry {$group}.");
            $this->assertSame($expected, array_keys($labels), "{$group} ({$locale}) must list exactly the enum values, in enum order.");

            foreach ($labels as $value => $label) {
                $this->assertIsString($label);
                $this->assertNotSame('', trim($label), "{$group}.{$value} ({$locale}) must not be blank.");
            }
        }
    }

    public function test_the_values_are_the_ones_the_design_names(): void
    {
        $values = static fn (string $enum): array => array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());

        $this->assertSame(['utm', 'organic', 'referral', 'direct', 'ai_assistant', 'social', 'email', 'admin', 'agent', 'unknown'], $values(AttributionSourceType::class));
        $this->assertSame(['captured', 'skipped_no_consent', 'skipped_no_data'], $values(AttributionCaptureStatus::class));
        $this->assertSame(['strict', 'notice', 'off'], $values(ConsentMode::class));
        $this->assertSame(['granted', 'denied', 'unknown'], $values(AnalyticsConsent::class));
        $this->assertSame(['banner', 'api', 'not_asked'], $values(ConsentSource::class));
        $this->assertSame(['desktop', 'mobile', 'tablet', 'bot', 'unknown'], $values(DeviceType::class));
        $this->assertSame(['company', 'individual'], $values(InvoiceRecipientType::class));
    }
}
