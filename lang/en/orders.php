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
        'payment_attempts' => 'Payment attempts',
        'total' => 'Total',
        'subtotal' => 'Subtotal',
        'discount' => 'Discount',
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
        'occurred_at' => 'Date',
        'event_type' => 'Event',
        'from_status' => 'From',
        'to_status' => 'To',
        'reason' => 'Reason',
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
        'payment_voided' => 'Pending payment voided',
        'note_added' => 'Internal note',
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

        'note_label' => 'Note (optional)',
        'refused_title' => 'This action was refused',
        // InvalidOrderTransitionException's own from()/to() (§8.3 item 4) — never its raw English message.
        'invalid_transition_body' => 'Order :id cannot move from :from to :to.',
        // InvalidArgumentException (an unknown/changed record) — never its raw message either.
        'generic_refusal_body' => 'Order :id could not be processed — it may no longer exist, or its state changed since this page was loaded.',
    ],

    'yes' => 'Yes',
    'no' => 'No',
    'no_promotion' => 'No promotion applied.',
    'no_payment' => 'No payment record.',
    'attempts_suffix' => ':count attempts',
    'not_available' => '—',
    'legacy_line_note' => 'Recorded before full snapshot',
    // order_events.staff_id/staff_name NULL — a console/job caller recorded
    // the event (OrderAdminEventView's own docblock names this wording).
    'system_actor' => 'System',

    // The names each LINE puts in front of its own values on the View page's
    // Lines table (admin-panel-design.md §14): a table wide enough to scroll
    // sideways scrolls its header row out of sight, so every line names its own
    // numbers, in the words the merchant reads them off in.
    'line_labels' => [
        'quantity' => 'Qty',
        'unit_price' => 'Price',
        'discount' => 'Discount',
        'amount' => 'Total',
    ],

];
