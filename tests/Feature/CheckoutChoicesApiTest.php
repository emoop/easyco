<?php

namespace Tests\Feature;

use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Payment\Adapters\CashOnDeliveryPaymentMethodAdapter;
use EasyCo\Payment\Registry\PaymentAvailabilityContext;
use EasyCo\Payment\Registry\PaymentMethodDescriptor;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-B (checkout-form-v2-design.md 6 and 8.2, order-context-design.md 9.1, 9.5, 9.6, 9.7): POST /api/checkout and
 * what the customer ticked. Every merchant setting on or off, the field sent or not; the terms asked, refused and recorded; the consent
 * sentence the SERVER writes; the replay; and a payment method that is not available for the order.
 */
class CheckoutChoicesApiTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRegionOrders();
    }

    private function set(string $key, ?string $value): void
    {
        $value === null ? app(SiteSettingsRepository::class)->forget($key) : app(SiteSettingsRepository::class)->set($key, $value);
    }

    /** The one order row the test just placed. */
    private function row(): object
    {
        return DB::table('orders')->orderByDesc('id')->first();
    }

    private function snapshot(): object
    {
        return DB::table('order_placement_snapshots')->orderByDesc('id')->first();
    }

    // ---- call before shipping and the newsletter --------------------------------------------------------------------------------------

    /** @return array<string, array{?string, array<string, mixed>, ?int}> setting, extra payload, stored */
    public static function callMatrix(): array
    {
        return [
            'off + true: ignored' => [null, ['call_before_shipping' => true], null],
            'off + false: ignored' => [null, ['call_before_shipping' => false], null],
            'off + absent' => [null, [], null],
            'on + true' => ['1', ['call_before_shipping' => true], 1],
            'on + false: offered, not ticked' => ['1', ['call_before_shipping' => false], 0],
            'on + absent: not answered' => ['1', [], null],
            'on + "1" as a string' => ['1', ['call_before_shipping' => '1'], 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('callMatrix')]
    public function test_call_before_shipping_is_recorded_only_when_the_setting_shows_it(?string $setting, array $extra, ?int $stored): void
    {
        $this->set(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, $setting);

        $this->checkout($this->streetFields('BG'), $extra)->assertStatus(201);

        $this->assertSame($stored, $this->row()->call_before_shipping === null ? null : (int) $this->row()->call_before_shipping);
        $this->assertSame($stored, $this->snapshot()->call_before_shipping === null ? null : (int) $this->snapshot()->call_before_shipping, 'the placement snapshot carries it too');
    }

    public function test_a_value_that_is_not_a_boolean_is_a_field_error(): void
    {
        $this->set(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, '1');

        $this->checkout($this->streetFields('BG'), ['call_before_shipping' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['call_before_shipping']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    /** @return array<string, array{?string, array<string, mixed>, ?int, bool}> */
    public static function consentMatrix(): array
    {
        return [
            'off + true: ignored' => [null, ['marketing_consent' => true], null, false],
            'off + absent' => [null, [], null, false],
            'on + true: the sentence is written' => ['1', ['marketing_consent' => true], 1, true],
            'on + false: no sentence' => ['1', ['marketing_consent' => false], 0, false],
            'on + absent' => ['1', [], null, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('consentMatrix')]
    public function test_the_newsletter_consent_and_its_sentence(?string $setting, array $extra, ?int $stored, bool $hasSentence): void
    {
        $this->set(CheckoutFormSettings::NEWSLETTER_KEY, $setting);

        $this->checkout($this->streetFields('BG'), $extra)->assertStatus(201);

        $row = $this->row();
        $this->assertSame($stored, $row->marketing_consent === null ? null : (int) $row->marketing_consent);
        $this->assertSame($hasSentence ? 'Subscribe me to the shop\'s newsletter with news and offers.' : null, $row->marketing_consent_text);
        $this->assertSame($row->marketing_consent_text, $this->snapshot()->marketing_consent_text);
    }

    public function test_the_consent_sentence_is_the_servers_in_the_stores_language_and_never_the_requests(): void
    {
        $this->set(CheckoutFormSettings::NEWSLETTER_KEY, '1');
        $this->set('site.locale', 'bg');

        $this->checkout($this->streetFields('BG'), ['marketing_consent' => true, 'marketing_consent_text' => 'I agree to anything you like', 'marketing_consent_sentence' => 'x'])->assertStatus(201);

        $this->assertSame('Абонирайте ме за бюлетина с новини и оферти на магазина.', $this->row()->marketing_consent_text);
        $this->assertStringNotContainsString('anything', (string) $this->row()->marketing_consent_text);

        $this->set('site.locale', 'en');
        $this->checkout($this->streetFields('BG'), ['marketing_consent' => true, 'marketing_consent_text' => 'Bulgarian text?'])->assertStatus(201);
        $this->assertSame('Subscribe me to the shop\'s newsletter with news and offers.', $this->row()->marketing_consent_text, 'the order\'s locale at placement');
    }

    public function test_confirmation_requested_is_stored_as_sent_and_null_when_absent(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->assertNull($this->row()->confirmation_requested);

        $this->checkout($this->streetFields('BG'), ['confirmation_requested' => false])->assertStatus(201);
        $this->assertSame(0, (int) $this->row()->confirmation_requested);

        $this->checkout($this->streetFields('BG'), ['confirmation_requested' => true])->assertStatus(201);
        $this->assertSame(1, (int) $this->row()->confirmation_requested);
    }

    // ---- the terms ------------------------------------------------------------------------------------------------------------------------

    private function termsOn(string $version = '2026-10-14'): void
    {
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/terms');
        $this->set(CheckoutFormSettings::TERMS_VERSION_KEY, $version);
        $this->set(CheckoutFormSettings::TERMS_ENABLED_KEY, null);
    }

    public function test_with_the_terms_on_an_acceptance_and_the_current_version_are_recorded_at_the_placement_instant(): void
    {
        $this->termsOn();
        $before = now()->subSecond();

        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => '2026-10-14'])->assertStatus(201);

        $row = $this->row();
        $this->assertSame('2026-10-14', $row->terms_version);
        $this->assertTrue(now()->addSecond()->greaterThanOrEqualTo($row->terms_accepted_at) && $before->lessThanOrEqualTo($row->terms_accepted_at), 'the placement instant, never a client timestamp');
        $this->assertSame([$row->terms_version, $row->terms_accepted_at], [$this->snapshot()->terms_version, $this->snapshot()->terms_accepted_at]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusedTerms(): array
    {
        return [
            'absent' => [[], 'terms_not_accepted'],
            'false' => [['terms_accepted' => false, 'terms_version' => '2026-10-14'], 'terms_not_accepted'],
            'the string "false"' => [['terms_accepted' => 'false', 'terms_version' => '2026-10-14'], 'terms_not_accepted'],
            'zero' => [['terms_accepted' => 0, 'terms_version' => '2026-10-14'], 'terms_not_accepted'],
            'accepted, no version' => [['terms_accepted' => true], 'terms_version_changed'],
            'accepted, an older version' => [['terms_accepted' => true, 'terms_version' => '2026-09-01'], 'terms_version_changed'],
            'accepted, a version that is not text' => [['terms_accepted' => true, 'terms_version' => ['x']], 'terms_version_changed'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedTerms')]
    public function test_an_unticked_missing_or_stale_acceptance_is_refused_with_its_own_reason_and_writes_nothing(array $extra, string $reason): void
    {
        $this->termsOn();

        $response = $this->checkout($this->streetFields('BG'), $extra)->assertStatus(422)->assertJsonPath('reason', $reason);

        $this->assertSame(__('checkout.'.$reason), $response->json('message'));
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_placement_snapshots')->count());
    }

    public function test_the_refusal_sentences_are_translated_by_the_store_language(): void
    {
        $this->termsOn();
        $this->set('site.locale', 'bg');

        $this->checkout($this->streetFields('BG'))->assertStatus(422)->assertJsonPath('message', 'Моля, приемете правилата и условията, за да направите поръчката.');
        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => 'old'])->assertStatus(422)
            ->assertJsonPath('message', 'Правилата и условията са променени, докато попълвахте поръчката. Моля, прочетете ги отново и потвърдете.');
    }

    public function test_with_the_terms_off_or_no_link_the_two_fields_are_prohibited_and_the_columns_stay_null(): void
    {
        // OFF by the switch (a link is set).
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/terms');
        $this->set(CheckoutFormSettings::TERMS_ENABLED_KEY, '0');
        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['terms_accepted', 'terms_version']);
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->assertSame([null, null], [$this->row()->terms_accepted_at, $this->row()->terms_version]);

        // ON, but there is no link to read the terms at: nothing is asked either.
        $this->set(CheckoutFormSettings::TERMS_ENABLED_KEY, null);
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, null);
        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['terms_accepted']);
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->assertSame([null, null], [$this->row()->terms_accepted_at, $this->row()->terms_version]);
    }

    public function test_an_unversioned_store_is_asked_with_the_word_unversioned(): void
    {
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, '/terms');

        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => 'unversioned'])->assertStatus(201);

        $this->assertSame('unversioned', $this->row()->terms_version);
    }

    // ---- the replay -------------------------------------------------------------------------------------------------------------------------

    public function test_a_double_submit_returns_the_order_with_the_values_it_was_placed_with_and_writes_nothing_twice(): void
    {
        $this->termsOn();
        $this->set(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, '1');
        $this->set(CheckoutFormSettings::NEWSLETTER_KEY, '1');
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => '2026-10-14', 'call_before_shipping' => true, 'marketing_consent' => true]);

        $first = $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $placed = (array) $this->row();

        // The merchant moves on between the two clicks: the replay is the SAME order, not a new decision.
        $this->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-12-01');
        $payload['call_before_shipping'] = false;
        $payload['marketing_consent'] = false;
        $payload['terms_version'] = '2026-12-01';
        $second = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $this->assertTrue($second->json('already_placed'));
        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('order_placement_snapshots')->count());
        $this->assertSame($placed, (array) $this->row(), 'the row is exactly what was placed: nothing was written twice');
        $this->assertSame([1, 1, '2026-10-14'], [(int) $this->row()->call_before_shipping, (int) $this->row()->marketing_consent, $this->row()->terms_version]);
    }

    // ---- the payment method registry -----------------------------------------------------------------------------------------------

    /** A TEST-ONLY extension: a third method, registered and bound exactly as a real extension's provider would; no core file is edited. */
    private function extension(string $code, ?\Closure $availability = null): void
    {
        app(PaymentMethodRegistry::class)->register(new PaymentMethodDescriptor(
            code: $code,
            label: fn (string $locale): string => 'Test card '.$locale,
            sortOrder: 5,
            availability: $availability,
        ));
        $this->app->bind('payment.adapter.'.$code, CashOnDeliveryPaymentMethodAdapter::class);
    }

    public function test_a_third_method_registered_by_an_extension_is_accepted_by_the_api(): void
    {
        $this->extension('test_card');

        $this->checkout($this->streetFields('BG'), ['payment_method' => 'test_card'])->assertStatus(201);

        $this->assertSame('test_card', DB::table('payments')->value('method'));
    }

    public function test_a_method_that_is_not_available_for_the_order_is_refused_and_nothing_is_written(): void
    {
        $seen = [];
        $this->extension('eur_only', function (PaymentAvailabilityContext $context) use (&$seen): bool {
            $seen[] = [$context->country, $context->currency, $context->deliveryKind, $context->totalMinor > 0];

            return $context->currency === 'USD';
        });

        $response = $this->checkout($this->streetFields('BG'), ['payment_method' => 'eur_only'])->assertStatus(422)->assertJsonPath('reason', 'payment_method_unavailable');

        $this->assertSame(__('checkout.payment_method_unavailable'), $response->json('message'));
        $this->assertSame([['BG', 'EUR', 'address', true]], array_slice($seen, -1), 'asked with the real order: country, currency, kind, total');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('order_placement_snapshots')->count());
    }

    public function test_the_availability_function_is_asked_with_the_delivery_kind_of_a_pickup_order(): void
    {
        $kinds = [];
        $this->extension('kinds', function (PaymentAvailabilityContext $context) use (&$kinds): bool {
            $kinds[] = $context->deliveryKind;

            return true;
        });

        $this->checkout($this->pickupFields(['pickup_point_kind' => 'locker']), ['payment_method' => 'kinds'])->assertStatus(201);

        $this->assertSame('locker', end($kinds));
    }

    public function test_the_built_in_methods_stay_available(): void
    {
        foreach (['cash_on_delivery', 'bank_transfer'] as $method) {
            $this->checkout($this->streetFields('BG'), ['payment_method' => $method])->assertStatus(201);
        }
    }
}
