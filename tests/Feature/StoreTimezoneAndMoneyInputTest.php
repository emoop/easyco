<?php

namespace Tests\Feature;

use App\Services\MoneyInput;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refunds R3 part 2: the two small readers the dialogs and the return services share —
 * the store's timezone (service-side, no Filament) and a typed amount (no floats).
 */
class StoreTimezoneAndMoneyInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_store_timezone_is_the_stored_row_then_the_project_default_then_the_app_timezone(): void
    {
        $this->assertSame('Europe/Sofia', app(StoreTimezone::class)->current(), 'no row: the same default ApplyStoreTimezone gave Filament');

        app(SiteSettingsRepository::class)->set('site.timezone', 'America/New_York');
        $this->assertSame('America/New_York', app(StoreTimezone::class)->current());

        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
        config(['services.site.default_timezone' => null]);
        $this->assertSame('Europe/Sofia', app(StoreTimezone::class)->current(), 'the row still wins');

        $this->assertSame('UTC', config('app.timezone'), 'storage stays UTC: nothing here changes it');
    }

    public function test_the_app_timezone_is_the_last_resort(): void
    {
        config(['services.site.default_timezone' => null]);

        $this->assertSame('UTC', app(StoreTimezone::class)->current());
    }

    public function test_the_calendar_day_of_an_instant_is_read_in_the_store_zone_and_day_differences_keep_their_sign(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
        $store = app(StoreTimezone::class);

        $this->assertSame('2026-09-29', $store->dayOf(new DateTimeImmutable('2026-09-28 21:30:00', new DateTimeZone('UTC'))), '00:30 on the 29th in Sofia');
        $this->assertSame('2026-09-28', $store->dayOf(new DateTimeImmutable('2026-09-28 20:59:00', new DateTimeZone('UTC'))));
        $this->assertSame(14, StoreTimezone::daysBetween('2026-09-30', '2026-10-14'));
        $this->assertSame(0, StoreTimezone::daysBetween('2026-10-14', '2026-10-14'));
        $this->assertSame(-3, StoreTimezone::daysBetween('2026-10-14', '2026-10-11'));
        $this->assertSame(396, StoreTimezone::daysBetween('2026-09-29', '2027-10-30'));
    }

    public function test_a_typed_amount_takes_a_comma_or_a_dot_and_nothing_else(): void
    {
        $accepted = ['3,50' => 350, '3.50' => 350, '3.5' => 350, '12' => 1200, ' 7 ' => 700, "1\u{00A0}234,5" => 123450, '0' => 0, '0,05' => 5];

        foreach ($accepted as $input => $minor) {
            $this->assertSame($minor, MoneyInput::parse($input, 'EUR')?->minorValue(), json_encode($input));
        }

        foreach (['', 'abc', '-3', '+3', '1.2.3', '1,2,3', '3.555', '3,', ',5', '1e3', '12 EUR'] as $input) {
            $this->assertNull(MoneyInput::parse($input, 'EUR'), json_encode($input));
        }

        $this->assertTrue(MoneyInput::parseOrZero('', 'EUR')->isZero(), 'blank is nothing entered');
        $this->assertTrue(MoneyInput::parseOrZero('  ', 'EUR')->isZero());
        $this->assertNull(MoneyInput::parseOrZero('x', 'EUR'), 'typed but not an amount');
    }
}
