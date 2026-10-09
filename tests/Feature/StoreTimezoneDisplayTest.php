<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\StaffPanelUser;
use App\Http\Middleware\ApplyStoreTimezone;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\OrderNoteRecorder;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Order;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * The merchant's own time zone (`site.timezone`) — that the setting decides
 * which wall clock the admin panel's date/time DISPLAYS are rendered in, and
 * that nothing below the display layer moves with it.
 *
 * DB-backed Feature test on purpose, like ApplyStoreLocaleTest: what is under
 * test is not a formatting helper but the WIRING — a middleware registered in
 * two genuinely separate pipelines, feeding Filament's own global timezone
 * manager, read by Filament's own table-column formatting. Only a real
 * request through the real panel can show that all of that holds end to end,
 * and only a real stored row can show that the stored instant does not move.
 *
 * Fixture helpers mirror OrderResourceTest/OrderViewPageTest's own
 * established shapes (orders are placed through the real
 * CheckoutOrchestrator, never hand-built rows).
 */
class StoreTimezoneDisplayTest extends TestCase
{
    use RefreshDatabase;
    use ProvidesCheckoutShipping;

    private static int $productCounter = 0;

    private ?PriceList $priceList = null;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsStaffRole(string $roleName): StaffPanelUser
    {
        $model = $this->staffWithRole($roleName);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        ));

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    /**
     * A real order with a known, hard-coded placement instant: 2026-09-20
     * 10:00:00 UTC. September is deliberate — both zones this test switches
     * between observe DST then (Europe/Sofia is UTC+3, America/New_York is
     * UTC-4), so the offsets asserted below are the real ones a merchant
     * would see in the panel, not a winter-only coincidence.
     */
    private function placeOrder(): Order
    {
        $variationId = $this->pricedPurchasableVariation();
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, 1, null, null);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            shippingMethodId: $this->shippingMethodId(), quoteHandle: $this->lostShippingHandle(), expectedShippingMinor: 0,
        );

        return app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-20 10:00:00'))->order();
    }

    /**
     * T1's own claim, end to end and through a real request: ONE stored
     * instant, two configured zones, two correctly different wall-clock
     * strings actually present in the rendered orders table — while the
     * column in the database still reads exactly what it read before.
     *
     * The rendered string asserted here is Filament's own default date-time
     * display format ('M j, Y H:i:s', confirmed in the installed v5.8.1
     * source: Filament\Support\Concerns\HasDefaultDataFormattingSettings),
     * formatted with translatedFormat() under this suite's locale — written
     * out as a literal rather than computed, so a change in either the
     * format or the timezone handling fails this test instead of silently
     * following it.
     */
    public function test_one_stored_instant_reads_in_the_merchants_zone_and_moves_when_the_setting_does(): void
    {
        $staff = $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        // Precondition, stated explicitly because the expected strings below
        // are English month names: this suite runs with APP_LOCALE=en
        // (.env.testing), and nothing in this test sets site.locale.
        $this->assertSame('en', app()->getLocale());

        $storedBefore = (string) DB::table('orders')->where('id', $order->id())->value('placed_at');
        $this->assertSame('2026-09-20 10:00:00', $storedBefore);

        $settings = app(SiteSettingsRepository::class);

        // (1) Nothing stored and no code-level default either: the reading an
        // installation had before this stage existed at all — Filament's own
        // fallback to config('app.timezone'), i.e. UTC, 10:00.
        config(['services.site.default_timezone' => null]);

        $this->get(OrderResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Sep 20, 2026 10:00:00');

        // (2) Still nothing stored, but this installation's own code-level
        // default is in place (config/services.php: Europe/Sofia) — EEST in
        // September, UTC+3 — so the SAME instant now reads 13:00, proving the
        // default itself is applied and not just the stored row.
        config(['services.site.default_timezone' => 'Europe/Sofia']);

        $this->get(OrderResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Sep 20, 2026 13:00:00');

        // A fresh request, same actor (the panel's own gotcha: the session's
        // password_hash_staff marker is re-asserted per panel request by the
        // established tests above).
        $this->actingAs($staff, 'staff');
        session()->forget('password_hash_staff');

        // (3) The merchant saves a different zone (the stored row now beats
        // the code-level default). America/New_York in September is EDT,
        // UTC-4: the SAME instant reads 06:00, and the two readings that are
        // no longer configured have genuinely left the page.
        $settings->set('site.timezone', 'America/New_York');

        $this->get(OrderResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Sep 20, 2026 06:00:00')
            ->assertDontSee('Sep 20, 2026 13:00:00')
            ->assertDontSee('Sep 20, 2026 10:00:00');

        // The whole point: display moved, storage did not — same row, same
        // column, byte-for-byte the same string as before both requests, and
        // still exactly the UTC instant the checkout caller passed in.
        $this->assertSame(
            $storedBefore,
            (string) DB::table('orders')->where('id', $order->id())->value('placed_at'),
        );
    }

    /**
     * The other half of "display-only": a service called AFTER the panel has
     * really applied a far-away zone still stores the caller's clock in UTC,
     * untouched. The order of operations is the test — the zone is stored, a
     * real panel page request runs (so the middleware has genuinely put
     * 'Pacific/Auckland' — UTC+12/+13 — into Filament's own manager in THIS
     * process), and only then is the service called. If a display zone leaked
     * into PHP's own default time zone, or into any DateTimeImmutable handed
     * to a service, this assertion is where it would show up first.
     */
    public function test_a_service_called_after_a_non_utc_display_zone_is_applied_still_stores_utc(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        app(SiteSettingsRepository::class)->set('site.timezone', 'Pacific/Auckland');

        $this->get(OrderResource::getUrl('index'))->assertOk();

        $this->assertSame('Pacific/Auckland', FilamentTimezone::get());

        app(OrderNoteRecorder::class)->record(
            (string) $order->id(),
            'Called the courier about this one',
            new DateTimeImmutable('2026-09-20 10:00:00'),
        );

        $this->assertSame(
            '2026-09-20 10:00:00',
            (string) DB::table('order_events')->where('order_id', $order->id())->value('occurred_at'),
        );
    }

    /**
     * The 'web' pipeline — which is the pipeline Livewire's own update
     * endpoint uses (see ApplyStoreTimezone's docblock), i.e. every table
     * sort, pagination click and form save on a panel page. Asserted on a
     * real request rather than only structurally, exactly as
     * ApplyStoreLocaleTest asserts App::getLocale() for its own setting.
     */
    public function test_the_setting_is_applied_on_a_real_web_group_request(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'America/New_York');

        $this->get('/');

        $this->assertSame('America/New_York', FilamentTimezone::get());
    }

    /**
     * The panel's own pipeline — a page GET runs through the panel's
     * middleware array and never through the 'web' group (confirmed in the
     * installed Filament source; see ApplyStoreTimezone's docblock), so this
     * is genuinely a second registration being exercised, not the same one
     * twice.
     */
    public function test_the_setting_is_applied_on_a_real_panel_request(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'America/New_York');

        $this->get('/admin/login')->assertOk();

        $this->assertSame('America/New_York', FilamentTimezone::get());
    }

    /**
     * BOTH registrations, pinned structurally: without the panel one, the
     * first render of every panel page is in UTC; without the 'web' one,
     * every Livewire round trip after it is. Neither line is decorative, so
     * neither is left to a comment.
     */
    public function test_the_middleware_is_registered_in_both_pipelines(): void
    {
        $this->assertContains(
            ApplyStoreTimezone::class,
            app('router')->getMiddlewareGroups()['web'],
        );

        $this->assertContains(
            ApplyStoreTimezone::class,
            Filament::getPanel('admin')->getMiddleware(),
        );
    }

    /**
     * When the whole mechanism is in place but no merchant has ever set the
     * zone, an existing installation must read exactly as it did before this
     * stage existed: Filament's manager falls back to config('app.timezone')
     * — UTC — and the code-level default named by config/services.php is what
     * a request applies instead.
     */
    public function test_the_time_zone_falls_back_to_the_code_level_default_the_setting_never_overrides(): void
    {
        // Pre-existing behaviour, unchanged for an installation that has
        // never saved this setting: Filament's own UTC default.
        config(['services.site.default_timezone' => null]);
        $this->get('/');
        $this->assertSame('UTC', FilamentTimezone::get());

        // And with the real default in place — no setting row anywhere in
        // the database — the panel renders in the merchant's own zone.
        config(['services.site.default_timezone' => 'Europe/Sofia']);
        $this->get('/');
        $this->assertSame('Europe/Sofia', FilamentTimezone::get());
    }
}
