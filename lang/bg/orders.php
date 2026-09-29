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
    ],

    'sections' => [
        'summary' => 'Обобщение',
        'client' => 'Клиент и контакт',
        'delivery' => 'Доставка',
        'lines' => 'Артикули',
        'promotion' => 'Промоция',
        'totals' => 'Суми',
        'payment' => 'Плащане',
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

    'yes' => 'Да',
    'no' => 'Не',
    'no_promotion' => 'Няма приложена промоция.',
    'no_payment' => 'Няма запис за плащане.',
    'attempts_suffix' => ':count опита',
    'not_available' => '—',
    'legacy_line_note' => 'Записано преди пълния запис',

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
