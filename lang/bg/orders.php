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
        'product_name' => 'Продукт',
        'sku' => 'SKU',
        'quantity' => 'Количество',
        'unit_price' => 'Единична цена',
        'line_total' => 'Сума',
        'promotion_discount' => 'Промо отстъпка',
        'discretionary_discount' => 'Ръчна отстъпка',
        'net_paid' => 'Платено нето',
        'unit_cost' => 'Себестойност',
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

    'status_options' => [
        'placed' => 'Направена',
        'fulfilled' => 'Изпълнена',
        'cancelled' => 'Отменена',
    ],

    'delivery_type_options' => [
        'street_address' => 'Адрес',
        'pickup_point' => 'Офис на куриер',
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

];
