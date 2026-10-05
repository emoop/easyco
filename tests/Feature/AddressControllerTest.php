<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Address\Persistence\Eloquent\AddressModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddressControllerTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInAccount(string $email = 'user@example.com'): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());

        $this->actingAs($model, 'customer');

        return $model;
    }

    private function streetAddressPayload(array $overrides = []): array
    {
        return array_merge([
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'country' => 'BG',
            'city' => 'Sofia',
            'postal_code' => '1000',
            'address_line_1' => 'Vitosha Blvd 1',
        ], $overrides);
    }

    private function pickupPointPayload(array $overrides = []): array
    {
        return array_merge([
            'delivery_type' => 'pickup_point',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'country' => 'BG',
            'carrier_code' => 'econt',
            'pickup_point_reference' => 'office-1234',
            'settlement' => 'Sofia',
        ], $overrides);
    }

    // --- store() -------------------------------------------------------------

    public function test_a_guest_can_create_an_address_with_a_null_account_id(): void
    {
        $response = $this->postJson('/api/addresses', $this->streetAddressPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('account_id', null);

        $model = AddressModel::findOrFail($response->json('id'));
        $this->assertNull($model->account_id);
    }

    public function test_a_logged_in_customer_creating_an_address_auto_associates_their_account_id(): void
    {
        $account = $this->loggedInAccount();

        $response = $this->postJson('/api/addresses', $this->streetAddressPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('account_id', (string) $account->id);

        $model = AddressModel::findOrFail($response->json('id'));
        $this->assertSame($account->id, $model->account_id);
    }

    public function test_missing_recipient_name_returns_422(): void
    {
        $payload = $this->streetAddressPayload();
        unset($payload['recipient_name']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['recipient_name']);
    }

    public function test_missing_phone_returns_422(): void
    {
        $payload = $this->streetAddressPayload();
        unset($payload['phone']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone']);
    }

    public function test_street_address_payload_including_carrier_code_returns_422(): void
    {
        $payload = $this->streetAddressPayload(['carrier_code' => 'econt']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['carrier_code']);
    }

    public function test_pickup_point_payload_missing_settlement_returns_422(): void
    {
        $payload = $this->pickupPointPayload();
        unset($payload['settlement']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settlement']);
    }

    // --- the delivery country, owner decision D1 (stage 3.0b) -----------------------------------

    public function test_a_pickup_point_payload_without_a_country_returns_422(): void
    {
        $payload = $this->pickupPointPayload();
        unset($payload['country']);

        $this->postJson('/api/addresses', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country']);
    }

    public function test_the_http_layer_accepts_a_lowercase_country_and_stores_it_uppercase_for_both_delivery_types(): void
    {
        foreach ([$this->streetAddressPayload(['country' => 'bg']), $this->pickupPointPayload(['country' => 'bg'])] as $payload) {
            $response = $this->postJson('/api/addresses', $payload)->assertStatus(201);

            $response->assertJsonPath('country', 'BG');
            $this->assertSame('BG', AddressModel::findOrFail($response->json('id'))->country);
        }
    }

    public function test_a_padded_country_is_trimmed_before_it_is_validated(): void
    {
        $response = $this->postJson('/api/addresses', $this->pickupPointPayload(['country' => '  gr ']))->assertStatus(201);

        $this->assertSame('GR', $response->json('country'));
    }

    public function test_an_unknown_country_code_is_refused_at_http_for_both_delivery_types(): void
    {
        foreach (['ZZ', 'EU', 'UN', 'XYZ', 'B1', 'ß'] as $bad) {
            foreach ([$this->streetAddressPayload(['country' => $bad]), $this->pickupPointPayload(['country' => $bad])] as $payload) {
                $this->postJson('/api/addresses', $payload)
                    ->assertStatus(422)
                    ->assertJsonValidationErrors(['country']);
            }
        }

        $this->assertSame(0, AddressModel::count());
    }

    public function test_xk_is_accepted_as_a_delivery_country(): void
    {
        $this->postJson('/api/addresses', $this->pickupPointPayload(['country' => 'xk']))
            ->assertStatus(201)
            ->assertJsonPath('country', 'XK');
    }

    public function test_the_country_error_message_is_translated_in_english_and_bulgarian(): void
    {
        app()->setLocale('en');
        $this->postJson('/api/addresses', $this->pickupPointPayload(['country' => 'ZZ']))
            ->assertJsonPath('errors.country.0', __('delivery.country.invalid', [], 'en'));
        $this->assertStringContainsString('two-letter code', __('delivery.country.invalid', [], 'en'));

        app()->setLocale('bg');
        $this->postJson('/api/addresses', $this->pickupPointPayload(['country' => 'ZZ']))
            ->assertJsonPath('errors.country.0', __('delivery.country.invalid', [], 'bg'));
        $this->assertStringContainsString('двубуквения', __('delivery.country.invalid', [], 'bg'));

        $payload = $this->pickupPointPayload();
        unset($payload['country']);
        $this->postJson('/api/addresses', $payload)->assertJsonPath('errors.country.0', __('delivery.country.required', [], 'bg'));
    }

    public function test_update_normalizes_the_country_too(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->pickupPointPayload())->assertStatus(201)->json();

        $this->putJson("/api/addresses/{$created['id']}", $this->pickupPointPayload(['country' => 'ro']))
            ->assertStatus(200)
            ->assertJsonPath('country', 'RO');

        $this->putJson("/api/addresses/{$created['id']}", $this->pickupPointPayload(['country' => 'ZZ']))
            ->assertStatus(422);
        $this->assertSame('RO', AddressModel::findOrFail($created['id'])->country);
    }

    // --- index() ---------------------------------------------------------------

    public function test_index_without_authentication_returns_401(): void
    {
        $response = $this->getJson('/api/addresses');

        $response->assertStatus(401);
    }

    public function test_index_returns_only_the_logged_in_customers_own_addresses(): void
    {
        $account = $this->loggedInAccount('mine@example.com');
        $this->postJson('/api/addresses', $this->streetAddressPayload())->assertStatus(201);
        $this->postJson('/api/addresses', $this->pickupPointPayload())->assertStatus(201);

        // Another account's address must not leak into this listing.
        $otherAccount = Account::register('other@example.com', 'hashed-password');
        app(AccountRepository::class)->save($otherAccount);
        $otherAddress = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Someone Else',
            phone: '+359888000000',
            accountId: $otherAccount->id(),
            country: 'BG',
            city: 'Varna',
            addressLine1: 'Main St 1',
        );
        app(AddressRepository::class)->save($otherAddress);

        $this->actingAs(AccountModel::findOrFail($account->id), 'customer');
        $response = $this->getJson('/api/addresses');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains($otherAddress->id(), $ids);
    }

    // --- update() --------------------------------------------------------------

    public function test_update_happy_path_changes_fields_and_a_follow_up_index_reflects_it(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->streetAddressPayload())->json();

        $response = $this->putJson("/api/addresses/{$created['id']}", $this->streetAddressPayload([
            'recipient_name' => 'Petar Petrov',
            'city' => 'Plovdiv',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('id', $created['id']);
        $response->assertJsonPath('recipient_name', 'Petar Petrov');
        $response->assertJsonPath('city', 'Plovdiv');

        $index = $this->getJson('/api/addresses');
        $index->assertJsonPath('data.0.id', $created['id']);
        $index->assertJsonPath('data.0.recipient_name', 'Petar Petrov');
        $index->assertJsonPath('data.0.city', 'Plovdiv');
    }

    public function test_updating_another_accounts_address_returns_404_and_changes_nothing(): void
    {
        $owner = Account::register('owner@example.com', 'hashed-password');
        app(AccountRepository::class)->save($owner);
        $address = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Original Name',
            phone: '+359888111111',
            accountId: $owner->id(),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Original St 1',
        );
        app(AddressRepository::class)->save($address);

        $this->loggedInAccount('attacker@example.com');

        $response = $this->putJson("/api/addresses/{$address->id()}", $this->streetAddressPayload([
            'recipient_name' => 'Hijacked Name',
        ]));

        $response->assertStatus(404);

        $reloaded = app(AddressRepository::class)->findById($address->id());
        $this->assertSame('Original Name', $reloaded->recipientName());
    }

    public function test_updating_a_nonexistent_address_returns_404(): void
    {
        $this->loggedInAccount();

        $response = $this->putJson('/api/addresses/999999', $this->streetAddressPayload());

        $response->assertStatus(404);
    }

    public function test_update_violating_exclusivity_returns_422_and_leaves_the_stored_address_unchanged(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->streetAddressPayload())->json();

        $response = $this->putJson("/api/addresses/{$created['id']}", $this->streetAddressPayload([
            'carrier_code' => 'econt',
        ]));

        $response->assertStatus(422);

        $reloaded = app(AddressRepository::class)->findById((string) $created['id']);
        $this->assertSame('Ivan Ivanov', $reloaded->recipientName());
        $this->assertSame('Sofia', $reloaded->city());
        $this->assertNull($reloaded->carrierCode());
    }

    // --- Input hardening: length and content limits (input-hardening pass 1) -----

    /**
     * Every text field this endpoint stores in a varchar(255) column, plus the
     * phone, whose interim bound is 32 characters. The limit is in CHARACTERS,
     * never bytes — the Cyrillic values below are what proves that.
     *
     * @return array<string, array{string, int}>
     */
    public static function hardenedTextFields(): array
    {
        return [
            'recipient_name' => ['recipient_name', 255],
            'phone' => ['phone', 32],
            'city' => ['city', 255],
            'postal_code' => ['postal_code', 255],
            'address_line_1' => ['address_line_1', 255],
            'address_line_2' => ['address_line_2', 255],
        ];
    }

    #[DataProvider('hardenedTextFields')]
    public function test_one_character_too_many_is_a_422_field_error_and_writes_nothing(string $field, int $limit): void
    {
        $response = $this->postJson('/api/addresses', $this->streetAddressPayload([
            $field => str_repeat('я', $limit + 1),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, AddressModel::count());
    }

    #[DataProvider('hardenedTextFields')]
    public function test_a_value_at_the_limit_is_accepted_and_stored_unchanged(string $field, int $limit): void
    {
        $value = str_repeat('я', $limit);

        $response = $this->postJson('/api/addresses', $this->streetAddressPayload([$field => $value]));

        $response->assertStatus(201);
        $response->assertJsonPath($field, $value);

        $model = AddressModel::findOrFail($response->json('id'));

        $this->assertSame($value, $model->{$field});
        $this->assertSame($limit, mb_strlen($model->{$field}), 'The bound is in characters, not bytes.');
    }

    /**
     * Every field crossed with every refused character, built here rather than
     * with two attributes: PHPUnit runs one data provider at a time, it does not
     * take their product.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedCharacterCases(): array
    {
        $characters = [
            'a newline' => "line\nbreak",
            'a NUL byte' => "nul\0byte",
            'a tab' => "tab\tseparated",
            'a right-to-left override' => 'Prague'."\u{202E}".'Ames',
        ];

        $cases = [];

        foreach (array_keys(self::hardenedTextFields()) as $field) {
            foreach ($characters as $description => $value) {
                $cases["{$field} carrying {$description}"] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('refusedCharacterCases')]
    public function test_a_control_or_bidirectional_character_is_a_422_field_error_and_writes_nothing(string $field, string $value): void
    {
        $response = $this->postJson('/api/addresses', $this->streetAddressPayload([$field => $value]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, AddressModel::count());
    }

    /** @return array<string, array{string}> */
    public static function specialCharacterValues(): array
    {
        return [
            'a script tag' => ['<script>alert(1)</script>'],
            'an apostrophe, an ampersand and quotes' => ["O'Brien & Sons \"Ltd\""],
            'Cyrillic with punctuation' => ['ул. Витоша 1, София'],
        ];
    }

    /** Special characters are allowed and stored as typed — never stripped here. */
    #[DataProvider('specialCharacterValues')]
    public function test_special_characters_are_accepted_and_stored_unchanged(string $value): void
    {
        $response = $this->postJson('/api/addresses', $this->streetAddressPayload([
            'recipient_name' => $value,
            'address_line_1' => $value,
        ]));

        $response->assertStatus(201);

        $model = AddressModel::findOrFail($response->json('id'));

        $this->assertSame($value, $model->recipient_name);
        $this->assertSame($value, $model->address_line_1);
    }

    /** store() and update() share one rule set — this proves update() carries them too. */
    public function test_update_refuses_an_overlong_value_and_leaves_the_stored_address_unchanged(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->streetAddressPayload())->json();

        $response = $this->putJson("/api/addresses/{$created['id']}", $this->streetAddressPayload([
            'city' => str_repeat('я', 256),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['city']);

        $reloaded = app(AddressRepository::class)->findById((string) $created['id']);

        $this->assertSame('Sofia', $reloaded->city());
    }

    public function test_update_refuses_a_control_character_and_leaves_the_stored_address_unchanged(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->streetAddressPayload())->json();

        $response = $this->putJson("/api/addresses/{$created['id']}", $this->streetAddressPayload([
            'recipient_name' => "Ivan\nIvanov",
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['recipient_name']);

        $reloaded = app(AddressRepository::class)->findById((string) $created['id']);

        $this->assertSame('Ivan Ivanov', $reloaded->recipientName());
    }

    // --- Input hardening pass 2: the three pickup-point fields --------------------------

    /**
     * The three fields only a PICKUP_POINT delivery carries, all three stored in
     * varchar(255) columns. Their required_if/prohibited_if shape is untouched —
     * what these tests pin is the width and the character set.
     *
     * @return array<string, array{string, int}>
     */
    public static function hardenedPickupPointFields(): array
    {
        return [
            'carrier_code' => ['carrier_code', 255],
            'pickup_point_reference' => ['pickup_point_reference', 255],
            'settlement' => ['settlement', 255],
        ];
    }

    #[DataProvider('hardenedPickupPointFields')]
    public function test_a_pickup_point_value_at_the_limit_is_accepted_and_stored_unchanged(string $field, int $limit): void
    {
        $value = str_repeat('я', $limit);

        $response = $this->postJson('/api/addresses', $this->pickupPointPayload([$field => $value]));

        $response->assertStatus(201);
        $response->assertJsonPath($field, $value);

        $model = AddressModel::findOrFail($response->json('id'));

        $this->assertSame($value, $model->{$field});
        $this->assertSame($limit, mb_strlen($model->{$field}), 'The bound is in characters, not bytes.');
    }

    #[DataProvider('hardenedPickupPointFields')]
    public function test_a_pickup_point_value_one_character_too_many_is_a_422_field_error_and_writes_nothing(string $field, int $limit): void
    {
        $response = $this->postJson('/api/addresses', $this->pickupPointPayload([
            $field => str_repeat('я', $limit + 1),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, AddressModel::count());
    }

    /**
     * Every pickup-point field crossed with every refused character, built here
     * rather than with two attributes: PHPUnit runs one data provider at a time,
     * it does not take their product.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedPickupPointCharacters(): array
    {
        $characters = [
            'a newline' => "office\n1234",
            'a NUL byte' => 'office'."\0".'1234',
            'a tab' => "office\t1234",
            'a right-to-left override' => 'office'."\u{202E}".'1234',
        ];

        $cases = [];

        foreach (array_keys(self::hardenedPickupPointFields()) as $field) {
            foreach ($characters as $description => $value) {
                $cases["{$field} carrying {$description}"] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('refusedPickupPointCharacters')]
    public function test_a_control_or_bidirectional_character_in_a_pickup_point_field_is_a_422_and_writes_nothing(string $field, string $value): void
    {
        $response = $this->postJson('/api/addresses', $this->pickupPointPayload([$field => $value]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, AddressModel::count());
    }

    /** store() and update() share one rule set — this proves update() carries these too. */
    public function test_update_refuses_an_overlong_pickup_point_value_and_leaves_the_stored_address_unchanged(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->pickupPointPayload())->json();

        $response = $this->putJson("/api/addresses/{$created['id']}", $this->pickupPointPayload([
            'settlement' => str_repeat('я', 256),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settlement']);

        $reloaded = app(AddressRepository::class)->findById((string) $created['id']);

        $this->assertSame('Sofia', $reloaded->settlement());
    }
}
