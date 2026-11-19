<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\AccountOrderReader;
use App\Settings\StoreLocale;
use App\Settings\StoreTimezone;
use App\Storefront\Account\SafeRedirect;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\NotFoundResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * ACC-3b-1: «Моят профил», the READ-ONLY part: the dashboard, the orders list, one order, and the two placeholders (addresses, details: ACC-3b-2 fills them).
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

    public function addresses(Request $request, CatalogReader $reader): Response|RedirectResponse
    {
        return $this->placeholder($request, $reader, 'addresses', 'addresses_title');
    }

    public function details(Request $request, CatalogReader $reader): Response|RedirectResponse
    {
        return $this->placeholder($request, $reader, 'details', 'details_title');
    }

    private function placeholder(Request $request, CatalogReader $reader, string $current, string $titleKey): Response|RedirectResponse
    {
        if (Auth::guard('customer')->user() === null) {
            return $this->toLogin($request);
        }

        return $this->page('placeholder', $titleKey, $reader, ['current' => $current]);
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
