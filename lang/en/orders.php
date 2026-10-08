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
        'payment_method_status' => 'Status from the payment method',
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
        'payment_settled' => 'Money received',
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
        'payment_receipt_recorded' => 'Bank transfer received',
        'payment_receipt_corrected' => 'Bank transfer record corrected',
        'payment_mismatch_accepted' => 'Different transfer amount accepted',
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

    // Refunds R3 part 2: the cancel / return dialog's money part, the facts panel, the money-only action.
    'history' => [
        'announced_on' => 'Customer announced the return for: :day',
    ],

    'refund_dialog' => [
        'section' => 'Refund',
        'goods_label' => 'Goods refund',
        'goods_hint' => 'Prefilled with the computed share; you may change it. Room left on this line: :room.',
        'goods_hint_free' => 'Prefilled with the computed share; you may change it.',
        'goods_readonly' => 'Nothing has been paid for this order, so nothing is refunded: this is the computed share and cannot be changed.',
        'shipping_label' => 'Shipping refund',
        'shipping_hint' => 'Room left: :room.',
        'shipping_reduction_label' => 'Shipping reduction',
        'shipping_reduction_hint' => 'Reduces what the customer still owes for delivery. Room left: :room. It has no effect if every unit is returned.',
        'shipping_not_included' => 'Shipping (:amount) is not included.',
        'deduction_label' => 'Deduction',
        'deduction_hint' => 'Money the shop keeps from this refund. Needs a reason.',
        'deduction_reason_label' => 'Reason for the deduction',
        'channel_label' => 'Pay out through',
        'channel_options' => [
            'cash' => 'Cash from the register',
            'bank' => 'Bank',
        ],
        'no_channel' => 'This order has been paid, and you have neither the "refund in cash" nor the "refund by bank" permission, so you cannot record this. Ask an administrator.',
        'total' => 'Refund total: :total',
        'still_refundable' => 'Still refundable on this order: :room',
        'over_total' => 'This is more than can still be refunded (:room). The system will refuse it.',
        'pending_total' => 'Nothing has been paid, so no money is refunded; the unpaid amount is reduced.',
        'invalid_amount' => 'Enter an amount such as 12.50 or 12,50.',
        'announced_label' => 'Date the customer announced the return',
        'announced_help' => 'The day the customer said they would return the goods; no time needed. Optional.',
        'facts_heading' => 'Facts about this order',
        'facts_delivered' => 'Delivered :date · days since delivery: :days',
        'facts_not_delivered' => 'Not marked as delivered.',
        'facts_return' => 'Earlier return: recorded :recorded, announced :announced',
        'facts_announced_none' => 'no day entered',
        'facts_return_days' => ' (days after delivery: :recorded recorded, :announced announced)',
    ],

    'money_only' => [
        'label' => 'Refund money only',
        'heading' => 'Refund money only — order :id',
        'description' => 'A refund of money without any goods coming back: goodwill or a correction. No stock changes.',
        'shipping' => 'Shipping refund',
        'adjustment' => 'Adjustment',
        'reason' => 'Reason',
        'hint' => 'Shipping room left: :shipping. Still refundable on this order: :total.',
        'total' => 'Refund total: :total',
        'done' => 'Refund of :amount recorded (owed).',
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

    // Refunds R3 part 1 (shipping-domain-design.md §7.2.6, §7.2.11).
    'money_only_refund' => [
        'reason_required' => 'A refund without a return needs a reason. Nothing was recorded.',
        'payment_not_settled' => 'This order has no settled payment, so nothing has been paid and nothing can be refunded. Nothing was recorded.',
        'deduction_not_allowed' => 'A refund without a return cannot have a deduction: there are no goods to deduct from. Nothing was recorded.',
        'nothing_to_refund' => 'Enter a shipping refund or an adjustment: the refund would be 0. Nothing was recorded.',
    ],

    // Refunds R4a-2 (shipping-domain-design.md §7.2.20): recording a bank-transfer receipt.
    'payment_receipt' => [
        'unreconciled' => 'A transfer of :received was received for this order and has not been reconciled — accept it or record the rest first. Nothing was changed.',
        'reconcile_denied' => 'Accepting a transfer that is short or over needs the permission to reconcile payments, which you do not have. Nothing was changed.',
        'refused' => [
            'not_bank_transfer' => 'Only a bank-transfer payment takes a receipt. Nothing was recorded.',
            'payment_settled' => 'This payment is already settled. Nothing was recorded.',
            'payment_voided' => 'This payment was voided and no longer counts. Nothing was recorded.',
            'payment_unanswered' => 'This payment has not been answered yet, so it cannot take a receipt. Nothing was recorded.',
            'payment_not_confirmable' => 'This payment cannot take a receipt in its current state. Nothing was recorded.',
            'too_many_effective_receipts' => 'A payment takes at most :max receipts. Nothing was recorded.',
            'too_many_receipt_rows' => 'This payment has reached its limit of :max recorded rows, corrections included. Nothing was recorded.',
            'amount_not_positive' => 'Enter an amount greater than 0. Nothing was recorded.',
            'amount_too_large' => 'The amount can have at most :digits digits before the decimal point. Nothing was recorded.',
            'currency_mismatch' => 'The amount must be in the currency of the payment (:currency). Nothing was recorded.',
            'day_malformed' => 'Enter the day the money arrived as a real date. Nothing was recorded.',
            'day_in_future' => 'The day the money arrived cannot be in the future. Nothing was recorded.',
            'day_before_placement' => 'The day the money arrived cannot be before the order was placed. Nothing was recorded.',
            'reference_blank' => 'Enter the bank reference of the transfer. Nothing was recorded.',
            'reference_too_long' => 'The bank reference can have at most :max characters. Nothing was recorded.',
            'reference_invalid' => 'The bank reference can only hold visible text on one line. Nothing was recorded.',
            'no_effective_receipt' => 'No transfer has been recorded for this payment, so there is nothing to accept. Nothing was changed.',
            'nothing_to_accept' => 'The recorded transfers add up to exactly the expected amount, so there is nothing to accept. Nothing was changed.',
            'accepted_amount_changed' => 'The amount received changed since you opened this window — it is now :received. Check it and accept again. Nothing was changed.',
            'receipt_unknown' => 'This transfer record does not exist. Nothing was changed.',
            'receipt_not_effective' => 'This transfer record has already been corrected; correct the newer one. Nothing was changed.',
            'nothing_to_correct' => 'Nothing was changed in the amount, the day or the reference, so there is nothing to correct. Nothing was recorded.',
            'receipt_amount_change_after_settlement' => 'An amount cannot be corrected after settlement; the day and the reference can. A wrong amount is put right with a refund. Nothing was changed.',
            'reason_blank' => 'Enter a reason. Nothing was changed.',
            'reason_too_long' => 'The reason can have at most :max characters. Nothing was changed.',
            'reason_invalid' => 'The reason can only hold visible text on one line. Nothing was changed.',
        ],
    ],

    // UI pass 1: the buttons of every order-page dialog. The dismiss button is always "Close" (never "Cancel": on an order page
    // that reads as cancelling the ORDER), and each submit button names what it does.
    'modal' => [
        'close' => 'Close',
        'submit' => [
            'confirm' => 'Confirm the order',
            'ship' => 'Ship the order',
            'deliver' => 'Mark as delivered',
            'mark_as_received' => 'Record the receipt',
            'accept_mismatch' => 'Accept the amount',
            'correct_receipt' => 'Save the correction',
            'cancel' => 'Cancel the order',
            'record_return' => 'Record the return',
            'refund_money_only' => 'Record the money-only refund',
            'add_note' => 'Add the note',

            // UI pass 1: the three dialogs of this page that the header menu does NOT hold — the items section's
            // own edit button, and the two buttons on a refund's own row — name their submit button the same way.
            'edit_order' => 'Save the edit',
            'mark_refund_paid_out' => 'Record the payout',
            'cancel_refund' => 'Cancel the refund',
        ],
    ],

    'actions_menu' => 'Actions',

    // UI pass 1: the ONE merchant-facing payment state of the header (facts only, no colour).
    'payment_state' => [
        'awaiting' => 'Awaiting payment',
        'partial' => 'Partly received (:received of :expected)',
        'over' => 'Over-received (:received of :expected)',
        'paid' => 'Paid',
        'paid_accepted' => 'Paid, mismatch accepted (:received of :expected)',
    ],

    // Refunds R4a-4 (shipping-domain-design.md §7.2.20 §6, §7): the admin of bank-transfer receipts.
    'receipt' => [
        'record' => [
            'heading' => 'Record a received transfer for order :id',
            'description' => 'Enter what arrived in the bank account. If the transfers add up to the expected amount the payment is settled. This only records the transfer; it does not ship or confirm the order.',
            'amount' => 'Received amount',
            'received_on' => 'Received on',
            'bank_reference' => 'Bank reference',
            'done' => 'Transfer recorded.',
            'hint' => 'Expected :expected · recorded so far :recorded · this transfer :this',
            'hint_matches' => '→ matches, the payment will be settled',
            'hint_short' => '→ does not match: short by :difference; the payment stays unsettled until the rest arrives or you accept the received amount',
            'hint_over' => '→ does not match: over by :difference; the payment stays unsettled until the rest arrives or you accept the received amount',
        ],
        'accept' => [
            'label' => 'Accept the received amount',
            'heading' => 'Accept the received amount for order :id',
            'description' => 'The transfers recorded for this order do not add up to the expected amount.',
            'expected' => 'Expected: :amount',
            'received' => 'Received: :amount',
            'short' => 'Short by :difference',
            'over' => 'Over by :difference',
            'reason' => 'Reason',
            'warning' => 'The payment will be settled for :amount; refunds are capped at :amount.',
            'help' => 'Accepting a short or over transfer is also the way to cancel or return an order that has an unreconciled receipt (those are refused until it is accepted); you can say so in the reason, so the history reads correctly.',
            'done' => 'The received amount was accepted and the payment is settled.',
            'changed' => 'The amount received changed while this window was open. Nothing was changed; open it again to see the current figure.',
        ],
        'correct' => [
            'label' => 'Correct a receipt',
            'heading' => 'Correct a recorded transfer for order :id',
            'description' => 'The old record stays in the history as superseded; the correction replaces it.',
            'receipt' => 'Transfer to correct',
            'option' => ':day · :amount · :reference',
            'reason' => 'Reason for the correction',
            'amount_locked' => 'An amount cannot be corrected after settlement; the day and the bank reference can. A wrong amount is put right with a refund.',
            'done' => 'The transfer record was corrected.',
            'not_found' => 'That transfer does not belong to this order. Nothing was changed.',
        ],
        'section' => [
            'heading' => 'Bank transfers received',
            'expected' => 'Expected',
            'received' => 'Received so far',
            'none_yet' => 'No transfer has been recorded yet.',
            'short_by' => 'Short by :difference',
            'over_by' => 'Over by :difference',
            'legacy' => 'Received amount not recorded (before receipts were kept).',
            'accepted' => 'Settled for :settled of :expected, accepted: :reason',
            'day' => 'Received on',
            'amount' => 'Amount',
            'reference' => 'Bank reference',
            'who' => 'Recorded by',
        ],
    ],

    'return_announced_date' => [
        'in_future' => 'The date the customer announced the return cannot be in the future. Nothing was recorded.',
        'before_placement' => 'The date the customer announced the return cannot be before the order was placed. Nothing was recorded.',
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
        'edit_invalid_discount' => 'Enter a discount such as 12.50.',
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
    'payment_settled_yes' => 'Yes',
    'payment_settled_no' => 'No',
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
