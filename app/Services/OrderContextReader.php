<?php

namespace App\Services;

use App\Enums\AttributionCaptureStatus;
use App\Enums\AttributionSourceType;
use App\Enums\InvoiceRecipientType;
use App\Models\OrderAttributionModel;
use App\Models\OrderInvoiceDetailsModel;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use App\Settings\InvoiceSettings;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Facades\Filament;

/**
 * THE ONE SOURCE of an order's context for the admin pages (order-context-design.md). What the CUSTOMER TICKED at checkout is on the
 * order's own row (checkout form v2, C1d-B), so it is read from the record the page already loaded: NO query. The ORIGIN and the INVOICE
 * REQUEST (stage C2-A) are two small reads by `order_id` (each on its unique index), made ONCE per order per request and remembered.
 *
 * THE FULL CLIENT ADDRESS IS NOT IN forOrder() AND NEVER IS (section 7.1): `ORDER_VIEW` alone is not enough to see it. forOrder() selects an
 * explicit column list that does not contain `ip`, so a staff member without `ORDER_MANAGE` causes no query that touches the address and
 * has no value of it in memory at all. fullClientAddress() is the ONE gated reader: it checks the signed-in staff member's permission ITSELF
 * (a future caller cannot forget the gate) and answers null, WITHOUT a query, to anyone else. What every viewer may see of the address once
 * it has been anonymised is a date and the anonymised network: not personal data.
 */
class OrderContextReader
{
    /** @var array<string, OrderContextView> */
    private array $views = [];

    /** @var array<string, ?string> only ever filled by fullClientAddress(), i.e. only for a permitted reader */
    private array $addresses = [];

    /** The columns of `order_attributions` forOrder() reads: EVERY column except `ip`. */
    private const ATTRIBUTION_COLUMNS = [
        'order_id', 'source_type', 'capture_status', 'last_utm_source', 'last_utm_medium', 'last_utm_campaign', 'last_utm_content',
        'referrer_host', 'landing_page_url', 'session_pages', 'session_count', 'device_type', 'visitor_id', 'account_id',
        'consent_mode', 'analytics_consent', 'consent_source', 'ip_anonymised_at', 'captured_at',
    ];

    public function forOrder(string $orderId): OrderContextView
    {
        return $this->views[$orderId] ??= $this->read($orderId);
    }

    /**
     * The full client address of an order, or null: for a staff member WITHOUT `ORDER_MANAGE` (no query is made), for an order with no
     * attribution row, for an address that was erased, and for one that was anonymised (the anonymised form is in forOrder()).
     */
    public function fullClientAddress(string $orderId): ?string
    {
        if (! $this->mayReadFullClientAddress()) {
            return null;
        }

        if (! array_key_exists($orderId, $this->addresses)) {
            $this->addresses[$orderId] = OrderAttributionModel::query()
                ->where('order_id', $orderId)
                ->whereNull('ip_anonymised_at')
                ->value('ip');
        }

        return $this->addresses[$orderId];
    }

