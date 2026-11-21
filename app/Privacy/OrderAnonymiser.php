<?php

namespace App\Privacy;

use App\Enums\OrderEventType;
use App\Services\OrderEventRecorder;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Order;
use Illuminate\Support\Facades\DB;

/**
 * THE ANONYMISATION OF ONE ORDER (acc-4-gdpr-design.md D1, D8, ACC-4b). One transaction per order:
 *
 *  1. the order itself: Order::anonymise() (the domain rule: which fields, finished statuses only, one-way, only past the cutoff) saved through the
 *     order repository, which also leaves the four identity keys NULL;
 *  2. the copies of the personal fields: the placement snapshot, the invoice details, the attribution (ip, user agent, visitor, account, the landing and
 *     referrer addresses, the ad click id), the free-text reasons of the order's events, payments and refunds, the bank reference of its receipts;
 *  3. the order's rows in `mail_log` are DELETED (they hold the address);
 *  4. ONE `anonymised` event in the order history.
 *
 * AFTER the commit: the `order.anonymised` action, so an extension can purge its own copies. A listener that throws never undoes it.
 *
 * What never changes: amounts, lines, statuses, dates, the number, shipping, the payment method, the consent boolean, `country`, `city`, UTM, the device
 * class and the counts. Staff names in the history stay (staff, not the customer). Skipping the orders that are not finished or have an open money matter is
 * the COMMAND's job (it selects them); a direct call on one of them is refused by the domain rule (LogicException) before anything is written.
 *
 * A NOT NULL column gets the placeholder, a nullable one NULL; a pick-up row keeps one non-blank description (the placeholder in the address): the database
 * requires a pick-up point to be identified or described.
 */
final class OrderAnonymiser
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderEventRecorder $events,
    ) {
    }

    /**
     * @return bool true when the order was anonymised now; false when it does not exist or already was (idempotent)
     *
     * @throws \LogicException not finished, or not past the cutoff (nothing written)
     */
    public function anonymise(string $orderId, DateTimeImmutable $cutoff, DateTimeImmutable $at): bool
    {
        $done = DB::transaction(function () use ($orderId, $cutoff, $at): bool {
            $order = $this->orders->findByIdForUpdate($orderId);

            if ($order === null || $order->isAnonymised()) {
                return false;
            }

            $order->anonymise($at, $cutoff);
            $this->orders->save($order);

            $this->copies($orderId);
            $this->events->record($orderId, OrderEventType::ANONYMISED, null, null, null, null, $at);

            return true;
        });

        if ($done) {
            Hook::fire('order.anonymised', $orderId);
        }

        return $done;
    }

    private function copies(string $orderId): void
    {
        $placeholder = Order::ANONYMISED;
        // a pick-up row keeps ONE non-blank description (ops_pickup_ident_check / ord_pickup_ident_check); any other row has none
        $pickupAddress = DB::raw("CASE WHEN delivery_type = 'pickup_point' THEN '{$placeholder}' ELSE NULL END");

        DB::table('order_placement_snapshots')->where('order_id', $orderId)->update([
            'recipient_first_name' => $placeholder, 'recipient_last_name' => $placeholder, 'phone' => $placeholder,
            'postal_code' => null, 'address_line_1' => null, 'address_line_2' => null, 'region' => null, 'settlement' => null,
            'pickup_point_reference' => null, 'pickup_point_name' => null, 'pickup_point_address' => $pickupAddress, 'customer_note' => null,
        ]);

        DB::table('order_invoice_details')->where('order_id', $orderId)->update([
            'legal_name' => $placeholder, 'tax_id' => null, 'company_registration_number' => null, 'representative' => null,
            'city' => $placeholder, 'postal_code' => $placeholder, 'address_line_1' => $placeholder, 'address_line_2' => null,
            'invoice_email' => Order::ANONYMISED_EMAIL, 'updated_at' => now(),
        ]);

        DB::table('order_attributions')->where('order_id', $orderId)->update([
            'ip' => null, 'user_agent' => null, 'visitor_id' => null, 'account_id' => null,
            'referrer_url' => null, 'landing_page_url' => null, 'first_landing_page_url' => null, 'click_id_value' => null,
            'updated_at' => now(),
        ]);

        DB::table('order_events')->where('order_id', $orderId)->update(['reason' => null]);

        DB::table('payments')->where('order_id', $orderId)->update(['failure_reason' => null]);
        // the staff free text of a refund: NULL, except where a CHECK needs a text (a cancelled refund keeps a reason: pay_refunds_cancel_check; a deduction
        // keeps one: pay_refunds_deduction_check) - the placeholder there
        DB::table('payment_refunds')->where('order_id', $orderId)->update([
            'reason' => null, 'failure_reason' => null, 'paid_out_note' => null, 'paid_out_reference' => null,
            'cancelled_reason' => DB::raw("CASE WHEN cancelled_at IS NOT NULL THEN '{$placeholder}' ELSE NULL END"),
            'deduction_reason' => DB::raw("CASE WHEN deduction_minor <> 0 THEN '{$placeholder}' ELSE NULL END"),
        ]);
        DB::table('payment_receipts')->whereIn('payment_id', DB::table('payments')->where('order_id', $orderId)->select('id'))->update(['bank_reference' => $placeholder]);

        DB::table('mail_log')->where('related_type', 'order')->where('related_id', $orderId)->delete();
    }
}
