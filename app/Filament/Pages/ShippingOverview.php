<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Rules\KnownCountryCode;
use App\Rules\PlainText;
use App\Services\MoneyInput;
use App\Services\PriceDisplayFormatter;
use App\Services\ShippingMethodSummaryReader;
use App\Services\ShippingTestMethod;
use App\Services\ShippingTestResult;
use App\Services\ShippingTester;
use App\Services\ShippingZoneCoverageReader;
use App\Settings\CountryNames;
use App\Settings\StoreCountry;
use App\Settings\StoreLocale;
use BackedEnum;
use Closure;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\ShippingCourier;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\ShippingZone;
use EasyCo\Staff\Enums\Permission;
use Filament\Pages\Page;
use Filament\Panel;

/**
 * The read-only Shipping page (shipping-domain-design.md §12.3.5, stage 5a):
 * `/admin/shipping`, the first item of the new Shipping group. Three blocks — a
 * status block, an overview of every zone with its methods written as readable
 * sentences, and the "Try it" tool — and NOTHING here writes a shipping row (the
 * editors are stages 5b–5d). Facts, not enforcement; no severity colours; compact,
 * one phone-friendly column.
 *
 * WHO SEES IT: Permission::SHIPPING_MANAGE, checked in BOTH places the project
 * always checks — canAccess() and again at mount() — so a direct URL never trusts
 * the route alone.
 *
 * THE OVERVIEW READS IN A BOUNDED NUMBER OF QUERIES whatever the number of zones:
 * `allOrdered()` once, `forZones()` (methods + rates) once, the class list once
 * (shared with the "Try it" class Select and the summary reader), plus the page's
 * own auth/settings reads. The "Try it" tool composes the SAME pure pieces the
 * quote service uses (ShippingTester), issues no handle and writes nothing.
 */
class ShippingOverview extends Page
{
    use AuthorizesViaStaffPermission;

    protected string $view = 'filament.pages.shipping-overview';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    public const MAX_LINES = 50;

    public const MAX_QUANTITY = 9999;

    public ?string $country = null;

    public string $settlement = '';

    public string $postcode = '';

    public bool $pickupPoint = false;

    public string $goods = '';

    /** @var list<array{class: ?string, quantity: int}> */
    public array $lines = [];

    /** @var array<string, mixed>|null  the last "Try it" run, already view-ready */
    public ?array $result = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'shipping';
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SHIPPING;
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function getNavigationLabel(): string
    {
        return __('shipping.navigation_label');
    }

    public function getTitle(): string
    {
        return __('shipping.title');
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    /** Defined locally, not on the shared trait — see AuthorizesViaStaffPermission's own docblock. */
    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->country = app(StoreCountry::class)->currentOrNull();
        $this->lines = [['class' => null, 'quantity' => 1]];
    }

    public function addLine(): void
    {
        if (count($this->lines) < self::MAX_LINES) {
            $this->lines[] = ['class' => null, 'quantity' => 1];
        }
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 1 || ! isset($this->lines[$index])) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function run(): void
    {
        $currency = DefaultCurrency::get();
        $this->country = (string) KnownCountryCode::normalize($this->country);

        $this->validate([
            'country' => ['required', 'string', new KnownCountryCode],
            'settlement' => ['nullable', 'string', 'max:255', new PlainText],
            'postcode' => ['nullable', 'string', 'max:20', new PlainText],
            'pickupPoint' => ['boolean'],
            'goods' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($currency): void {
                if (MoneyInput::parse(is_string($value) ? $value : null, $currency) === null) {
                    $fail(__('shipping.try_it.invalid_amount'));
                }
            }],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'lines.*.class' => ['nullable', 'string', 'max:64'],
        ]);

        $goods = MoneyInput::parse($this->goods, $currency);

        if ($goods === null) {
            return; // the rule above already refused it — unreachable
        }

        $rateLines = [];

        foreach ($this->lines as $line) {
            $class = $line['class'] ?? null;

            $rateLines[] = new RateLine(
                is_string($class) && trim($class) !== '' ? $class : null,
                (int) $line['quantity'],
            );
        }

        $result = app(ShippingTester::class)->run(
            (string) $this->country,
            $this->settlement !== '' ? $this->settlement : null,
            $this->postcode !== '' ? $this->postcode : null,
            $this->pickupPoint,
            $goods->minorValue(),
            $rateLines,
        );

