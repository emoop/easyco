<?php

namespace App\Services;

use App\Enums\AnalyticsConsent;
use App\Enums\AttributionCaptureStatus;
use App\Enums\AttributionSourceType;
use App\Enums\ConsentSource;
use App\Enums\OrderEventType;
use App\Models\OrderAttributionModel;
use App\Models\OrderInvoiceDetailsModel;
use App\Settings\OrderContextSettings;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * THE ONE WRITER of an order's context rows (order-context-design.md 9.6, stage C2-A), called by CheckoutOrchestrator INSIDE the placement
 * transaction, right after the Order row is inserted and BEFORE the cart claim. Three inserts, nothing else:
 *
 *  1. ONE `order_attributions` row, always. The storefront is cookie-free and sessionless, so no visit can be captured yet (design
 *     amendment, section 4): `source_type` unknown, `capture_status` skipped_no_data, every touch and visit column NULL, `visitor_id` NULL,
 *     `consent_mode` from `privacy.consent_mode`, `analytics_consent` unknown, `consent_source` not_asked — plus the request facts that need
 *     no storage: the client address, the user agent (truncated to `privacy.user_agent_max_length`), the device class derived from it, the
 *     account the customer was logged in as, and `captured_at` = the placement instant. A sandbox or API order follows the same rule.
 *  2. ONE `order_invoice_details` row, only when the customer asked for an invoice.
 *  3. ONE `placed` event: the timeline's first row, `occurred_at` = the placement instant (section 11.3).
 *
 * WHY HERE AND NOT AFTER THE COMMIT: a double submit loses the cart claim, the transaction rolls back and the replay path returns the
 * order that already exists. If these rows were written after that, a second request would hit `attr_order_id_unique` / `oid_order_id_unique`
 * and turn an idempotent replay into a 500. Written inside, the rollback discards them and the replay never inserts one.
 *
 * THE ADDRESS NEVER LEAVES THE ROW: no log line, no activity_log entry, no exception message carries it. With `privacy.ip_full_retention_days`
 * = 0 the full address is never kept at all: it is stored already anonymised, and `ip_anonymised_at` says so.
 */
final class OrderContextWriter
{
    public function __construct(
        private readonly OrderContextSettings $settings,
        private readonly DeviceTypeDetector $devices,
        private readonly OrderEventRecorder $events,
    ) {
    }

    public function write(string $orderId, CheckoutInput $input, DateTimeImmutable $placedAt): void
    {
        $this->writeAttribution($orderId, $input, $placedAt);

        if ($input->invoice !== null) {
            $this->writeInvoice($orderId, $input->invoice, $input->email, $placedAt);
        }

        $this->events->record($orderId, OrderEventType::PLACED, null, null, null, null, $placedAt);
    }

    private function writeAttribution(string $orderId, CheckoutInput $input, DateTimeImmutable $placedAt): void
    {
        $facts = $input->requestFacts;
        $agent = $this->userAgent($facts?->userAgent);
        [$ip, $anonymisedAt] = $this->address($facts?->ip, $placedAt);

        OrderAttributionModel::query()->create([
            'order_id' => $orderId,
            'source_type' => AttributionSourceType::UNKNOWN->value,
            'capture_status' => AttributionCaptureStatus::SKIPPED_NO_DATA->value,
            'device_type' => $this->devices->detect($agent)->value,
            'user_agent' => $agent,
            'visitor_id' => null,
            'account_id' => $input->accountId === null ? null : (int) $input->accountId,
            'consent_mode' => $this->settings->consentMode()->value,
            'analytics_consent' => AnalyticsConsent::UNKNOWN->value,
            'consent_source' => ConsentSource::NOT_ASKED->value,
            'ip' => $ip,
            'ip_anonymised_at' => $anonymisedAt,
            'captured_at' => $placedAt,
        ]);
    }

    private function writeInvoice(string $orderId, CheckoutInvoiceRequest $invoice, string $orderEmail, DateTimeImmutable $placedAt): void
    {
        OrderInvoiceDetailsModel::query()->create([
            'order_id' => $orderId,
            'requested_at' => $placedAt,
            'recipient_type' => $invoice->recipientType->value,
            'legal_name' => $invoice->legalName,
            'tax_id' => $invoice->taxId,
            'company_registration_number' => $invoice->companyRegistrationNumber,
            'representative' => $invoice->representative,
            'country' => $invoice->country,
            'city' => $invoice->city,
            'postal_code' => $invoice->postalCode,
            'address_line_1' => $invoice->addressLine1,
            'address_line_2' => $invoice->addressLine2,
            // A stored COPY: it defaults to the order's own email, but it may be an accountant's.
            'invoice_email' => $invoice->email ?? $orderEmail,
            'country_rule_version' => $invoice->countryRuleVersion,
        ]);
    }

    /** The user agent as a bounded, valid-UTF-8 string (privacy.user_agent_max_length), or NULL when the request carried none. */
    private function userAgent(?string $agent): ?string
    {
        if ($agent === null) {
            return null;
        }

        $agent = trim(mb_scrub($agent));

        return $agent === '' ? null : mb_substr($agent, 0, $this->settings->userAgentMaxLength());
    }

    /**
     * The address to store and when it was anonymised: [full, null], or — when the merchant keeps no full address at all (retention 0) —
     * [anonymised, now]. Anything that is not an IP address is not stored (never a free-text value in a column read as an address).
     *
     * @return array{0: ?string, 1: ?DateTimeImmutable}
     */
    private function address(?string $ip, DateTimeImmutable $placedAt): array
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [null, null];
        }

        return $this->settings->ipFullRetentionDays() === 0
            ? [IpUtils::anonymize($ip), $placedAt]
            : [$ip, null];
    }
}
