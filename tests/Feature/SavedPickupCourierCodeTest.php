<?php

namespace Tests\Feature;

use App\Couriers\CourierDirectory;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * COUR-2a.6: a saved pick-up address of an INTEGRATED courier stores the courier's CODE (`speedy`), not its display name («Спиди»). One mapping
 * (CourierDirectory::canonicalCode): the code or the display name in either language, case and spacing ignored; any other text stays as it is.
 */
class SavedPickupCourierCodeTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $account = Account::register('courier-code@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');
        $this->withHeader('Referer', 'http://localhost/');
    }

    /** @return array<string, mixed> */
    private function pickup(string $carrier): array
    {
        return [
            'delivery_type' => 'pickup_point', 'recipient_first_name' => 'Ana', 'recipient_last_name' => 'Testova', 'phone' => '+359888111222', 'country' => 'BG',
            'carrier_code' => $carrier, 'settlement' => 'Харманли', 'pickup_point_address' => 'ул. Тестова 1', 'pickup_point_kind' => 'office',
        ];
    }

    public static function names(): array
    {
        return [
            'the code' => ['speedy', 'speedy'], 'bulgarian name' => ['Спиди', 'speedy'], 'english name' => ['Speedy', 'speedy'],
            'case and spaces' => ["  СПИДИ\u{00A0}", 'speedy'], 'econt' => ['Еконт', 'econt'], 'box now with a space' => ['box  now', 'boxnow'],
            'a courier that is not integrated keeps its name' => ['Local Courier', 'Local Courier'], 'a near miss is not a match' => ['Спиди експрес', 'Спиди експрес'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('names')]
    public function test_the_one_mapping(string $stored, string $expected): void
    {
        $this->assertSame($expected, app(CourierDirectory::class)->canonicalCode($stored));
    }

    public function test_nothing_in_nothing_out(): void
    {
        $this->assertNull(app(CourierDirectory::class)->canonicalCode(null));
        $this->assertSame('', app(CourierDirectory::class)->canonicalCode(''));
    }

    public function test_an_address_saved_under_a_display_name_is_stored_as_the_code_and_a_plain_courier_keeps_its_name(): void
    {
        $this->signIn();

        $this->postJson('/api/addresses', $this->pickup('Спиди'))->assertStatus(201)->assertJsonPath('carrier_code', 'speedy');
        $this->postJson('/api/addresses', $this->pickup('Local Courier'))->assertStatus(201)->assertJsonPath('carrier_code', 'Local Courier');

        $this->assertSame(['Local Courier', 'speedy'], DB::table('addresses')->orderBy('carrier_code')->pluck('carrier_code')->all());
    }

    public function test_a_row_stored_with_a_display_name_is_listed_under_the_code_so_the_page_offers_it_for_the_method(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/addresses', $this->pickup('speedy'))->assertStatus(201)->json('id');
        DB::table('addresses')->where('id', $id)->update(['carrier_code' => 'Спиди']);

        $this->getJson('/api/addresses')->assertOk()->assertJsonPath('data.0.carrier_code', 'speedy');
    }

    public function test_the_same_point_typed_again_under_the_other_spelling_is_the_same_saved_address(): void
    {
        $this->signIn();
        $this->postJson('/api/addresses', $this->pickup('speedy'))->assertStatus(201);

        $resolver = app(\App\Services\AddressResolver::class);
        $again = $resolver->resolveNew(\EasyCo\Address\Enums\AddressDeliveryType::PICKUP_POINT, 'Ana', 'Testova', '+359888111222', (string) DB::table('accounts')->value('id'), 'BG', null, null, null, null, 'Спиди', null, 'Харманли', null, 'ул. Тестова 1', 'office');

        $this->assertSame(1, DB::table('addresses')->count(), 'reused, not saved twice');
        $this->assertSame('speedy', $again->carrierCode());
    }

    public function test_the_migration_converts_the_display_names_of_integrated_couriers_and_leaves_the_rest(): void
    {
        $this->signIn();
        $kept = ['Local Courier', 'Спиди експрес', 'unknown'];
        $names = ['Спиди' => 'speedy', 'Еконт' => 'econt', ' Econt ' => 'econt', 'BOX NOW' => 'boxnow', 'speedy' => 'speedy'];

        foreach ([...array_keys($names), ...$kept] as $value) {
            $id = $this->postJson('/api/addresses', $this->pickup('speedy'))->assertStatus(201)->json('id');
            DB::table('addresses')->where('id', $id)->update(['carrier_code' => $value, 'pickup_point_address' => 'ул. '.md5($value)]);
        }

        $migration = require base_path('database/migrations/2026_11_29_000001_store_courier_codes_on_saved_pickup_addresses.php');
        $migration->up();
        $after = DB::table('addresses')->orderBy('id')->pluck('carrier_code')->all();
        $migration->up();   // twice: the same

        $this->assertSame([...array_values($names), ...$kept], $after);
        $this->assertSame($after, DB::table('addresses')->orderBy('id')->pluck('carrier_code')->all());
    }

    public function test_the_migration_is_safe_on_an_empty_table(): void
    {
        $migration = require base_path('database/migrations/2026_11_29_000001_store_courier_codes_on_saved_pickup_addresses.php');
        $migration->up();

        $this->assertSame(0, DB::table('addresses')->count());
    }
}