        $this->result = $this->toViewResult($result);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return $this->overview();
    }

    /**
     * The whole page's read, in a fixed number of queries whatever the number of zones.
     *
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $zones = app(ShippingZoneRepository::class)->allOrdered();
        $zoneIds = array_map(static fn (ShippingZone $zone): string => (string) $zone->id(), $zones);
        $methodsByZone = app(ShippingMethodRepository::class)->forZones($zoneIds);

        // The class list, read ONCE for both the summary reader and the "Try it" class Select.
        $classNames = [];
        foreach (app(ShippingClassRepository::class)->all() as $class) {
            $classNames[$class->code()] = $class->name();
        }

        $countryNames = CountryNames::forLocale(app(StoreLocale::class)->current());
        $reader = app(ShippingMethodSummaryReader::class);
        $coverage = app(ShippingZoneCoverageReader::class);

        $zoneRows = [];
        $activeMethods = 0;

        foreach ($zones as $index => $zone) {
            $methodRows = [];

            foreach ($methodsByZone[(int) $zone->id()] ?? [] as $method) {
                if ($method->isActive()) {
                    $activeMethods++;
                }

                $methodRows[] = [
                    'name' => $method->name(),
                    'summary' => $reader->summary($method, $classNames),
                ];
            }

            $zoneRows[] = [
                'number' => $index + 1,
                'name' => $zone->name(),
                'coverage' => $coverage->sentence($zone, $countryNames),
                'methods' => $methodRows,
            ];
        }

        return [
            'zones' => $zoneRows,
            'zoneCount' => count($zones),
            'activeMethodCount' => $activeMethods,
            'noZone' => $zones === [],
            // shipping stage 5e: a fact, never a block — ONE count query (see ShippingClassMissingReader)
            'classMigrationHelpUrl' => HelpLink::url('class_migration', 'shipping'),
            'classlessCount' => app(\App\Services\ShippingClassMissingReader::class)->total(),
            'countryOptions' => $countryNames,
            'classOptions' => $classNames,
            'overviewHelpUrl' => HelpLink::url('shipping_overview', 'shipping'),
            'tryItHelpUrl' => HelpLink::url('try_it', 'shipping'),
            'zonesUrl' => \App\Filament\Resources\ShippingZoneResource::getUrl('index'),
            'methodsUrl' => \App\Filament\Resources\ShippingMethodResource::getUrl('index'),
            'classesUrl' => \App\Filament\Resources\ShippingClassResource::getUrl('index'),
        ];
    }

    /** @return array<string, mixed> */
    private function toViewResult(ShippingTestResult $result): array
    {
        $formatter = app(PriceDisplayFormatter::class);
        $currency = Currency::from($result->currency);

        $money = static fn (int $minor): string => $formatter->format(
            Money::fromMinorUnits($minor, $currency)->decimalValue(),
            $currency,
        );

        $methods = array_map(static fn (ShippingTestMethod $method): array => [
            'name' => $method->name,
            'summary' => $method->summary,
            'needsQuote' => $method->needsCarrierQuote,
            'price' => $method->amountMinor === null ? null : $money($method->amountMinor),
            'freeAbove' => $method->freeAboveMinor === null ? null : $money($method->freeAboveMinor),
            'remaining' => $method->remainingToFreeMinor === null ? null : $money($method->remainingToFreeMinor),
            'classMode' => $method->classMode === null ? null : __('shipping.class_mode.'.$method->classMode),
            'needsMore' => $method->remainingToFreeMinor !== null && $method->remainingToFreeMinor > 0,
            'courier' => $method->courier,
            // The destination scope in words (stage 6d, design 9.2.8) and whether this method serves the tested kind.
            'scope' => __('shipping.summary.scope.'.$method->destinationScope),
            'serves' => $method->servesDestination,
        ], $result->methods);

        // The methods that do NOT serve the tested destination kind, each named with its scope so the merchant sees
        // what to switch to. The rule is the tester's own servesDestination flag, never re-derived here.
        $unserved = array_values(array_map(
            static fn (array $method): string => $method['name'].' ('.$method['scope'].')',
            array_filter($methods, static fn (array $method): bool => ! $method['serves']),
        ));

        // The result grouped by courier (stage 5f), the same grouping the quote API returns; methods without a courier come last.
        $groups = array_map(static fn (array $group): array => ['courier' => $group['courier'], 'methods' => $group['items']], ShippingCourier::group($methods, static fn (array $method): ?string => $method['courier']));

        return [
            'matched' => $result->isMatched(),
            'zoneName' => $result->zoneName,
            'country' => $result->countryCode,
            'settlement' => $result->settlement,
            'postcode' => $result->postcode,
            'pickup' => $result->isPickupPoint,
            'goods' => $money($result->goodsAfterDiscountMinor),
            'methods' => $methods,
            'groups' => $groups,
            'unserved' => $unserved,
            'showGroupNames' => $groups !== [] && array_filter($groups, static fn (array $group): bool => $group['courier'] !== null) !== [],
            'hint' => $result->hint?->text,
        ];
    }
}