    /** Does the signed-in staff member hold ORDER_MANAGE (the one permission that may read a full address)? */
    public function mayReadFullClientAddress(): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof StaffModel) {
            return false;
        }

        $staff = app(AuthenticatedStaffResolver::class)->resolveById((string) $user->getAuthIdentifier());

        return $staff !== null && $staff->can(Permission::ORDER_MANAGE);
    }

    /**
     * The context for an order the page already loaded: forOrder() (the origin and the invoice) plus what the customer ticked, read from the
     * row itself (no query). A value the row holds wins; a NULL on the row leaves what forOrder() said.
     */
    public function forRecord(OrderModel $record): OrderContextView
    {
        $base = $this->forOrder((string) $record->id);
        $accepted = $record->terms_accepted_at;

        return new OrderContextView(
            visitorId: $base->visitorId,
            termsAccepted: $accepted === null ? $base->termsAccepted : true,
            confirmationRequested: $record->confirmation_requested === null ? $base->confirmationRequested : (bool) $record->confirmation_requested,
            callBeforeShipping: $record->call_before_shipping === null ? $base->callBeforeShipping : (bool) $record->call_before_shipping,
            invoiceCompanyName: $base->invoiceCompanyName,
            invoiceVatNumber: $base->invoiceVatNumber,
            invoiceBillingAddress: $base->invoiceBillingAddress,
            originSourceType: $base->originSourceType,
            originCampaign: $base->originCampaign,
            originLandingPage: $base->originLandingPage,
            originReferrer: $base->originReferrer,
            termsAcceptedAt: $accepted ?? $base->termsAcceptedAt,
            termsVersion: $record->terms_version ?? $base->termsVersion,
            marketingConsent: $record->marketing_consent === null ? $base->marketingConsent : (bool) $record->marketing_consent,
            marketingConsentText: $record->marketing_consent_text ?? $base->marketingConsentText,
            originDeviceType: $base->originDeviceType,
            originConsent: $base->originConsent,
            originReason: $base->originReason,
            accountId: $base->accountId,
            attributionRecorded: $base->attributionRecorded,
            ipAnonymisedAt: $base->ipAnonymisedAt,
            ipNetwork: $base->ipNetwork,
            invoiceRequested: $base->invoiceRequested,
            invoiceRecipientType: $base->invoiceRecipientType,
            invoiceTaxIdLabel: $base->invoiceTaxIdLabel,
            invoiceRegistrationNumber: $base->invoiceRegistrationNumber,
            invoiceRegistrationLabel: $base->invoiceRegistrationLabel,
            invoiceRepresentative: $base->invoiceRepresentative,
            invoiceRepresentativeLabel: $base->invoiceRepresentativeLabel,
            invoiceEmail: $base->invoiceEmail,
            invoiceRuleVersion: $base->invoiceRuleVersion,
            invoiceRequestedAt: $base->invoiceRequestedAt,
        );
    }

    private function read(string $orderId): OrderContextView
    {
        $attribution = OrderAttributionModel::query()->where('order_id', $orderId)->first(self::ATTRIBUTION_COLUMNS);
        $invoice = OrderInvoiceDetailsModel::query()->where('order_id', $orderId)->first();

        if ($attribution === null && $invoice === null) {
            return OrderContextView::notRecorded();
        }

        $origin = $attribution === null ? [] : $this->originFields($attribution);
        $billing = $invoice === null ? [] : $this->invoiceFields($invoice);

        return new OrderContextView(...$origin, ...$billing);
    }

    /** @return array<string, mixed> */
    private function originFields(OrderAttributionModel $row): array
    {
        $source = AttributionSourceType::tryFrom((string) $row->source_type);
        $status = AttributionCaptureStatus::tryFrom((string) $row->capture_status);
        $campaign = array_filter([$row->last_utm_source, $row->last_utm_medium, $row->last_utm_campaign, $row->last_utm_content], static fn ($part): bool => filled($part));
        $anonymisedAt = $row->ip_anonymised_at;

        return [
            'visitorId' => $row->visitor_id,
            'originSourceType' => $this->label('attribution_source_options', $row->source_type),
            'originCampaign' => $campaign === [] ? null : implode(' / ', $campaign),
            'originLandingPage' => $row->landing_page_url,
            'originReferrer' => $row->referrer_host,
            'originDeviceType' => $this->label('device_type_options', $row->device_type),
            'originConsent' => $this->consentSentence($row),
            'originReason' => $this->reason($source, $status, $row),
            'accountId' => $row->account_id === null ? null : (string) $row->account_id,
            'attributionRecorded' => true,
            'ipAnonymisedAt' => $anonymisedAt,
            // The anonymised network is read ONLY when the address WAS anonymised (a second, narrow query): it is then not personal data.
            'ipNetwork' => $anonymisedAt === null ? null : OrderAttributionModel::query()->where('order_id', $row->order_id)->whereNotNull('ip_anonymised_at')->value('ip'),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceFields(OrderInvoiceDetailsModel $row): array
    {
        $rule = app(InvoiceSettings::class)->ruleFor((string) $row->country);
        $lines = array_filter([$row->address_line_1, $row->address_line_2, trim($row->postal_code.' '.$row->city), $row->country], static fn ($part): bool => filled($part));

        return [
            'invoiceRequested' => true,
            'invoiceCompanyName' => $row->legal_name,
            'invoiceVatNumber' => $row->tax_id,
            'invoiceBillingAddress' => implode(', ', $lines),
            'invoiceRecipientType' => $this->label('invoice_recipient_options', $row->recipient_type),
            // The labels the merchant CONFIGURED for the row's country (the generic ones when none): "ЕИК / Булстат", not `tax_id`.
            'invoiceTaxIdLabel' => $row->recipient_type === InvoiceRecipientType::COMPANY->value ? $rule->taxIdLabelOrDefault() : null,
            'invoiceRegistrationNumber' => $row->company_registration_number,
            'invoiceRegistrationLabel' => $rule->registrationNumberLabelOrDefault(),
            'invoiceRepresentative' => $row->representative,
            'invoiceRepresentativeLabel' => $rule->representativeLabelOrDefault(),
            'invoiceEmail' => $row->invoice_email,
            'invoiceRuleVersion' => $row->country_rule_version,
            'invoiceRequestedAt' => $row->requested_at,
        ];
    }

    /** A stored enum value as its label in the current language (the raw value when it has none: a label is never a reason to fail a page). */
    private function label(string $group, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = 'orders.'.$group.'.'.$value;
        $label = __($key);

        return $label === $key ? (string) $value : (string) $label;
    }

    /** "Strict — analytics not asked — nothing stored on the visitor's device": the regime, the visitor's own state, what that means for storage. */
    private function consentSentence(OrderAttributionModel $row): string
    {
        $mode = (string) $row->consent_mode;

        return implode(' — ', array_filter([
            $this->label('consent_mode_options', $mode),
            $this->label('analytics_consent_options', $row->analytics_consent),
            __('orders.context.consent_storage.'.$mode) === 'orders.context.consent_storage.'.$mode ? null : __('orders.context.consent_storage.'.$mode),
        ]));
    }

    /** The reason line (section 11.1): what turns "the field is empty" into an answer a merchant can act on. NULL when the visit WAS captured. */
    private function reason(?AttributionSourceType $source, ?AttributionCaptureStatus $status, OrderAttributionModel $row): ?string
    {
        return match (true) {
            $status === AttributionCaptureStatus::CAPTURED => null,
            $status === AttributionCaptureStatus::SKIPPED_NO_CONSENT => __('orders.context.reason_no_consent', [
                'mode' => mb_strtolower((string) $this->label('consent_mode_options', $row->consent_mode)),
                'analytics' => mb_strtolower((string) $this->label('analytics_consent_options', $row->analytics_consent)),
            ]),
            // The storefront is cookie-free until the consent-aware capture exists: the honest statement for the orders of this stage.
            $status === AttributionCaptureStatus::SKIPPED_NO_DATA && $source === AttributionSourceType::UNKNOWN => __('orders.context.reason_not_recorded_yet'),
            $status === AttributionCaptureStatus::SKIPPED_NO_DATA => __('orders.context.reason_no_data'),
            default => null,
        };
    }
}
