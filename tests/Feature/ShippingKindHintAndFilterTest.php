<?php

namespace Tests\Feature;

use App\Services\FreeShippingHintReader;
use App\Services\MethodQuote;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\BuildsAllKindsQuote;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\Support\Shipping\Fakes;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 5g-2b (shipping-domain-design.md 9.3.5, 9.3.4): the free-shipping hint knows the KINDS it is free for, and the
 * `shipping.quotes` filter runs once per kind with `destination_kind` in its context. Everything over HTTP, like the storefront.
 */
class ShippingKindHintAndFilterTest extends TestCase
{
    use BuildsAllKindsQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    private function single(?string $kind): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/shipping/quote', match ($kind) {
            'address' => ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'],
            'pickup' => ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia'],
            default => ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia', 'pickup_kind' => $kind],
        })->assertOk();
    }

    private function text(array $hint): string
    {
        return html_entity_decode((string) $hint['text']);
    }

    // ---- a single-kind quote only names what is free for ITS kind -----------------------------------------------------------

    public function test_a_single_kind_quote_never_names_a_method_that_is_not_free_for_that_kind(): void
    {
        $id = $this->akMethod('Locker deal', Scope::ANY, null, 600, 6000, [Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        $this->assertNull($this->single('address')->json('free_shipping_hint'));
        $this->assertNull($this->single('office')->json('free_shipping_hint'));
        $this->assertNull($this->single('pickup')->json('free_shipping_hint'), 'an unstated pickup could be an office, so it is not free');

        $hint = $this->single('locker')->json('free_shipping_hint');
        $this->assertSame($id, $hint['method_id']);
        $this->assertSame(['locker'], $hint['destinations']);
        $this->assertSame('Add 30.00 € more for free shipping with “Speedy – Locker deal”.', $this->text($hint), 'a single-kind quote keeps the plain sentence: it is about that kind');
    }

    public function test_the_text_is_byte_for_byte_today_s_when_the_free_set_covers_every_served_kind_and_destinations_is_always_present(): void
    {
        $id = $this->akMethod('Everywhere', Scope::ANY, null, 600, 6000, null, 'Speedy'); // null set: every served kind
        $this->akCart('30.00', 1);

        $all = $this->allQuote()->assertOk()->json('free_shipping_hint');
        $this->assertSame('Add 30.00 € more for free shipping with “Speedy – Everywhere”.', $this->text($all));
        $this->assertSame(['address', 'office', 'locker'], $all['destinations'], 'present also when the sentence omits the phrase');

        $street = $this->single('address')->json('free_shipping_hint');
        $this->assertSame($this->text($all), $this->text($street));
        $this->assertSame(['address'], $street['destinations']);
        $this->assertSame($id, $street['method_id']);
    }

    // ---- the kinds sentence, in both languages ---------------------------------------------------------------------------------

    public function test_the_all_quote_and_the_cart_name_the_kinds_the_method_is_free_for(): void
    {
        $id = $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);
        app(SiteSettingsRepository::class)->set('site.country', 'BG');

        $expected = 'Add 30.00 € more for free shipping to an office or a locker with “Speedy – Speedy any”.';

        $this->assertSame($expected, $this->text($this->allQuote()->assertOk()->json('free_shipping_hint')));

        $cart = $this->getJson('/api/cart')->assertOk()->json('free_shipping_hint');
        $this->assertSame($expected, $this->text($cart), 'the cart hint reads the broad zone with every kind in scope');
        $this->assertSame(['office', 'locker'], $cart['destinations']);
        $this->assertSame($id, $cart['method_id']);
    }

    public function test_the_phrase_in_bulgarian_for_one_and_for_two_kinds(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        $this->assertSame('Добавете още 30.00 € за безплатна доставка до офис или автомат с „Speedy – Speedy any“.', $this->text($this->allQuote()->assertOk()->json('free_shipping_hint')));

        $this->akCart('30.00', 1); // 60.00 in the cart: unlocked
        $this->assertSame('Доставката до офис или автомат с „Speedy – Speedy any“ е безплатна.', $this->text($this->allQuote()->assertOk()->json('free_shipping_hint')));
    }

    public function test_one_kind_and_the_kinds_joins_in_both_languages(): void
    {
        $join = new ReflectionMethod(FreeShippingHintReader::class, 'kindsPhrase');
        $reader = app(FreeShippingHintReader::class);

        $this->assertSame('a locker', $join->invoke($reader, ['locker'], 'en'));
        $this->assertSame('an office or a locker', $join->invoke($reader, ['office', 'locker'], 'en'));
        $this->assertSame('an address, an office or a locker', $join->invoke($reader, ['address', 'office', 'locker'], 'en'));
        $this->assertSame('автомат', $join->invoke($reader, ['locker'], 'bg'));
        $this->assertSame('офис или автомат', $join->invoke($reader, ['office', 'locker'], 'bg'));
        $this->assertSame('адрес, офис или автомат', $join->invoke($reader, ['address', 'office', 'locker'], 'bg'));
    }

    public function test_the_all_quote_sentence_for_a_single_free_kind_in_english(): void
    {
        $this->akMethod('Locker deal', Scope::ANY, null, 600, 6000, [Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        $hint = $this->allQuote()->assertOk()->json('free_shipping_hint');

        $this->assertSame('Add 30.00 € more for free shipping to a locker with “Speedy – Locker deal”.', $this->text($hint));
        $this->assertSame(['locker'], $hint['destinations']);
    }

    public function test_a_carrier_method_is_ignored_by_the_hint(): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(fn () => [Fakes::quote(450)]));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $this->akMethod('Acme', Scope::ANY, null, 0, 1000, null, null, 0, null, ShippingMethodKind::CARRIER, 'acme');
        $this->akCart('30.00', 1);

        $this->assertNull($this->allQuote()->assertOk()->json('free_shipping_hint'));
        $this->assertNull($this->single('address')->json('free_shipping_hint'));
    }

    // ---- the merchant filter -------------------------------------------------------------------------------------------------------

    public function test_the_filter_sees_destination_kind_on_every_call(): void
    {
        $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        $seen = [];
        Hook::filter('shipping.quotes', function (array $methods, array $context) use (&$seen): array {
            $seen[] = $context['destination_kind'];

            return $methods;
        });

        $this->allQuote()->assertOk();
        $this->assertSame(['address', 'office', 'locker'], $seen, 'one call per kind in an all quote');

        foreach ([['address', 'address'], ['office', 'office'], ['locker', 'locker'], ['pickup', 'pickup']] as [$request, $expected]) {
            $seen = [];
            $this->single($request)->assertOk();
            $this->assertSame([$expected], $seen, "one call for a single-kind quote ({$request})");
        }
    }

    public function test_a_filter_can_remove_a_method_for_one_kind_only_and_the_hint_follows(): void
    {
        $id = $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        Hook::filter('shipping.quotes', fn (array $methods, array $context): array => $context['destination_kind'] === 'office' ? [] : $methods);

        $json = $this->allQuote()->assertOk()->json();
        $speedy = collect($json['methods'])->firstWhere('id', $id);

        $this->assertSame(['address', 'locker'], array_keys($speedy['destinations']), 'gone from the office kind only');
        $this->assertSame(['locker'], $json['free_shipping_hint']['destinations'], 'the removed kind is not in scope for the hint');
        $this->assertSame('Add 30.00 € more for free shipping to a locker with “Speedy – Speedy any”.', $this->text($json['free_shipping_hint']));
        $this->assertSame(['address', 'locker'], $json['groups'][0]['destinations']);
    }

    /** @return array<string, array{0: ?list<string>, 1: ?list<string>}> */
    public static function changedFacts(): array
    {
        return ['the served kinds' => [['address'], null], 'the free kinds' => [null, ['address']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedFacts')]
    public function test_a_filter_cannot_change_the_kind_facts(?array $served, ?array $free): void
    {
        $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        Hook::filter('shipping.quotes', fn (array $methods): array => [$methods[0]->withKinds($served ?? $methods[0]->servedKinds, $free ?? $methods[0]->freeKinds)]);

        $this->allQuote()->assertStatus(500)->assertJsonPath('reason', 'quote_filter_invalid');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedFacts')]
    public function test_the_same_in_a_single_kind_quote(?array $served, ?array $free): void
    {
        $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        Hook::filter('shipping.quotes', fn (array $methods): array => [$methods[0]->withKinds($served ?? $methods[0]->servedKinds, $free ?? $methods[0]->freeKinds)]);

        $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia', 'pickup_kind' => 'office'])->assertStatus(500)->assertJsonPath('reason', 'quote_filter_invalid');
    }

    public function test_a_filter_that_rebuilds_a_quote_gets_the_methods_kind_facts_back(): void
    {
        $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $this->akCart('30.00', 1);

        Hook::filter('shipping.quotes', fn (array $methods): array => [MethodQuote::priced($methods[0]->methodId, $methods[0]->name, $methods[0]->kind, $methods[0]->requiresPickupPoint, $methods[0]->currency, 1)]);

        $json = $this->allQuote()->assertOk()->json();

        $this->assertSame(['office', 'locker'], $json['free_shipping_hint']['destinations'], 'the hint reads the method\'s own facts, not the rebuilt quote\'s');
        $this->assertSame([1, 1, 1], array_column(array_column(collect($json['methods'])->first()['destinations'], 'price'), 'minor'));
    }
}
