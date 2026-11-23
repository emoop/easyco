<?php

namespace App\Privacy;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\IdentityKeys;
use EasyCo\Pricing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * THE PERSONAL-DATA EXPORT of one email (acc-4-gdpr-design.md D3, §4, ACC-4c): `build($email)` returns the two files of the ZIP, `html` (one readable Bulgarian page) and
 * `json` (the same data, machine-readable). Built in the background by BuildPrivacyExportJob; only the merchant starts it, only staff download it (D2 revised: no mail, no customer link).
 *
 * WHAT IT HOLDS (sections, each `{title, data}`): the account; its saved addresses; the ORDERS (lines, amounts, delivery, the second recipient's email, payment method, invoice details,
 * the statuses the customer could see, IP and user agent only while the attribution still keeps them); the CONSENTS (the marketing tick with its exact text, the terms acceptance, dated by
 * the order); the LISTS (empty until ACC-5: the section exists so a module can fill it through the filter).
 *
 * WHICH ORDERS: those whose `email_key` is this email, OR whose `account_id` is this person's (live) account. Both, because a guest order made with this address and an order the account
 * placed under another contact address are both HER data. An anonymised order (ACC-4b) has no keys: it is exported only through its account link, as it stands (placeholders and all).
 *
 * NEVER INCLUDED, by construction (the queries do not select them): staff notes (`customer_notes`), the free-text reasons of order events, staff names, order-block reasons, cost and profit,
 * the history of staff operations (notes, edits, refunds' internals). Only the whitelisted status events (EVENTS) are exported, as type + from + to + date.
 *
 * EXTENSION: the filter `privacy.export.sections` (the sections array, the email key) lets a module add or adjust a section. A filter that throws is logged (never its data) and the sections
 * stay as they were; a section of a wrong shape is dropped. Filters are not contained by the hook registry (extensibility-design-and-hooks.md), hence the guard HERE.
 *
 * SECURITY: every value is HTML-escaped (orders hold customer-typed text); the page has inline CSS only, no script, no external resource.
 * MEMORY: the orders are read in chunks of CHUNK with a constant number of queries each; only the resulting rows are kept.
 */
final class PrivacyExportBuilder
{
    public const FILTER = 'privacy.export.sections';

    public const CHUNK = 100;

    /** The order events a customer could see as «статус»; every other type (notes, edits, refund internals, anonymisation) stays out. */
    public const EVENTS = ['placed', 'status_changed', 'payment_confirmed', 'returned', 'refunded'];

    private const LABELS = [
        'email' => 'Имейл', 'created_at' => 'Създаден на', 'email_verified_at' => 'Имейлът е потвърден на', 'number' => 'Номер', 'placed_at' => 'Поръчана на',
        'status' => 'Статус', 'currency' => 'Валута', 'amounts' => 'Суми', 'subtotal' => 'Междинна сума', 'discount' => 'Отстъпка', 'shipping' => 'Доставка', 'total' => 'Общо',
        'promotion_code' => 'Промо код', 'payment_method' => 'Начин на плащане', 'lines' => 'Артикули', 'name' => 'Име', 'sku' => 'Артикулен номер', 'attributes' => 'Варианти',
        'quantity' => 'Количество', 'unit_price' => 'Единична цена', 'line_total' => 'Сума', 'delivery' => 'Доставка до', 'method' => 'Метод', 'courier' => 'Куриер',
        'delivery_type' => 'Вид доставка', 'recipient_first_name' => 'Име на получателя', 'recipient_last_name' => 'Фамилия на получателя', 'recipient_email' => 'Имейл на втория получател',
        'phone' => 'Телефон', 'country' => 'Държава', 'city' => 'Град', 'region' => 'Област', 'settlement' => 'Населено място', 'postal_code' => 'Пощенски код',
        'address_line_1' => 'Адрес', 'address_line_2' => 'Адрес (продължение)', 'pickup_point_name' => 'Място за вземане', 'pickup_point_address' => 'Адрес на мястото за вземане',
        'tracking_number' => 'Товарителница', 'customer_note' => 'Бележка на клиента', 'order_email' => 'Имейл на поръчката', 'invoice' => 'Данни за фактура',
        'recipient_type' => 'Получател на фактурата', 'legal_name' => 'Фирма / име', 'tax_id' => 'ЕИК / данъчен номер', 'company_registration_number' => 'Регистрационен номер',
        'representative' => 'МОЛ', 'invoice_email' => 'Имейл за фактура', 'requested_at' => 'Поискана на', 'history' => 'Статуси', 'type' => 'Събитие', 'from' => 'От', 'to' => 'Към',
        'at' => 'Дата', 'attribution' => 'Откъде е дошла поръчката', 'ip' => 'IP адрес', 'user_agent' => 'Браузър', 'anonymised_at' => 'Анонимизирана на',
        'marketing_consent' => 'Съгласие за маркетинг', 'marketing_consent_text' => 'Текст на съгласието', 'order' => 'Поръчка', 'terms_accepted_at' => 'Условията са приети на',
        'terms_version' => 'Версия на условията', 'default' => 'Основен адрес',
    ];

    /** @param int $chunk the order chunk size (the constant by default; a test passes a small one to cross the boundary) */
    public function __construct(
        private readonly int $chunk = self::CHUNK,
    ) {
    }

    /**
     * @return array{html: string, json: string}
     *
     * @throws InvalidArgumentException a blank email
     */
    public function build(string $email): array
    {
        $key = IdentityKeys::email($email) ?? throw new InvalidArgumentException('An email is required.');
        $account = AccountModel::query()->where('email', $key)->first();

        $sections = [
            'account' => ['title' => 'Профил', 'data' => $account === null ? [] : $this->clean([
                'email' => (string) $account->email,
                'created_at' => $this->date($account->created_at),
                'email_verified_at' => $this->date($account->email_verified_at),
            ])],
            'addresses' => ['title' => 'Запазени адреси', 'data' => $account === null ? [] : $this->addresses((int) $account->getKey())],
        ];

        [$orders, $consents] = $this->orders($key, $account === null ? null : (int) $account->getKey());

        $sections['orders'] = ['title' => 'Поръчки', 'data' => $orders];
        $sections['consents'] = ['title' => 'Съгласия', 'data' => $consents];
        $sections['lists'] = ['title' => 'Списъци и аудитории', 'data' => []];

        $sections = $this->filtered($sections, $key);

        return ['html' => $this->html($key, $sections), 'json' => $this->json($key, $sections)];
    }

    // ---- sections ---------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function addresses(int $accountId): array
    {
        return DB::table('addresses')->where('account_id', $accountId)->orderBy('id')->get()->map(fn (object $row): array => $this->clean([
            'default' => (bool) $row->default_marker ? true : null,
            'delivery_type' => $row->delivery_type, 'recipient_first_name' => $row->recipient_first_name, 'recipient_last_name' => $row->recipient_last_name, 'phone' => $row->phone,
            'country' => $row->country, 'city' => $row->city, 'region' => $row->region, 'settlement' => $row->settlement, 'postal_code' => $row->postal_code,
            'address_line_1' => $row->address_line_1, 'address_line_2' => $row->address_line_2, 'pickup_point_name' => $row->pickup_point_name, 'pickup_point_address' => $row->pickup_point_address,
        ]))->all();
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} the orders and the consents */
    private function orders(string $emailKey, ?int $accountId): array
    {
        $orders = [];
        $consents = [];

        DB::table('orders')
            ->where(function ($query) use ($emailKey, $accountId): void {
                $query->where('email_key', $emailKey);
                $accountId !== null && $query->orWhere('account_id', $accountId);
            })
            ->chunkById(max(1, $this->chunk), function ($chunk) use (&$orders, &$consents): void {
                $ids = $chunk->pluck('id')->all();
                $lines = $this->linesOf($chunk->pluck('transaction_id')->all());
                $invoices = DB::table('order_invoice_details')->whereIn('order_id', $ids)->get()->keyBy('order_id');
                $attributions = DB::table('order_attributions')->whereIn('order_id', $ids)->get(['order_id', 'ip', 'user_agent'])->keyBy('order_id');
                $methods = DB::table('payments')->whereIn('order_id', $ids)->orderBy('id')->pluck('method', 'order_id');
                $events = DB::table('order_events')->whereIn('order_id', $ids)->whereIn('type', self::EVENTS)->orderBy('occurred_at')->orderBy('id')
                    ->get(['order_id', 'type', 'from_status', 'to_status', 'occurred_at'])->groupBy('order_id');

                foreach ($chunk as $order) {
                    $orders[] = $this->order($order, $lines[$order->transaction_id] ?? [], $invoices[$order->id] ?? null, $attributions[$order->id] ?? null, $methods[$order->id] ?? null, $events[$order->id] ?? collect());
                    $consents[] = OrderConsents::row($order);
                }
            }, 'id');

        return [$orders, $consents];
    }

    /** @return array<string, mixed> */
    private function order(object $order, array $lines, ?object $invoice, ?object $attribution, ?string $method, $events): array
    {
        $money = static fn (?int $minor): ?string => $minor === null ? null : Money::fromMinorUnits($minor, (string) $order->currency)->decimalValue();

        return $this->clean([
            'number' => (string) $order->id,
            'placed_at' => $this->date($order->placed_at),
            'status' => $order->status,
            'currency' => $order->currency,
            'amounts' => $this->clean([
                'subtotal' => $money((int) $order->subtotal_minor), 'discount' => $money((int) $order->discount_minor), 'shipping' => $money((int) $order->shipping_minor), 'total' => $money((int) $order->total_minor),
            ]),
            'promotion_code' => $order->applied_promotion_code,
            'payment_method' => $method,
            'order_email' => $order->email,
            'recipient_email' => $order->recipient_email,
            'lines' => array_map(fn (object $line): array => $this->clean([
                'name' => $line->product_name, 'sku' => $line->sku, 'attributes' => $this->attributes($line->sold_attributes), 'quantity' => (int) $line->quantity,
                'unit_price' => $money($line->final_unit_price_minor === null ? null : (int) $line->final_unit_price_minor),
                'line_total' => $money($line->final_unit_price_minor === null ? (int) $line->amount_minor : (int) $line->final_unit_price_minor * (int) $line->quantity),
            ]), $lines),
            'delivery' => $this->clean([
                'method' => $order->shipping_method_name, 'courier' => $order->shipping_courier, 'delivery_type' => $order->delivery_type,
                'recipient_first_name' => $order->recipient_first_name, 'recipient_last_name' => $order->recipient_last_name, 'phone' => $order->phone,
                'country' => $order->country, 'city' => $order->city, 'region' => $order->region, 'settlement' => $order->settlement, 'postal_code' => $order->postal_code,
                'address_line_1' => $order->address_line_1, 'address_line_2' => $order->address_line_2, 'pickup_point_name' => $order->pickup_point_name,
                'pickup_point_address' => $order->pickup_point_address, 'tracking_number' => $order->tracking_number,
            ]),
            'customer_note' => $order->customer_note,
            'invoice' => $invoice === null ? null : $this->clean([
                'recipient_type' => $invoice->recipient_type, 'legal_name' => $invoice->legal_name, 'tax_id' => $invoice->tax_id, 'company_registration_number' => $invoice->company_registration_number,
                'representative' => $invoice->representative, 'country' => $invoice->country, 'city' => $invoice->city, 'postal_code' => $invoice->postal_code,
                'address_line_1' => $invoice->address_line_1, 'address_line_2' => $invoice->address_line_2, 'invoice_email' => $invoice->invoice_email,
                'requested_at' => $this->date($invoice->requested_at),
            ]),
            'history' => $events->map(fn (object $event): array => $this->clean([
                'type' => $event->type, 'from' => $event->from_status, 'to' => $event->to_status, 'at' => $this->date($event->occurred_at),
            ]))->values()->all(),
            // only while the attribution still keeps them: after 30 days (and at anonymisation) they are NULL and simply absent here
            'attribution' => $attribution === null ? null : $this->clean(['ip' => $attribution->ip, 'user_agent' => $attribution->user_agent]),
            'anonymised_at' => $this->date($order->anonymised_at),
        ]);
    }

    /**
     * The sale lines (not cancelled, not deleted) of the given transactions, grouped by transaction: one query per chunk.
     *
     * @param list<mixed> $transactionIds
     *
     * @return array<int|string, list<object>>
     */
    private function linesOf(array $transactionIds): array
    {
        $transactionIds = array_values(array_filter($transactionIds, static fn ($id): bool => $id !== null));

        if ($transactionIds === []) {
            return [];
        }

        return DB::table('operational_sales_sale_lines')
            ->whereIn('transaction_id', $transactionIds)->where('type', 'sale')->where('status', '!=', 'cancelled')->whereNull('deleted_at')->orderBy('id')
            ->get(['transaction_id', 'product_name', 'sku', 'sold_attributes', 'quantity', 'final_unit_price_minor', 'amount_minor'])
            ->groupBy('transaction_id')->map(fn ($group): array => $group->all())->all();
    }

    /** @return array<string, string>|null the sold attributes as «name => value» when they are a flat JSON object */
    private function attributes(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $flat = [];

        foreach ($decoded as $name => $value) {
            $flat[(string) $name] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $flat;
    }

    // ---- the filter -------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, array{title: string, data: array<mixed>}> $sections
     *
     * @return array<string, array{title: string, data: array<mixed>}>
     */
    private function filtered(array $sections, string $emailKey): array
    {
        try {
            $result = Hook::apply(self::FILTER, $sections, $emailKey);
        } catch (Throwable $e) {
            // never the sections or the email: they are personal data
            Log::error('privacy.export_filter_failed', ['filter' => self::FILTER, 'exception' => $e::class, 'message' => $e->getMessage(), 'where' => basename($e->getFile()).':'.$e->getLine()]);

            return $sections;
        }

        if (! is_array($result)) {
            return $sections;
        }

        $valid = [];

        foreach ($result as $name => $section) {
            if (is_string($name) && $name !== '' && is_array($section) && isset($section['title']) && is_string($section['title']) && isset($section['data']) && is_array($section['data'])) {
                $valid[$name] = ['title' => $section['title'], 'data' => $section['data']];
            }
        }

        return $valid;
    }

    // ---- the two files ----------------------------------------------------------------------------------------------------------

    /** @param array<string, array{title: string, data: array<mixed>}> $sections */
    private function json(string $emailKey, array $sections): string
    {
        return json_encode(
            ['generated_at' => now()->toIso8601String(), 'subject_email' => $emailKey, 'sections' => $sections],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string, array{title: string, data: array<mixed>}> $sections */
    private function html(string $emailKey, array $sections): string
    {
        $body = '';

        foreach ($sections as $section) {
            $body .= '<section><h2>'.$this->e($section['title']).'</h2>'.($section['data'] === [] ? '<p class="empty">Няма данни.</p>' : $this->node($section['data'])).'</section>';
        }

        return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Лични данни</title><style>'
            .'body{font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif;color:#1f2937;background:#f9fafb;margin:0;padding:24px}main{max-width:900px;margin:0 auto}'
            .'h1{font-size:22px;margin:0 0 4px}h2{font-size:18px;border-bottom:2px solid #e5e7eb;padding-bottom:4px;margin:28px 0 12px}'
            .'.meta{color:#6b7280;margin:0 0 8px}section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:8px 16px 16px}'
            .'dl{margin:0;display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:4px 16px}dt{color:#6b7280}dd{margin:0;word-break:break-word;white-space:pre-wrap}'
            .'.item{border-top:1px solid #e5e7eb;padding:12px 0}.item:first-child{border-top:0}.empty{color:#6b7280}'
            .'</style></head><body><main><h1>Лични данни</h1><p class="meta">Имейл: '.$this->e($emailKey).' · Създадено на: '.$this->e(now()->format('Y-m-d H:i')).' UTC</p>'
            .$body.'</main></body></html>';
    }

    private function node(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'да' : 'не';
        }

        if (! is_array($value)) {
            return $this->e((string) $value);
        }

        if (array_is_list($value)) {
            $html = '';

            foreach ($value as $item) {
                $html .= '<div class="item">'.$this->node($item).'</div>';
            }

            return $html;
        }

        $html = '<dl>';

        foreach ($value as $key => $item) {
            if ($item === null || $item === '' || $item === []) {
                continue;
            }

            $html .= '<dt>'.$this->e(self::LABELS[$key] ?? (string) $key).'</dt><dd>'.$this->node($item).'</dd>';
        }

        return $html.'</dl>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---- small helpers ----------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function clean(array $row): array
    {
        return array_filter($row, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toIso8601String();
    }
}
