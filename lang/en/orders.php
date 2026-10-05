<?php

return [

    'label' => 'Order',
    'plural_label' => 'Orders',
    'navigation_label' => 'Orders',

    'fields' => [
        'id' => '№',
        'placed_at' => 'Placed at',
        'recipient_name' => 'Recipient',
        'email' => 'Email',
        'client_name' => 'Client',
        'client_id' => 'Client ID',
        'channel' => 'Channel',
        'payment_method' => 'Payment method',
        'payment_status' => 'Payment status',
        'total' => 'Total',
        'subtotal' => 'Subtotal',
        'discount' => 'Discount',
        'shipping' => 'Shipping',
        'shipping_method' => 'Shipping method',
        'delivery_address' => 'Address',
        'tracking_number' => 'Tracking number',
        'status' => 'Status',
        'phone' => 'Phone',
        'account_id' => 'Account',
        'delivery_type' => 'Delivery type',
        'country' => 'Country',
        'city' => 'City',
        'postal_code' => 'Postal code',
        'address_line_1' => 'Address',
        'address_line_2' => 'Address (line 2)',
        'carrier_code' => 'Carrier',
        'pickup_point_reference' => 'Pickup point',
        'settlement' => 'Settlement',
        'image' => 'Image',
        'product_name' => 'Product',
        'sku' => 'SKU',
        'quantity' => 'Quantity',
        'unit_price' => 'Price',
        'promotion_discount' => 'Discount',
        'discretionary_discount' => 'Merchant discount',
        'net_paid' => 'Final price',
        'promotion_code' => 'Code',
        'promotion_redeemed' => 'Redeemed code',
        'provider_reference' => 'Reference',
        'failure_reason' => 'Failure reason',
        'attempted_at' => 'Attempted at',
        // §8.4 stage 7c-1 — the two facts the Payment section could not state
        // before "Mark as received" (see that section's own comment block).
        // 'Money held' names the fact §4.1 means by settled (a held hold, not a
        // debt settled); 'Received at' follows attempted_at's own
        // '<participle> at' shape for the instant §4.1's confirm() ran.
        'payment_settled' => 'Money held',
        'payment_confirmed_at' => 'Received at',
        'occurred_at' => 'Date',
        'event_type' => 'Event',
        'from_status' => 'From',
        'to_status' => 'To',
        'reason' => 'Reason',
        // §8.4 stage 7c-1 — the History table's return-reference column: the
        // return's own Transaction id, rendered as TEXT (there is still no
        // Transaction page to link to — see historyRows()'s own docblock).
        // Named for what a merchant reads it as, not for the column it comes
        // from.
        'return_record' => 'Change',
        'staff_name' => 'By',
    ],

    'sections' => [
        'summary' => 'Summary',
        'client' => 'Client & contact',
        'delivery' => 'Delivery',
        'lines' => 'Items',
        'promotion' => 'Promotion',
        'totals' => 'Totals',
        'payment' => 'Payment',
        'payment_shipping' => 'Payment and shipping',
        'order_details' => 'Order details',
        'invoice' => 'Invoice',
        'origin' => 'Origin',
        'history' => 'History',
    ],

    'channel_options' => [
        'web' => 'Web',
        'pos' => 'POS',
    ],

    'payment_method_options' => [
        'cash_on_delivery' => 'Cash on delivery',
        'bank_transfer' => 'Bank transfer',
    ],

    'payment_status_options' => [
        'pending' => 'Pending',
        'captured' => 'Captured',
        'failed' => 'Failed',
    ],

    // The order's own six statuses (order-lifecycle-design.md §1, §10 stage 2).
    // `fulfilled` is deliberately GONE rather than kept as a dead key: the enum
    // no longer has the case, so nothing can ever produce the value again.
    'status_options' => [
        'placed' => 'Placed',
        'confirmed' => 'Confirmed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ],

    // The order's own history — one label per App\Enums\OrderEventType value
    // (order-lifecycle-design.md §6.1, §10 stage 2). A type with no
    // entry here would render as its raw snake_case value rather than fail
    // (OrderResource::optionLabel(), :448-457), which is why
    // OrderEventTypeLabelsTest pins every case against both languages.
    'event_type_options' => [
        'status_changed' => 'Status changed',
        'payment_confirmed' => 'Payment recorded as received',
        'returned' => 'Goods returned',
        'refunded' => 'Money refunded',
        'refund_owed' => 'Refund owed',
        'refund_paid_out' => 'Refund paid out',
        'refund_cancelled' => 'Refund cancelled',
        'payment_voided' => 'Pending payment voided',
        'note_added' => 'Internal note',
        'edited' => 'Order edited',
        'tracking_recorded' => 'Tracking number recorded',
    ],

    'delivery_type_options' => [
        'street_address' => 'Street address',
        'pickup_point' => 'Pickup point',
    ],

    // The list's status-view toolbar buttons (OrderResource::STATUS_VIEWS) —
    // a separate group from status_options above, mirroring products.
    // status_views: the words happen to match today, but the two stay
    // independently editable (Product's own status_views group does the
    // same for the identical reason).
    'status_views' => [
        'all' => 'All',
        'placed' => 'Placed',
        'confirmed' => 'Confirmed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ],

    // One label per App\Enums\OrderRefusalReason value (order-lifecycle-
    // design.md §2.2, §10 stage 6a) — R9's "bank transfer must have arrived"
    // is the first. Minimal on purpose: one case, one key, added again only
    // when a real guard needs one.
    // Refunds R1b (shipping-domain-design.md §7.2.2, §7.2.3, §7.2.8): the sentences
    // the refund caps, the operation key and the money permission refuse with.
    'refund_caps' => [
        'line' => 'This refund is more than what is left to refund for one of the lines: at most :room can still be refunded for it.',
        'shipping' => 'The shipping refund is more than the shipping that was paid: at most :room can still be refunded.',
        'total' => 'This refund is more than the customer paid: at most :room can still be refunded.',
        'deduction' => 'The deduction is larger than the goods plus the shipping being refunded (at most :room). Nothing was recorded.',
        'pending_shipping' => 'The shipping reduction is more than the shipping that has not been reduced yet: at most :room can still be taken off. Nothing was recorded.',
    ],

    'pending_payment_rules' => [
        'deduction' => 'This order has not been paid, so there is nothing to deduct from. Remove the deduction. Nothing was recorded.',
        'goods' => 'This order has not been paid, so the goods amount cannot be changed: it is the computed share of the returned units. Clear the entered amount. Nothing was recorded.',
    ],

    'refund_permission_denied' => [
        'cash' => 'Refunding in cash from the register needs the "refund in cash" permission, which you do not have. Nothing was recorded.',
        'bank' => 'Refunding through the bank needs the "refund by bank" permission, which you do not have. Nothing was recorded.',
    ],

    // Refunds R2b (shipping-domain-design.md §7.2.5, §7.2.17): the refunds section of the order page and its two actions.
    // Order view polish: the order-context fields are designed (order-context-design.md) and not built, so each renders
    // "not recorded" — NEVER "No".
    'context' => [
        'not_recorded' => 'n/a',
        'fields' => [
            'customer_ip' => 'IP address',
            'ip_short' => 'IP',
            'visitor_id' => 'Visitor ID',
            'terms_accepted' => 'Terms accepted',
            'confirmation_requested' => 'Order confirmation requested',
            'call_before_shipping' => 'Call before shipping',
            'company_name' => 'Company name',
            'vat_number' => 'VAT / company number',
            'billing_address' => 'Billing address',
            'source_type' => 'Source type',
            'campaign' => 'Campaign / ad',
            'landing_page' => 'Landing page',
            'referrer' => 'Referrer',
        ],
    ],

    'money' => [
        'paid' => 'Paid',
        'refunded' => 'Refunded (paid out)',
        'refund_owed' => 'Refund owed',
    ],

    'refunds' => [
        'heading' => 'Refunds',
        'heading_count' => 'Refunds (:count)',
        'refund_heading' => 'Refund #:id',
        'figures' => [
            'paid_in' => 'Paid in',
            'paid_out' => 'Refunded and paid out',
            'owed' => 'Refunded, still owed',
            'still_refundable' => 'Still refundable',
        ],
        'status' => [
            'owed' => 'Owed',
            'paid_out' => 'Paid out',
            'legacy_paid_out' => 'Paid out (legacy)',
            'cancelled' => 'Cancelled',
            'requested' => 'Requested',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ],
        'channel' => [
            'cash' => 'Cash from the register',
            'bank' => 'Bank',
        ],
        'fields' => [
            'status' => 'State',
            'channel' => 'Channel',
            'goods' => 'Goods',
            'shipping' => 'Shipping',
            'adjustment' => 'Adjustment',
            'deduction' => 'Deduction',
            'deduction_reason' => 'Deduction reason',
            'total' => 'Total',
            'recorded_at' => 'Recorded',
            'recorded_by' => 'Recorded by',
            'reason' => 'Reason',
            'paid_out_at' => 'Paid out',
            'paid_out_by' => 'Paid out by',
            'paid_out_reference' => 'Reference',
            'paid_out_note' => 'Note',
            'cancelled_at' => 'Cancelled',
            'cancelled_by' => 'Cancelled by',
            'cancelled_reason' => 'Why it was cancelled',
        ],
        'mark_paid_out' => [
            'label' => 'Mark paid out',
            'heading' => 'Mark refund #:id as paid out',
            'description' => 'Confirms that :amount has been paid back. The amount cannot be changed here.',
            'paid_out_at' => 'Payout date',
            'reference' => 'Bank reference',
            'note' => 'Note',
            'done' => 'Refund #:id marked as paid out.',
        ],
        'cancel' => [
            'label' => 'Cancel refund',
            'heading' => 'Cancel refund #:id',
            'warning' => 'Only the money record is cancelled. The goods stay returned and restocked, and nothing is paid back for them.',
            'reason' => 'Reason',
            'done' => 'Refund #:id cancelled.',
        ],
        'invariant_broken' => 'The numbers of this order do not add up, so the refund was not recorded. Nothing was changed. Please contact support.',
    ],

    'refund_transition' => [
        'refund_not_found' => 'That refund was not found.',
        'not_owed' => 'Only a refund that is still owed can be paid out or cancelled. This one is not (it may already be paid out or cancelled). Nothing was changed.',
        'payout_in_future' => 'The payout date cannot be in the future. Nothing was changed.',
        'bank_reference_required' => 'A refund paid through the bank needs its payment reference. Nothing was changed.',
        'reason_required' => 'Cancelling a refund needs a reason. Nothing was changed.',
        'actor_required' => 'This needs a signed-in staff member. Nothing was changed.',
        'refund_lines_unlinked' => 'This refund cannot be cancelled automatically: its goods lines cannot be matched to the return that created it. Nothing was changed.',
        'storno_mismatch' => 'This refund cannot be cancelled: its recorded lines do not agree with the ledger. Nothing was changed.',
    ],

    'operation_key_reused' => 'This form was already submitted, with different contents. Nothing was changed; open the dialog again and re-enter what you want.',

    'refusal_reasons' => [
        'bank_transfer_not_settled' => 'This order\'s bank transfer has not been recorded as settled yet.',
        'order_not_cancellable' => 'This order cannot be cancelled from its current status.',
        'order_not_returnable' => 'A return cannot be recorded against this order in its current status.',
    ],

    'filters' => [
        'all_payment_methods' => 'All payment methods',
    ],

    // The View page's four header actions (order-lifecycle-design.md §8,
    // §10 stage 7b) — the first write buttons this Resource has ever had.
    'actions' => [
        'confirm' => 'Confirm',
        'confirm_heading' => 'Confirm order :id',
        'confirm_description' => 'Accept order :id and start preparing it.',
        'confirm_done' => 'Order confirmed.',

        'ship' => 'Ship',
        'ship_heading' => 'Ship order :id',
        'ship_description' => 'Mark order :id as shipped.',
        'ship_done' => 'Order marked as shipped.',

        'deliver' => 'Deliver',
        'deliver_heading' => 'Deliver order :id',
        'deliver_description' => 'Mark order :id as delivered to the customer.',
        'deliver_done' => 'Order marked as delivered.',

        'mark_as_received' => 'Mark as received',
        'mark_as_received_heading' => 'Mark payment as received for order :id',
        'mark_as_received_description' => 'Record this order\'s pending payment as received.',
        'mark_as_received_done' => 'Payment recorded as received.',
        // D2's own graceful refusal (§0 item — no eligible payment left at click time).
        'no_eligible_payment' => 'No payment on this order is eligible to be marked as received.',

        'cancel' => 'Cancel',
        'cancel_heading' => 'Cancel order :id',
        'cancel_description' => 'Cancel order :id.',
        'cancel_done' => 'Order :id cancelled.',

        'record_return' => 'Record a return',
        'record_return_heading' => 'Record a return for order :id',
        'record_return_description' => 'Record which units of order :id have come back.',
        'record_return_done' => 'Recorded a return of :count unit(s) on order :id.',

        'add_note' => 'Add note',
        'add_note_heading' => 'Add a note to order :id',
        'add_note_description' => 'Record an internal note on order :id. Visible to anyone who can view this order.',
        'add_note_done' => 'Note added.',
        // The ONE genuinely required field on this page — an empty note is
        // nothing to record (D1). Deliberately a different key from
        // note_label below, which is every OTHER action's optional note.
        'note_field_label' => 'Note',

        'quantity_label' => 'Quantity',
        'restock_label' => 'Return to stock',
        'reason_label' => 'Reason (optional)',
        // D5's own client-side rule — every line left at 0/blank.
        'nothing_to_return' => 'Enter at least one unit to record a return.',

        'note_label' => 'Note (optional)',
        'refused_title' => 'This action was refused',
        // InvalidOrderTransitionException's own from()/to() (§8.3 item 4) — never its raw English message.
        'invalid_transition_body' => 'Order :id cannot move from :from to :to.',
        // ReturnExceedsRemainingQuantityException's own real accessors (§10 stage 7c-2, D7) — never its raw message either.
        'return_exceeds_remaining_body' => 'Cannot return :requested unit(s) of ":line": only :remaining unit(s) remain.',
        // InvalidArgumentException (an unknown/changed record) — never its raw message either.
        'generic_refusal_body' => 'Order :id could not be processed — it may no longer exist, or its state changed since this page was loaded.',
        // Order editing (stage 4b-i, order-editing-design.md section 8)
        'edit' => 'Edit order',
        'edit_heading' => 'Edit order :id',
        'edit_description' => 'Reduce or remove items, change the delivery or the promotion code. The order total and the pending payment follow automatically.',
        'edit_done' => 'Order :id updated.',
        'edit_lines_hint' => 'Use Remove to take an item off the order (it can be restored before saving). A quantity can be reduced here, not raised.',
        // Adding a line (stage 4b-ii)
        'edit_add_heading' => 'Add a product',
        'edit_add_hint' => 'Search by name, SKU or barcode, then set how many to add. The price comes from the price list — it is never typed in here.',
        'edit_add_product_placeholder' => 'Type to search…',
        'edit_add_quantity' => 'Quantity to add',
        'edit_add_merge_hint' => 'This order already sells this variation, so these units are added to that line at its own price.',
        'edit_add_no_price' => 'No price is configured for this product.',
        'edit_add_unavailable' => 'This product cannot be added to an order.',
        'edit_add_unavailable_body' => 'The selected product cannot be added to this order: it is archived, it is not purchasable, or it no longer exists. Nothing was saved.',
        'edit_add_no_price_body' => 'The selected product has no price configured in this order\'s currency, so it cannot be added. Nothing was saved.',
        'edit_add_insufficient_stock_body' => 'There is not enough stock to add this item in the quantity asked for. Nothing was saved — check the stock and try again.',
        'edit_remove_line' => 'Remove',
        'edit_restore_line' => 'Restore',
        'edit_remove_last_line' => 'An order cannot be left with no items. To cancel it entirely, use the Cancel action.',
        'edit_current_quantity' => 'Now',
        'edit_new_quantity' => 'New quantity',
        'edit_discount' => 'Manual discount',
        'edit_promotion_code' => 'Promotion code',
        'edit_promotion_code_hint' => 'Leave as is to keep the code, type another code to replace it.',
        'edit_remove_promotion_code' => 'Remove the promotion code entirely',
        'edit_nothing_to_change' => 'Nothing was changed, so there is nothing to save.',
        'edit_stale_body' => 'Order :id was changed by someone else after this form was opened (revision :expected, now :actual). Nothing was saved - close this and open Edit order again.',
        'edit_not_editable_status_body' => 'Order :id can no longer be edited: it is :status, and editing is only possible while an order is placed or confirmed.',
        'edit_not_editable_payment_body' => 'Order :id can no longer be edited: a payment for it has already settled. Use cancel or return instead.',
        'edit_promotion_invalid_body' => 'The promotion code ":code" cannot be applied to this order: :reason. Nothing was saved - change the quantities, or remove the code, and try again.',
    ],

    // PromotionValidator's own reason codes, worded for the edit dialog's refusal.
    'promotion_refusal_reasons' => [
        'not_found' => 'no such code exists',
        'inactive' => 'the code is not active',
        'not_yet_active' => 'the code is not valid yet',
        'expired' => 'the code has expired',
        'minimum_spend_not_met' => "the order is below the code's minimum spend",
        'maximum_spend_exceeded' => "the order is above the code's maximum spend",
        'new_customers_only' => 'the code is for new customers only',
        'account_scope_mismatch' => 'the code is not available for this customer',
        'usage_limit_reached' => 'the code has reached its usage limit',
        'usage_limit_per_customer_reached' => 'this customer has used the code the maximum number of times',
        'no_matching_lines' => "none of the order's items qualify for the code",
    ],

    'yes' => 'Yes',
    'no' => 'No',
    'no_promotion' => 'No promotion applied.',
    'no_payment' => 'No payment record.',
    // §8.4 stage 7c-1 — the two states of the Payment section's settled
    // badge. The positive one is §4.1's own "money is held"; the negative one
    // states the money as NOT RECORDED, never "unpaid": §4.5 forbids a
    // computed 'unpaid' badge, because a payment row with no confirmation can
    // just as truthfully be a COD delivery that never had to confirm (§3
    // item 3 — "no computed 'unpaid' badge — but no silence either").
    'payment_settled_yes' => 'Settled',
    'payment_settled_no' => 'Not recorded',
    'payment_history' => [
        'heading' => 'Payment history',
        'amount' => 'Amount',
        'why' => 'Why it is not current',
        'failed' => 'Failed attempt',
        'superseded' => 'Superseded',
    ],
    'not_available' => '—',
    'guest' => 'Guest',
    'promotion_redeemed_yes' => 'redeemed',
    'promotion_redeemed_no' => 'not redeemed',
    'legacy_line_note' => 'Recorded before full snapshot',
    // order_events.staff_id/staff_name NULL — a console/job caller recorded
    // the event (OrderAdminEventView's own docblock names this wording).
    'system_actor' => 'System',

    // The names each LINE puts in front of its own values on the View page's
    // Lines table (admin-panel-design.md §14): a table wide enough to scroll
    // sideways scrolls its header row out of sight, so every line names its own
    // numbers, in the words the merchant reads them off in.
    'line_labels' => [
        'sku' => 'SKU',
        'returned_or_removed' => 'returned or removed: :count',
        'quantity' => 'Qty',
        'unit_price' => 'Price',
        'discount' => 'Discount',
        'amount' => 'Total',
    ],

];
