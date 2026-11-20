<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\AccountOrderReader;
use App\Services\AddressResolver;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\CountryNames;
use App\Settings\StoreCountry;
use App\Settings\StoreLocale;
use App\Settings\StoreTimezone;
use App\Storefront\Account\SafeRedirect;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\NotFoundResponse;
use Carbon\CarbonImmutable;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * ACC-3b-1/2: «Моят профил»: the dashboard, the orders list, one order (ACC-3b-1), the address book with its street-address form and the details page (ACC-3b-2).
 * Every page is a server-rendered READ; the writes of ACC-3b-2 go through the existing API from storefront-account.js and come back with ?notice=<key> (a closed list).
 *
 * Server-rendered from the existing readers (AccountOrderReader: the same one the account API uses; it already filters by the account and never carries staff
 * notes, history, flags, IP, user agent, costs or internal ids). Inside the `/account` route group: the session, the soft stale-session check first, never
 * cacheable, noindex. A guest (or a stale session) goes to the login with THIS page as the way back, through SafeRedirect.
 *
 * Another account's order, a guest order and an unknown number are the SAME storefront 404 (the reader answers null for all three).
 */
final class AccountAreaController extends Controller
{
    public function __construct(
        private readonly AccountOrderReader $orders,
        private readonly StoreTimezone $timezone,
        private readonly StoreLocale $locale,
        private readonly AddressRepository $addresses,
    ) {
    }

