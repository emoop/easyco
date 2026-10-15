<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\OrderMatchReader;
use App\Services\PhoneBackfill;
use App\Services\PhoneNumbers;
use App\Models\OrderBlockModel;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Stage PH-1: the phone is validated by the rules of the DELIVERY country (libphonenumber, `isValidNumber`), stored as E.164, shown in the store's form,
 * keyed exactly in the identity keys, and the numbers already stored are converted by the migration. PhoneNumbers is the one place that reads a number.
 */
class PhoneValidationTest extends TestCase
{
    use BuildsShippingZones;
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const MESSAGE_BG = 'Телефонният номер не е валиден. Проверете дали не липсва цифра.';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        app()->setLocale('en');
    }

    private function place(string $phone, string $country = 'BG'): \Illuminate\Testing\TestResponse
    {
        return $this->checkout($this->streetFields($country), ['phone' => $phone]);
    }

    /** @return array<string, array{0: string}> */
    public static function bulgarianMobileForms(): array
    {
        return [
            'national' => ['0888123456'],
            'national with spaces' => ['0888 123 456'],
            'international +' => ['+359888123456'],
            'international 00 with spaces' => ['00359 888 123 456'],
            'with dashes and a leading space' => [' 0888-123-456 '],
        ];
    }

    // ---- checkout: the rule and the storage ------------------------------------------------------------------------------------------------

    #[DataProvider('bulgarianMobileForms')]
    public function test_a_bulgarian_mobile_in_every_form_is_stored_as_e164_on_the_order_the_snapshot_and_the_key(string $typed): void
    {
        $id = (string) $this->place($typed)->assertStatus(201)->json('order.id');

        $this->assertSame('+359888123456', DB::table('orders')->where('id', $id)->value('phone'));
        $this->assertSame('+359888123456', DB::table('order_placement_snapshots')->value('phone'), 'the placement snapshot');
        $this->assertSame('+359888123456', DB::table('orders')->where('id', $id)->value('phone_key'), 'the exact E.164 key');
    }

    public function test_one_digit_missing_is_a_422_on_the_phone_with_the_message_and_writes_nothing(): void
    {
        $this->place('088812345')->assertStatus(422)->assertJsonValidationErrors(['phone'])->assertJsonPath('errors.phone.0', 'The phone number is not valid. Check that no digit is missing.');

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_message_is_the_bulgarian_sentence_in_a_bulgarian_shop(): void
    {
        app()->setLocale('bg');

        $this->place('088812345')->assertStatus(422)->assertJsonPath('errors.phone.0', self::MESSAGE_BG);
    }

    public function test_one_digit_too_many_is_refused(): void
    {
        $this->place('08881234567')->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->place('+3598881234567')->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_a_number_that_is_only_possible_is_refused_because_validity_is_what_counts(): void
    {
        $util = \libphonenumber\PhoneNumberUtil::getInstance();
        $number = $util->parse('088812345', 'BG');

        $this->assertTrue($util->isPossibleNumber($number), 'the case the rule is for: possible by length...');
        $this->assertFalse($util->isValidNumber($number), '...and not a real number');
        $this->assertNull(PhoneNumbers::parse('088812345', 'BG'));
        $this->place('088812345')->assertStatus(422);
    }

    public function test_a_sofia_landline_is_accepted_and_is_a_fixed_line(): void
    {
        $id = (string) $this->place('02 123 4567')->assertStatus(201)->json('order.id');

        $this->assertSame('+35921234567', DB::table('orders')->where('id', $id)->value('phone'));
        $parsed = PhoneNumbers::parse('02 123 4567', 'BG');
        $this->assertSame('FIXED_LINE', $parsed->type);
        $this->assertTrue($parsed->isFixedLine);
        $this->assertFalse(PhoneNumbers::parse('0888 123 456', 'BG')->isFixedLine);
    }

    public function test_a_german_number_with_a_plus_is_accepted_for_a_bulgarian_delivery(): void
    {
        $id = (string) $this->place('+49 1511 2345678')->assertStatus(201)->json('order.id');

        $this->assertSame('+4915112345678', DB::table('orders')->where('id', $id)->value('phone'));
    }

    #[DataProvider('garbage')]
    public function test_letters_and_garbage_are_refused(string $typed): void
    {
        $this->place($typed)->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    /** @return array<string, array{0: string}> */
    public static function garbage(): array
    {
        return [
            'letters' => ['abcdefghij'],
            'a vanity number' => ['0888 CALL ME'],
            'only pluses' => ['++++++'],
            'too short' => ['12'],
            'an extension' => ['0888 123 456 ext 5'],
            'markup' => ['<b>0888123456</b>'],
            'longer than the guard' => [str_repeat('1', 33)],
            'a bidirectional character' => ["0888123456\u{202E}"],
        ];
    }

    public function test_the_delivery_country_decides_a_national_number(): void
    {
        $this->place('0888123456', 'DE')->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $id = (string) $this->place('0151 12345678', 'DE')->assertStatus(201)->json('order.id');
        $this->assertSame('+4915112345678', DB::table('orders')->where('id', $id)->value('phone'));
    }

    public function test_the_unit_reads_a_number_by_the_country_it_is_given(): void
    {
        $this->assertSame('+359888123456', PhoneNumbers::e164('0888123456', 'BG'));
        $this->assertSame('+359888123456', PhoneNumbers::e164('0888123456', 'bg'), 'the code is case-insensitive');
        $this->assertNull(PhoneNumbers::e164('0888123456', null), 'no country, no national number');
        $this->assertNull(PhoneNumbers::e164('0888123456', 'ZZ'));
        $this->assertSame('+359888123456', PhoneNumbers::e164('+359 888 123 456', null), 'a leading + needs no country');
    }

    // ---- the address API --------------------------------------------------------------------------------------------------------------------

    public function test_the_address_api_applies_the_same_rule_and_stores_e164(): void
    {
        $customer = $this->customerLogin();
        $payload = ['delivery_type' => 'street_address', 'recipient_first_name' => 'Ana', 'recipient_last_name' => 'Petrova', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha 1'];

        $this->actingAs($customer, 'customer')->postJson('/api/addresses', $payload + ['phone' => '088812345'])->assertStatus(422)->assertJsonPath('errors.phone.0', 'The phone number is not valid. Check that no digit is missing.');
        $this->actingAs($customer, 'customer')->postJson('/api/addresses', $payload + ['phone' => '0888 123 456'])->assertStatus(201);

        $this->assertSame('+359888123456', DB::table('addresses')->value('phone'));
        $this->assertSame(1, DB::table('addresses')->count());
    }

    private function customerLogin(): AccountModel
    {
        $account = Account::register('phone-'.uniqid().'@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);

        return AccountModel::findOrFail($account->id());
    }

    // ---- the identity key ---------------------------------------------------------------------------------------------------------------

    public function test_the_four_bulgarian_forms_are_one_key_and_one_match(): void
    {
        $ids = [];
        foreach (['0888123456', '0888 123 456', '+359888123456', '00359 888 123 456'] as $typed) {
            $ids[] = (string) $this->place($typed)->assertStatus(201)->json('order.id');
        }

        $this->assertSame(['+359888123456'], DB::table('orders')->pluck('phone_key')->unique()->values()->all());

        $this->app->forgetScopedInstances();
        $matches = app(OrderMatchReader::class)->forOrder(OrderModel::findOrFail($ids[0]));
        $this->assertCount(3, $matches['orders'], 'the three other orders match by the phone, whatever way it was typed');
    }

    public function test_the_key_is_exact_so_two_different_numbers_with_the_same_last_nine_digits_do_not_match(): void
    {
        $this->assertSame('+359888123456', \EasyCo\Order\IdentityKeys::phone('+359888123456'));
        $this->assertNotSame(\EasyCo\Order\IdentityKeys::phone('+359888123456'), \EasyCo\Order\IdentityKeys::phone('+4915888123456'));
        $this->assertSame('888123456', \EasyCo\Order\IdentityKeys::phone('0888 123 456'), 'an unparsed old number keeps the old fallback');
        $this->assertNull(\EasyCo\Order\IdentityKeys::phone('1234567'));
    }

    public function test_the_phone_key_column_accepts_e164_and_the_old_nine_digits_and_refuses_anything_else(): void
    {
        $id = (string) $this->place('0888123456')->assertStatus(201)->json('order.id');

        DB::table('orders')->where('id', $id)->update(['phone_key' => '888123456']);
        DB::table('orders')->where('id', $id)->update(['phone_key' => '+359888123456']);

        $this->expectException(QueryException::class);
        DB::table('orders')->where('id', $id)->update(['phone_key' => '0888 123']);
    }

    // ---- display: the admin ---------------------------------------------------------------------------------------------------------

    public function test_the_phone_formats_for_display(): void
    {
        $this->assertSame('0888 123 456', PhoneNumbers::formatForDisplay('+359888123456', 'BG'));
        $this->assertSame('02 123 4567', PhoneNumbers::formatForDisplay('+35921234567', 'BG'));
        $this->assertSame('+49 1511 2345678', PhoneNumbers::formatForDisplay('+4915112345678', 'BG'), 'international for another country');
        $this->assertSame('+359 88 812 3456', PhoneNumbers::formatForDisplay('+359888123456', 'DE'));
        $this->assertSame('0888 1234', PhoneNumbers::formatForDisplay('0888 1234', 'BG'), 'an old number that cannot be read is shown as it is');
    }

    public function test_the_admin_view_shows_the_store_form_and_a_landline_badge_only_for_a_fixed_line(): void
    {
        $mobile = (string) $this->place('0888123456')->assertStatus(201)->json('order.id');
        $landline = (string) $this->place('02 123 4567')->assertStatus(201)->json('order.id');
        $this->actingAsStaff('Administrator');

        $html = $this->get(OrderResource::getUrl('view', ['record' => $mobile]))->assertOk()->getContent();
        $this->assertStringContainsString('0888 123 456', $html);
        $this->assertStringNotContainsString('landline', $html, 'no badge for a mobile');

        $this->app->forgetScopedInstances();
        $html = $this->get(OrderResource::getUrl('view', ['record' => $landline]))->assertOk()->getContent();
        $this->assertStringContainsString('02 123 4567', $html);
        $this->assertStringContainsString('landline', $html, 'the badge: the courier SMS will not reach it');
    }

    // ---- the order editor ---------------------------------------------------------------------------------------------------------------

    public function test_the_order_editor_applies_the_same_rule_and_stores_e164(): void
    {
        $id = (string) $this->place('0888123456')->assertStatus(201)->json('order.id');
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(ViewOrder::class, ['record' => $id]);
        $component->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $seeded = $component->instance()->mountedActions[0]['data'];
        $this->assertSame('0888 123 456', $seeded['delivery']['phone'], 'the form shows the store form');

        $component->fillForm(['lines' => $seeded['lines'], 'delivery' => array_merge($seeded['delivery'], ['phone' => '088812345'])])->callMountedAction()->assertHasFormErrors();
        $this->assertSame('+359888123456', DB::table('orders')->where('id', $id)->value('phone'), 'a refused number changes nothing');

        $component->fillForm(['lines' => $seeded['lines'], 'delivery' => array_merge($seeded['delivery'], ['phone' => '0877 654 321'])])->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame('+359877654321', DB::table('orders')->where('id', $id)->value('phone'));
        $this->assertSame('+359877654321', DB::table('orders')->where('id', $id)->value('phone_key'), 'the key follows');
        $this->assertSame('+359888123456', DB::table('order_placement_snapshots')->value('phone'), 'the placement snapshot is write-once');
    }

    // ---- the migration ---------------------------------------------------------------------------------------------------------------------

    public function test_the_backfill_converts_what_parses_leaves_the_rest_counts_and_is_idempotent(): void
    {
        $a = (string) $this->place('0888123456')->assertStatus(201)->json('order.id');
        $b = (string) $this->place('0877 654 321')->assertStatus(201)->json('order.id');
        $c = (string) $this->place('0877 000 111')->assertStatus(201)->json('order.id');

        // Old rows, as they were before PH-1: typed text, an old key, one that cannot be read.
        DB::table('orders')->where('id', $a)->update(['phone' => '0888 123 456', 'phone_key' => '888123456']);
        DB::table('order_placement_snapshots')->where('order_id', $a)->update(['phone' => '0888 123 456']);
        DB::table('orders')->where('id', $b)->update(['phone' => '1234567', 'phone_key' => null]);
        DB::table('order_placement_snapshots')->where('order_id', $b)->update(['phone' => '1234567']);

        $address = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', 'Petrova', '+359888111222', null, 'BG', 'Sofia', addressLine1: 'Vitosha 1');
        app(AddressRepository::class)->save($address);
        DB::table('addresses')->where('id', $address->id())->update(['phone' => '0888 111 222']);

        OrderBlockModel::query()->create(['type' => 'phone', 'value_key' => '888123456', 'value_display' => '0888 123 456']);
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\StoreCountry::KEY, 'BG');

        $migration = require base_path('database/migrations/2026_10_20_000002_normalise_phones_to_e164.php');
        ob_start();
        $migration->up();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('orders: converted 1, left 1', $output);
        $this->assertStringContainsString('addresses: converted 1, left 0', $output);
        $this->assertStringContainsString('order_placement_snapshots: converted 1, left 1', $output);
        $this->assertStringContainsString('order_blocks (phone): converted 1, left 0', $output);

        $this->assertSame(['+359888123456', '+359888123456'], [DB::table('orders')->where('id', $a)->value('phone'), DB::table('orders')->where('id', $a)->value('phone_key')]);
        $this->assertSame('1234567', DB::table('orders')->where('id', $b)->value('phone'), 'left untouched');
        $this->assertSame('+359877000111', DB::table('orders')->where('id', $c)->value('phone'), 'already E.164');
        $this->assertSame('+359888111222', DB::table('addresses')->where('id', $address->id())->value('phone'));
        $this->assertSame('+359888123456', OrderBlockModel::query()->sole()->value_key);

        $again = PhoneBackfill::run();
        $this->assertSame(0, $again['tables']['orders']['converted'] + $again['tables']['addresses']['converted'] + $again['tables']['order_placement_snapshots']['converted'] + $again['blocks']['converted'], 'idempotent');
        $this->assertStringNotContainsString('1234567', $output, 'counts only, never a number');
        $this->assertStringNotContainsString('888', $output);
    }

    public function test_the_widening_migration_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_10_20_000001_widen_orders_phone_key_check.php');
        $migration->up();
        $migration->up();

        $clause = (string) DB::table('information_schema.check_constraints')->where('constraint_schema', DB::getDatabaseName())->where('constraint_name', 'ord_phone_key_check')->value('check_clause');
        $this->assertStringContainsString('+', $clause);
    }
}
