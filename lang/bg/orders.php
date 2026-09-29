<?php

return [

    'label' => 'Поръчка',
    'plural_label' => 'Поръчки',
    'navigation_label' => 'Поръчки',

    'fields' => [
        'id' => '№',
        'placed_at' => 'Дата',
        'recipient_name' => 'Получател',
        'email' => 'Имейл',
        'client_name' => 'Клиент',
        'client_id' => 'ID на клиента',
        'channel' => 'Канал',
        'payment_method' => 'Начин на плащане',
        'payment_status' => 'Статус на плащане',
        'payment_attempts' => 'Опити за плащане',
        'total' => 'Обща сума',
        'subtotal' => 'Междинна сума',
        'discount' => 'Отстъпка',
        'status' => 'Статус',
        'phone' => 'Телефон',
        'account_id' => 'Профил',
        'delivery_type' => 'Начин на доставка',
        'country' => 'Държава',
        'city' => 'Град',
        'postal_code' => 'Пощенски код',
        'address_line_1' => 'Адрес',
        'address_line_2' => 'Адрес (продължение)',
        'carrier_code' => 'Куриер',
        'pickup_point_reference' => 'Офис на куриер',
        'settlement' => 'Населено място',
        'image' => 'Снимка',
        'product_name' => 'Продукт',
        'sku' => 'SKU',
        'quantity' => 'Количество',
        'unit_price' => 'Цена',
        'promotion_discount' => 'Отстъпка',
        'discretionary_discount' => 'Отстъпка от търговеца',
        'net_paid' => 'Крайна цена',
        'promotion_code' => 'Код',
        'promotion_redeemed' => 'Използван код',
        'provider_reference' => 'Референция',
        'failure_reason' => 'Причина за отказ',
        'attempted_at' => 'Опитано на',
        // §8.4 stage 7c-1 — the two facts the Payment section could not state
        // before "Mark as received" (see en/orders.php's own comment for the
        // wording). 'Money held' is §4.1's settled fact; 'Received at' follows
        // attempted_at's own '<participle> at' shape.
        'payment_settled' => 'Държани пари',
        'payment_confirmed_at' => 'Получено на',
        'occurred_at' => 'Дата',
        'event_type' => 'Събитие',
        'from_status' => 'От',
        'to_status' => 'До',
        'reason' => 'Причина',
        // §8.4 stage 7c-1 — the History table's return-reference column: the
        // return's own Transaction id, rendered as text (no Transaction page
        // exists to link to yet). Named for what a merchant reads it as, not
        // for the column it comes from.
        'return_record' => 'Запис за връщане',
        'staff_name' => 'От (служител)',
    ],

    'sections' => [
        'summary' => 'Обобщение',
        'client' => 'Клиент и контакт',
        'delivery' => 'Доставка',
        'lines' => 'Артикули',
        'promotion' => 'Промоция',
        'totals' => 'Суми',
        'payment' => 'Плащане',
        'history' => 'История',
    ],

    'channel_options' => [
        'web' => 'Уеб',
        'pos' => 'Каса',
    ],

    'payment_method_options' => [
        'cash_on_delivery' => 'Наложен платеж',
        'bank_transfer' => 'Банков превод',
    ],

    'payment_status_options' => [
        'pending' => 'Изчаква',
        'captured' => 'Платена',
        'failed' => 'Неуспешна',
    ],

    // The order's own six statuses (order-lifecycle-design.md §1, §10 stage 2).
    // `fulfilled` is deliberately GONE rather than kept as a dead key: the enum
    // no longer has the case, so nothing can ever produce the value again.
    'status_options' => [
        'placed' => 'Приета',
        'confirmed' => 'Потвърдена',
        'shipped' => 'Изпратена',
        'delivered' => 'Доставена',
        'cancelled' => 'Отказана',
        'refunded' => 'Върната',
    ],

    // The order's own history — one label per App\Enums\OrderEventType value
    // (order-lifecycle-design.md §6.1, §10 stage 2). A type with no entry here
    // would render as its raw snake_case value rather than fail
    // (OrderResource::optionLabel(), :448-457), which is why
    // OrderEventTypeLabelsTest pins every case against both languages.
    'event_type_options' => [
        'status_changed' => 'Смяна на статус',
        'payment_confirmed' => 'Плащането е отбелязано като получено',
        'returned' => 'Върната стока',
        'refunded' => 'Върнати пари',
        'payment_voided' => 'Чакащо плащане анулирано',
        'note_added' => 'Вътрешна бележка',
    ],

    'delivery_type_options' => [
        'street_address' => 'Адрес',
        'pickup_point' => 'Офис на куриер',
    ],

    // The list's status-view toolbar buttons (OrderResource::STATUS_VIEWS) —
    // see the en/ file's own comment for why this is a separate group from
    // status_options above.
    'status_views' => [
        'all' => 'Всички',
        'placed' => 'Приета',
        'confirmed' => 'Потвърдена',
        'shipped' => 'Изпратена',
        'delivered' => 'Доставена',
        'cancelled' => 'Отказана',
        'refunded' => 'Върната',
    ],

    // One label per App\Enums\OrderRefusalReason value (order-lifecycle-
    // design.md §2.2, §10 stage 6a). Wording pending owner review — see this
    // stage's own report.
    'refusal_reasons' => [
        'bank_transfer_not_settled' => 'Банковият превод за тази поръчка още не е отбелязан като получен.',
        'order_not_cancellable' => 'Тази поръчка не може да бъде отказана в текущия си статус.',
        'order_not_returnable' => 'Не може да се регистрира връщане за тази поръчка в текущия ѝ статус.',
    ],

    'filters' => [
        'all_payment_methods' => 'Всички начини на плащане',
    ],

    // The View page's four header actions — see en/orders.php's own comment.
    'actions' => [
        'confirm' => 'Потвърди',
        'confirm_heading' => 'Потвърждаване на поръчка :id',
        'confirm_description' => 'Приемане на поръчка :id и подготовка за изпращане.',
        'confirm_done' => 'Поръчката е потвърдена.',

        'ship' => 'Изпрати',
        'ship_heading' => 'Изпращане на поръчка :id',
        'ship_description' => 'Отбелязване на поръчка :id като изпратена.',
        'ship_done' => 'Поръчката е отбелязана като изпратена.',

        'deliver' => 'Достави',
        'deliver_heading' => 'Доставяне на поръчка :id',
        'deliver_description' => 'Отбелязване на поръчка :id като доставена на клиента.',
        'deliver_done' => 'Поръчката е отбелязана като доставена.',

        'mark_as_received' => 'Отбележи като получено',
        'mark_as_received_heading' => 'Отбелязване на плащане като получено за поръчка :id',
        'mark_as_received_description' => 'Записване на чакащото плащане по тази поръчка като получено.',
        'mark_as_received_done' => 'Плащането е отбелязано като получено.',
        'no_eligible_payment' => 'Нито едно плащане по тази поръчка не отговаря на условията да бъде отбелязано като получено.',

        'note_label' => 'Бележка (незадължително)',
        'refused_title' => 'Действието беше отказано',
        'invalid_transition_body' => 'Поръчка :id не може да премине от :from към :to.',
        'generic_refusal_body' => 'Поръчка :id не можа да бъде обработена — може вече да не съществува, или състоянието ѝ се е променило след зареждането на страницата.',
    ],

    'yes' => 'Да',
    'no' => 'Не',
    'no_promotion' => 'Няма приложена промоция.',
    'no_payment' => 'Няма запис за плащане.',
    // §8.4 stage 7c-1 — the two states of the Payment section's settled
    // badge (en/orders.php's own comment carries the full argument): the
    // positive one is §4.1's "money is held", the negative one states the money
    // as NOT RECORDED, never "unpaid" (§4.5 / §3 item 3).
    'payment_settled_yes' => 'Получени',
    'payment_settled_no' => 'Няма запис',
    'attempts_suffix' => ':count опита',
    'not_available' => '—',
    'legacy_line_note' => 'Записано преди пълния запис',
    // order_events.staff_id/staff_name NULL — записът е направен от конзолата
    // или фонова задача (виж en/orders.php's own comment).
    'system_actor' => 'Системата',

    // The names each LINE puts in front of its own values on the View page's
    // Lines table (admin-panel-design.md §14): a table wide enough to scroll
    // sideways scrolls its header row out of sight, so every line names its own
    // numbers, in the words the merchant reads them off in.
    'line_labels' => [
        'quantity' => 'Бройка',
        'unit_price' => 'Цена',
        'discount' => 'Отстъпка',
        'amount' => 'Сума',
    ],

];
