<?php

/*
 * Mail strings (mail-design.md, stage M1): the labels of the blocks inside the order-confirmation mail
 * and the "Payment: bank transfer" settings page. The wording of the mail itself lives in
 * resources/mail/{locale}/*.md. bg/en are kept together (tests/Feature/Mail/MailLangParityTest.php).
 */
return [

    'order' => [
        'lines' => [
            'product' => 'Артикул',
            'quantity' => 'Бр.',
            'unit_price' => 'Цена',
            'line_total' => 'Сума',
            'sku' => 'Код',
        ],
        'totals' => [
            'subtotal' => 'Междинна сума',
            'discount' => 'Отстъпка',
            'discount_with_code' => 'Отстъпка (:code)',
            'shipping' => 'Доставка',
            'shipping_named' => 'Доставка (:method)',
            'shipping_free' => 'Безплатна',
            'total' => 'Общо',
        ],
        'delivery' => [
            'heading' => 'Доставка',
            'method' => 'Начин на доставка',
            'recipient' => 'Получател',
            'address' => 'Адрес',
            'pickup_point' => 'Офис за получаване',
            'courier' => 'Куриер',
            'reference' => 'Код на офиса',
        ],
    ],

    'payment' => [
        'methods' => [
            'bank_transfer' => 'Банков превод',
            'cash_on_delivery' => 'Наложен платеж',
        ],
        'unknown' => 'Предстои уточняване',
        'cash_on_delivery_due' => 'Сума за плащане при доставка: :amount',
    ],

    'bank_transfer' => [
        'heading' => 'Плащане с банков превод',
        'holder' => 'Титуляр на сметката',
        'bank' => 'Банка',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'reason' => 'Основание за плащане',
        'reason_value' => 'Поръчка :number',
        'amount' => 'Сума',
        'deadline' => 'Моля, платете в рамките на :days дни от датата на поръчката.',
        'iban_invalid' => 'Въведете валиден IBAN, например BG80BNBG96611020345678.',
        'bic_invalid' => 'Въведете валиден BIC от 8 или 11 знака, например BNBGBGSD.',
    ],

    'bank_transfer_page' => [
        'navigation_label' => 'Плащане: банков превод',
        'title' => 'Плащане: банков превод',
        'help' => 'Тези данни се показват в имейла за потвърждение на поръчките, платени с банков превод. Докато IBAN и инструкциите са празни, имейлът за потвърждение няма да съдържа данни за плащане.',
        'account_holder' => 'Титуляр на сметката',
        'bank_name' => 'Име на банката',
        'iban' => 'IBAN',
        'iban_help' => 'Интервалите се игнорират. Номерът се проверява за валиден формат.',
        'bic' => 'BIC / SWIFT',
        'deadline_days' => 'Срок за плащане (дни)',
        'deadline_help' => 'По желание, от 1 до 60. Оставете празно, ако не искате изречение със срок.',
        'instructions' => 'Инструкции',
        'instructions_help' => 'Свободен текст под данните, до 1000 знака. Само обикновен текст.',
        'save_label' => 'Запази',
        'saved_notification' => 'Данните за плащане са запазени',
    ],
];