    public function dashboard(Request $request, CatalogReader $reader): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return $this->toLogin($request);
        }

        return $this->page('dashboard', 'title', $reader, ['email' => (string) $account->email, 'current' => 'dashboard']);
    }

    public function orders(Request $request, CatalogReader $reader): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return $this->toLogin($request);
        }

        $page = max(1, min(100000, (int) $request->query('page', 1)));
        $list = $this->orders->list($account, $page);

        // a page past the last one is the shop's 404 (like a category), never an empty list
        if ($page > 1 && ($list['data'] === [] || $page > $list['meta']['last_page'])) {
            return NotFoundResponse::make();
        }

        $rows = array_map(fn (array $order): array => [
            'number' => $order['number'],
            'date' => $this->date($order['placed_at'], false),
            'status' => $order['status_label'],
            'total' => $order['total'],
            'items' => $order['items_count'],
            'url' => route('storefront.account.order', ['number' => $order['number']], false),
        ], $list['data']);

        return $this->page('orders', 'orders_title', $reader, [
            'rows' => $rows,
            'meta' => $list['meta'],
            'newer' => $page > 1 ? $this->ordersUrl($page - 1) : null,
            'older' => $page < $list['meta']['last_page'] ? $this->ordersUrl($page + 1) : null,
            'current' => 'orders',
        ]);
    }

    public function order(Request $request, string $number, CatalogReader $reader): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return $this->toLogin($request);
        }

        $order = $this->orders->detail($account, $number);

        if ($order === null) {
            return NotFoundResponse::make();
        }

        return $this->page('order', 'order_title', $reader, [
            'number' => $order['number'],
            'placed' => $this->date($order['placed_at'], true),
            'status' => $order['status_label'],
            'tracking' => $order['tracking'],
            'order' => $this->bodyOf($order),
            'current' => 'orders',
        ], ['number' => $order['number']]);
    }

    /** the closed list of notices a write may leave on the next page (?notice=key): a key that is not here shows nothing */
    private const NOTICES = [
        'address-saved' => 'notice_address_saved',
        'address-deleted' => 'notice_address_deleted',
        'address-default' => 'notice_address_default',
        'email-sent' => 'notice_email_sent',
        'password-changed' => 'notice_password_changed',
    ];

    public function addresses(Request $request, CatalogReader $reader): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return $this->toLogin($request);
        }

        $accountId = (string) $account->getKey();
        $defaultId = $this->addresses->defaultAddressId($accountId);
        $countries = CountryNames::forLocale($this->locale->current());
        $saved = $this->addresses->findByAccountId($accountId);

        return $this->page('addresses', 'addresses_title', $reader, [
            'cards' => array_map(fn (Address $address): array => $this->card($address, $defaultId, $countries), $saved),
            'full' => count($saved) >= AddressResolver::SAVED_LIMIT,
            'max' => AddressResolver::SAVED_LIMIT,
            'current' => 'addresses',
            'notice' => $this->notice($request),
        ]);
    }

    public function newAddress(Request $request, CatalogReader $reader, StoreCountry $storeCountry, CheckoutRegionPolicy $regions): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return $this->toLogin($request);
        }

        // a full book has no «new» page (the API refuses the 21st anyway)
        if (count($this->addresses->findByAccountId((string) $account->getKey())) >= AddressResolver::SAVED_LIMIT) {
            return redirect(route('storefront.account.addresses', [], false));
        }

        return $this->form($reader, $regions, null, $storeCountry->currentOrNull() ?? 'BG', 'address_new_title');
    }

    public function editAddress(Request $request, string $id, CatalogReader $reader, StoreCountry $storeCountry, CheckoutRegionPolicy $regions): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return $this->toLogin($request);
        }

        $address = preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1 ? $this->addresses->findById($id) : null;

        // another account's address, a pick-up address (no edit: offices need the courier integrations) and an unknown id are the SAME 404
        if ($address === null || $address->accountId() !== (string) $account->getKey() || $address->deliveryType() !== AddressDeliveryType::STREET_ADDRESS) {
            return NotFoundResponse::make();
        }

        return $this->form($reader, $regions, $address, (string) ($address->country() ?? 'BG'), 'address_edit_title');
    }

    public function details(Request $request, CatalogReader $reader): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return $this->toLogin($request);
        }

        return $this->page('details', 'details_title', $reader, ['current' => 'details', 'email' => (string) $account->email, 'notice' => $this->notice($request)]);
    }

    /** the street-address form: empty (new) or filled with HER address; the region rule per country goes to the page as data for the script */
    private function form(CatalogReader $reader, CheckoutRegionPolicy $regions, ?Address $address, string $country, string $titleKey): Response
    {
        return $this->page('address-form', $titleKey, $reader, [
            'address' => $address,
            'country' => $country,
            'countries' => CountryNames::forLocale($this->locale->current()),
            'regionPolicy' => $regions->all(),
            'current' => 'addresses',
            'formPage' => true,
        ]);
    }

    /**
     * One saved address as the card shows it. A street address: the lines like everywhere («city postcode», the region when it has one). A pick-up address: the
     * office, its address and the settlement, plus the courier and the kind, read-only.
     *
     * @param  array<string, string>  $countries
     * @return array<string, mixed>
     */
    private function card(Address $address, ?string $defaultId, array $countries): array
    {
        $street = $address->deliveryType() === AddressDeliveryType::STREET_ADDRESS;
        $country = $address->country();
        $countryName = $country === null ? null : ($countries[$country] ?? $country);

        $lines = $street
            ? [$address->addressLine1(), $address->addressLine2(), trim(($address->city() ?? '').' '.($address->postalCode() ?? '')), $address->region(), $countryName]
            : [$address->pickupPointName(), $address->pickupPointAddress(), $address->settlement(), $countryName];

        return [
            'id' => $address->id(),
            'street' => $street,
            'name' => trim($address->recipientFirstName().' '.$address->recipientLastName()),
            'phone' => $address->phone(),
            'lines' => array_values(array_filter($lines, static fn (?string $line): bool => $line !== null && trim($line) !== '')),
            'courier' => $street || $address->carrierCode() === null ? null : ucfirst($address->carrierCode()),
            'kind' => $street ? null : $address->pickupPointKind(),
            'default' => $address->id() === $defaultId,
            'editUrl' => $street ? route('storefront.account.address.edit', ['id' => $address->id()], false) : null,
        ];
    }

    /** the text of the notice the request asks for, from the closed list; anything else is no notice */
    private function notice(Request $request): ?string
    {
        $key = $request->query('notice');

        return is_string($key) && isset(self::NOTICES[$key]) ? (string) __('account_pages.'.self::NOTICES[$key]) : null;
    }

    /** the guest's way back: the path of THIS page, passed through the same rule as the login's own `redirect` */
    private function toLogin(Request $request): RedirectResponse
    {
        $path = SafeRedirect::path($request->getPathInfo());

        return redirect('/account/login?redirect='.str_replace('%2F', '/', rawurlencode($path)));
    }

    private function ordersUrl(int $page): string
    {
        $url = route('storefront.account.orders', [], false);

        return $page > 1 ? $url.'?page='.$page : $url;
    }

    /** an ISO date from the reader in the store's time zone, in the store's date format */
    private function date(string $iso, bool $withTime): string
    {
        $bg = $this->locale->current() === 'bg';
        $format = ($bg ? 'd.m.Y' : 'Y-m-d').($withTime ? ' H:i' : '');

        return CarbonImmutable::parse($iso)->setTimezone($this->timezone->zone())->format($format);
    }

    /**
     * AccountOrderReader::detail()'s answer in the shape OrderReceivedReader::find() has, so the order-received page's own body partial prints it
     * (no copy of that markup, no second read of the order).
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function bodyOf(array $order): array
    {
        return [
            'lines' => array_map(static fn (array $line): array => [
                'name' => $line['name'],
                'label' => (string) ($line['attributes'] ?? ''),
                'quantity' => $line['quantity'],
                'total' => $line['line_total'],
            ], $order['lines']),
            'shipping' => $order['totals']['shipping'],
            'subtotal' => $order['totals']['subtotal'],
            'discount' => $order['totals']['discount'],
            'total' => $order['totals']['total'],
            'destination' => ['kind' => $order['delivery']['kind'], 'pickup_kind' => $order['delivery']['pickup_kind'], 'lines' => $order['delivery']['lines']],
            'note' => $order['note'],
            'delivery_notice_email' => $order['delivery_notice_email'],
            'payment' => [
                'method' => $order['payment']['method'],
                'bank' => $order['payment']['bank'],
                'cash_on_delivery_due' => $order['payment']['cash_on_delivery_due'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $titleVars
     */
    private function page(string $view, string $titleKey, CatalogReader $reader, array $data, array $titleVars = []): Response
    {
        $title = (string) __('account_pages.'.$titleKey, $titleVars);

        return response()->view('storefront.account.area.'.$view, $data + [
            'title' => $title,
            'categories' => $reader->categoryTree(),
            'seo' => [
                'title' => $title.' — '.config('app.name'),
                'description' => '',
                'canonical' => null,
            ],
        ]);
    }
}
