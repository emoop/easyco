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
        'total' => 'Обща сума',
        'subtotal' => 'Междинна сума',
        'discount' => 'Отстъпка',
        'shipping' => 'Доставка',
        'shipping_method' => 'Начин на доставка',
        'delivery_address' => 'Адрес',
        'tracking_number' => 'Номер за проследяване',
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
        'return_record' => 'Промяна',
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
        'payment_shipping' => 'Плащане и доставка',
        'order_details' => 'Детайли на поръчката',
        'invoice' => 'Фактура',
        'origin' => 'Произход',
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
        'refund_owed' => 'Дължимо възстановяване',
        'refund_paid_out' => 'Възстановяването е изплатено',
        'refund_cancelled' => 'Възстановяването е отменено',
        'payment_voided' => 'Чакащо плащане анулирано',
        'note_added' => 'Вътрешна бележка',
        // Draft — flagged for the owner's review (§0's own posture for new copy).
        'edited' => 'Поръчката е редактирана',
        'tracking_recorded' => 'Записан е номер за проследяване',
        'payment_receipt_recorded' => 'Получен е банков превод',
        'payment_receipt_corrected' => 'Коригиран е запис на банков превод',
        'payment_mismatch_accepted' => 'Приета е различна сума на превода',
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
    // Refunds R1b (shipping-domain-design.md §7.2.2, §7.2.3, §7.2.8): the sentences
    // the refund caps, the operation key and the money permission refuse with.
    'refund_caps' => [
        'line' => 'Това възстановяване е повече от остатъка за един от редовете: за него могат да се върнат най-много :room.',
        'shipping' => 'Връщането на доставката е повече от платената доставка: могат да се върнат най-много :room.',
        'total' => 'Това възстановяване е повече от платеното от клиента: могат да се върнат най-много :room.',
        'deduction' => 'Удръжката е по-голяма от стоките плюс доставката, които се връщат (най-много :room). Нищо не е записано.',
        'pending_shipping' => 'Намалението на доставката е повече от доставката, която още не е намалена: могат да се свалят най-много :room. Нищо не е записано.',
    ],

    'pending_payment_rules' => [
        'deduction' => 'Тази поръчка не е платена, затова няма от какво да се удържа. Премахнете удръжката. Нищо не е записано.',
        'goods' => 'Тази поръчка не е платена, затова сумата за стоките не може да се променя: тя е изчисленият дял на върнатите бройки. Изчистете въведената сума. Нищо не е записано.',
    ],

    'refund_permission_denied' => [
        'cash' => 'Връщането в брой от касата изисква правото „връщане в брой“, което нямате. Нищо не е записано.',
        'bank' => 'Връщането по банков път изисква правото „връщане по банка“, което нямате. Нищо не е записано.',
    ],

    // Refunds R2b (shipping-domain-design.md §7.2.5, §7.2.17): секцията за възстановяванията на поръчката и двете ѝ действия.
    // Order view polish: полетата на контекста на поръчката са проектирани (order-context-design.md) и още не са изградени,
    // затова всяко се показва като „не е записано“ — НИКОГА като „Не“.
    'context' => [
        'not_recorded' => 'н/д',
        'fields' => [
            'customer_ip' => 'IP адрес',
            'ip_short' => 'IP',
            'visitor_id' => 'ID посетител',
            'terms_accepted' => 'Приети условия',
            'confirmation_requested' => 'Поискано потвърждение на поръчката',
            'call_before_shipping' => 'Обаждане преди изпращане',
            'company_name' => 'Име на фирмата',
            'vat_number' => 'ДДС / ЕИК номер',
            'billing_address' => 'Адрес за фактуриране',
            'source_type' => 'Вид източник',
            'campaign' => 'Кампания / реклама',
            'landing_page' => 'Входна страница',
            'referrer' => 'Препращащ сайт',
        ],
    ],

    'money' => [
        'paid' => 'Платено',
        'refunded' => 'Възстановено (изплатено)',
        'refund_owed' => 'Дължимо възстановяване',
    ],

    'refunds' => [
        'heading' => 'Възстановявания',
        'heading_count' => 'Възстановявания (:count)',
        'refund_heading' => 'Възстановяване №:id',
        'figures' => [
            'paid_in' => 'Платено',
            'paid_out' => 'Възстановено и изплатено',
            'owed' => 'Възстановено, още се дължи',
            'still_refundable' => 'Още може да се възстанови',
        ],
        'status' => [
            'owed' => 'Дължимо',
            'paid_out' => 'Изплатено',
            'legacy_paid_out' => 'Изплатено (старо)',
            'cancelled' => 'Отменено',
            'requested' => 'Заявено',
            'completed' => 'Завършено',
            'failed' => 'Неуспешно',
        ],
        'channel' => [
            'cash' => 'В брой от касата',
            'bank' => 'По банка',
        ],
        'fields' => [
            'status' => 'Състояние',
            'channel' => 'Канал',
            'goods' => 'Стоки',
            'shipping' => 'Доставка',
            'adjustment' => 'Корекция',
            'deduction' => 'Удръжка',
            'deduction_reason' => 'Причина за удръжката',
            'total' => 'Общо',
            'recorded_at' => 'Записано',
            'recorded_by' => 'Записано от',
            'reason' => 'Причина',
            'paid_out_at' => 'Изплатено на',
            'paid_out_by' => 'Изплатено от',
            'paid_out_reference' => 'Референция',
            'paid_out_note' => 'Бележка',
            'cancelled_at' => 'Отменено на',
            'cancelled_by' => 'Отменено от',
            'cancelled_reason' => 'Защо е отменено',
        ],
        'mark_paid_out' => [
            'label' => 'Отбележи като изплатено',
            'heading' => 'Отбелязване на възстановяване №:id като изплатено',
            'description' => 'Потвърждава, че :amount са върнати. Сумата не може да се променя тук.',
            'paid_out_at' => 'Дата на изплащане',
            'reference' => 'Банкова референция',
            'note' => 'Бележка',
            'done' => 'Възстановяване №:id е отбелязано като изплатено.',
        ],
        'cancel' => [
            'label' => 'Отмени възстановяването',
            'heading' => 'Отмяна на възстановяване №:id',
            'warning' => 'Отменя се само записът за парите. Стоките остават върнати и заприходени и за тях не се връща нищо.',
            'reason' => 'Причина',
            'done' => 'Възстановяване №:id е отменено.',
        ],
        'invariant_broken' => 'Числата на тази поръчка не се връзват, затова възстановяването не е записано. Нищо не е променено. Обърнете се към поддръжката.',
    ],

    // Refunds R3 част 2: паричната част на диалога за отказ / връщане, панелът с факти, действието „само сума“.
    'history' => [
        'announced_on' => 'Клиентът обяви връщане за: :day',
    ],

    'refund_dialog' => [
        'section' => 'Връщане на суми',
        'goods_label' => 'Сума за стоките',
        'goods_hint' => 'Попълнена с изчисления дял; можете да я промените. Остава за този ред: :room.',
        'goods_hint_free' => 'Попълнена с изчисления дял; можете да я промените.',
        'goods_readonly' => 'За тази поръчка не е платено нищо, затова не се връщат пари: това е изчисленият дял и не може да се променя.',
        'shipping_label' => 'Връщане на доставка',
        'shipping_hint' => 'Остава: :room.',
        'shipping_reduction_label' => 'Намаление на доставката',
        'shipping_reduction_hint' => 'Намалява това, което клиентът още дължи за доставката. Остава: :room. Няма ефект, ако се връщат всички бройки.',
        'shipping_not_included' => 'Доставката (:amount) не е включена.',
        'deduction_label' => 'Удръжка',
        'deduction_hint' => 'Пари, които магазинът задържа от това връщане. Изисква причина.',
        'deduction_reason_label' => 'Причина за удръжката',
        'channel_label' => 'Изплащане чрез',
        'channel_options' => [
            'cash' => 'В брой от касата',
            'bank' => 'Банка',
        ],
        'no_channel' => 'Тази поръчка е платена, а нямате нито правото „връщане в брой“, нито „връщане по банка“, затова не можете да го запишете. Обърнете се към администратор.',
        'total' => 'Общо за връщане: :total',
        'still_refundable' => 'Още може да се върне по тази поръчка: :room',
        'over_total' => 'Това е повече от още връщаемото (:room). Системата ще го откаже.',
        'pending_total' => 'Не е платено нищо, затова не се връщат пари; неплатената сума се намалява.',
        'invalid_amount' => 'Въведете сума, например 12.50 или 12,50.',
        'announced_label' => 'Дата, на която клиентът е обявил връщането',
        'announced_help' => 'Денят, в който клиентът е казал, че ще върне стоките; не е нужен час. По избор.',
        'facts_heading' => 'Факти за тази поръчка',
        'facts_delivered' => 'Доставена :date · дни от доставката: :days',
        'facts_not_delivered' => 'Не е отбелязана като доставена.',
        'facts_return' => 'Предишно връщане: записано :recorded, обявено :announced',
        'facts_announced_none' => 'няма въведен ден',
        'facts_return_days' => ' (дни след доставката: :recorded записано, :announced обявено)',
    ],

    'money_only' => [
        'label' => 'Връщане само на сума',
        'heading' => 'Връщане само на сума — поръчка :id',
        'description' => 'Връщане на пари, без да се връщат стоки: жест към клиента или корекция. Наличностите не се променят.',
        'shipping' => 'Връщане на доставка',
        'adjustment' => 'Корекция',
        'reason' => 'Причина',
        'hint' => 'Остава за доставката: :shipping. Още може да се върне по тази поръчка: :total.',
        'total' => 'Общо за връщане: :total',
        'done' => 'Връщане на :amount е записано (дължимо).',
    ],

    'refund_transition' => [
        'refund_not_found' => 'Това възстановяване не е намерено.',
        'not_owed' => 'Само възстановяване, което още се дължи, може да бъде изплатено или отменено. Това не е такова (може вече да е изплатено или отменено). Нищо не е променено.',
        'payout_in_future' => 'Датата на изплащане не може да е в бъдещето. Нищо не е променено.',
        'bank_reference_required' => 'Възстановяване по банков път изисква банкова референция. Нищо не е променено.',
        'reason_required' => 'Отмяната на възстановяване изисква причина. Нищо не е променено.',
        'actor_required' => 'Това изисква влязъл служител. Нищо не е променено.',
        'refund_lines_unlinked' => 'Това възстановяване не може да се отмени автоматично: редовете му не могат да се свържат с връщането, което го е създало. Нищо не е променено.',
        'storno_mismatch' => 'Това възстановяване не може да се отмени: записаните му редове не съвпадат с книгата. Нищо не е променено.',
    ],

    // Refunds R3 част 1 (shipping-domain-design.md §7.2.6, §7.2.11).
    'money_only_refund' => [
        'reason_required' => 'Възстановяване без връщане изисква причина. Нищо не е записано.',
        'payment_not_settled' => 'Тази поръчка няма получено плащане, т.е. нищо не е платено и нищо не може да се възстанови. Нищо не е записано.',
        'deduction_not_allowed' => 'Възстановяване без връщане не може да има удръжка: няма стока, от която да се удържа. Нищо не е записано.',
        'nothing_to_refund' => 'Въведете сума за доставка или корекция: възстановяването би било 0. Нищо не е записано.',
    ],

    // Refunds R4a-2 (shipping-domain-design.md §7.2.20): записване на банково постъпление.
    'payment_receipt' => [
        'unreconciled' => 'По тази поръчка е получен превод от :received, който не е сверен — приемете го или запишете остатъка първо. Нищо не е променено.',
        'reconcile_denied' => 'Приемането на превод с по-малка или по-голяма сума изисква право за сверяване на плащания, което нямате. Нищо не е променено.',
        'refused' => [
            'not_bank_transfer' => 'Постъпление се записва само към плащане с банков превод. Нищо не е записано.',
            'payment_settled' => 'Това плащане вече е уредено. Нищо не е записано.',
            'payment_voided' => 'Това плащане е анулирано и вече не се брои. Нищо не е записано.',
            'payment_unanswered' => 'Това плащане още няма отговор, затова не може да получи постъпление. Нищо не е записано.',
            'payment_not_confirmable' => 'Това плащане не може да получи постъпление в сегашното си състояние. Нищо не е записано.',
            'too_many_effective_receipts' => 'Едно плащане приема най-много :max постъпления. Нищо не е записано.',
            'too_many_receipt_rows' => 'Това плащане е стигнало лимита си от :max записа, включително корекциите. Нищо не е записано.',
            'amount_not_positive' => 'Въведете сума, по-голяма от 0. Нищо не е записано.',
            'amount_too_large' => 'Сумата може да има най-много :digits цифри преди десетичната запетая. Нищо не е записано.',
            'currency_mismatch' => 'Сумата трябва да е във валутата на плащането (:currency). Нищо не е записано.',
            'day_malformed' => 'Въведете деня, в който са постъпили парите, като истинска дата. Нищо не е записано.',
            'day_in_future' => 'Денят на постъпване на парите не може да е в бъдещето. Нищо не е записано.',
            'day_before_placement' => 'Денят на постъпване на парите не може да е преди поръчката да е направена. Нищо не е записано.',
            'reference_blank' => 'Въведете банковата референция на превода. Нищо не е записано.',
            'reference_too_long' => 'Банковата референция може да е най-много :max символа. Нищо не е записано.',
            'reference_invalid' => 'Банковата референция може да съдържа само видим текст на един ред. Нищо не е записано.',
            'no_effective_receipt' => 'За това плащане няма записан превод, затова няма какво да се приеме. Нищо не е променено.',
            'nothing_to_accept' => 'Записаните преводи са точно колкото очакваната сума, затова няма какво да се приеме. Нищо не е променено.',
            'accepted_amount_changed' => 'Получената сума се е променила, откакто отворихте прозореца — сега е :received. Проверете я и приемете отново. Нищо не е променено.',
            'receipt_unknown' => 'Този запис на превод не съществува. Нищо не е променено.',
            'receipt_not_effective' => 'Този запис на превод вече е коригиран; коригирайте по-новия. Нищо не е променено.',
            'nothing_to_correct' => 'Нищо не е променено в сумата, деня или референцията, затова няма какво да се коригира. Нищо не е записано.',
            'receipt_amount_change_after_settlement' => 'Сума не може да се коригира след уреждане на плащането; денят и референцията могат. Грешна сума се оправя с връщане на пари. Нищо не е променено.',
            'reason_blank' => 'Въведете причина. Нищо не е променено.',
            'reason_too_long' => 'Причината може да е най-много :max символа. Нищо не е променено.',
            'reason_invalid' => 'Причината може да съдържа само видим текст на един ред. Нищо не е променено.',
        ],
    ],

    // Refunds R4a-4 (shipping-domain-design.md §7.2.20 §6, §7): администрация на банковите постъпления.
    'receipt' => [
        'record' => [
            'heading' => 'Записване на получен превод за поръчка :id',
            'description' => 'Въведете какво е постъпило по банковата сметка. Ако преводите се равняват на очакваната сума, плащането се уреждва.',
            'amount' => 'Получена сума',
            'received_on' => 'Получена на',
            'bank_reference' => 'Банкова референция',
            'done' => 'Преводът е записан.',
            'hint' => 'Очаквано :expected · записано досега :recorded · този превод :this',
            'hint_matches' => '→ съвпада, плащането ще бъде уредено',
            'hint_short' => '→ не съвпада: по-малко с :difference; плащането остава неуредено и поръчката отива в „Изискват внимание“',
            'hint_over' => '→ не съвпада: повече с :difference; плащането остава неуредено и поръчката отива в „Изискват внимание“',
        ],
        'accept' => [
            'label' => 'Приемане на получената сума',
            'heading' => 'Приемане на получената сума за поръчка :id',
            'description' => 'Записаните преводи по тази поръчка не се равняват на очакваната сума.',
            'expected' => 'Очаквано: :amount',
            'received' => 'Получено: :amount',
            'short' => 'По-малко с :difference',
            'over' => 'Повече с :difference',
            'reason' => 'Причина',
            'warning' => 'Плащането ще бъде уредено за :amount; връщанията са ограничени до :amount.',
            'help' => 'Приемането на по-малък или по-голям превод е и начинът да откажете или върнете поръчка със несверена сума; можете да го посочите в причината, за да се чете правилно историята.',
            'done' => 'Получената сума е приета и плащането е уредено.',
            'changed' => 'Получената сума се промени, докато прозорецът беше отворен. Нищо не е променено; отворете го отново, за да видите сегашната сума.',
        ],
        'correct' => [
            'label' => 'Корекция на запис на превод',
            'heading' => 'Корекция на записан превод за поръчка :id',
            'description' => 'Старият запис остава в историята като заменен; корекцията го замества.',
            'receipt' => 'Превод за коригиране',
            'option' => ':day · :amount · :reference',
            'reason' => 'Причина за корекцията',
            'amount_locked' => 'Сума не може да се коригира след уреждане на плащането; денят и банковата референция могат. Грешна сума се оправя с връщане на пари.',
            'done' => 'Записът на превода е коригиран.',
            'not_found' => 'Този превод не принадлежи на тази поръчка. Нищо не е променено.',
        ],
        'section' => [
            'heading' => 'Получени банкови преводи',
            'expected' => 'Очаквано',
            'received' => 'Получено досега',
            'none_yet' => 'Още няма записан превод.',
            'short_by' => 'По-малко с :difference',
            'over_by' => 'Повече с :difference',
            'legacy' => 'Получената сума не е записана (преди да се пазят постъпленията).',
            'accepted' => 'Уредено за :settled от :expected, прието: :reason',
            'day' => 'Получен на',
            'amount' => 'Сума',
            'reference' => 'Банкова референция',
            'who' => 'Записал',
        ],
    ],

    'return_announced_date' => [
        'in_future' => 'Датата, на която клиентът е обявил връщането, не може да е в бъдещето. Нищо не е записано.',
        'before_placement' => 'Датата, на която клиентът е обявил връщането, не може да е преди направата на поръчката. Нищо не е записано.',
    ],

    'operation_key_reused' => 'Тази форма вече е изпратена, с различно съдържание. Нищо не е променено; отворете диалога отново и въведете каквото искате.',

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

        'cancel' => 'Откажи',
        'cancel_heading' => 'Отказване на поръчка :id',
        'cancel_description' => 'Отказване на поръчка :id.',
        'cancel_done' => 'Поръчка :id е отказана.',

        'record_return' => 'Регистрирай връщане',
        'record_return_heading' => 'Регистриране на връщане за поръчка :id',
        'record_return_description' => 'Записване кои бройки от поръчка :id са върнати.',
        'record_return_done' => 'Регистрирано е връщане на :count бройка(и) по поръчка :id.',

        'add_note' => 'Добави бележка',
        'add_note_heading' => 'Добавяне на бележка към поръчка :id',
        'add_note_description' => 'Записване на вътрешна бележка за поръчка :id. Видима за всеки, който може да преглежда тази поръчка.',
        'add_note_done' => 'Бележката е добавена.',
        'note_field_label' => 'Бележка',

        'quantity_label' => 'Количество',
        'restock_label' => 'Върни на склад',
        'reason_label' => 'Причина (незадължително)',
        'nothing_to_return' => 'Въведете поне една бройка, за да регистрирате връщане.',

        'note_label' => 'Бележка (незадължително)',
        'refused_title' => 'Действието беше отказано',
        'invalid_transition_body' => 'Поръчка :id не може да премине от :from към :to.',
        'return_exceeds_remaining_body' => 'Не може да се върнат :requested бройка(и) от ":line": остават само :remaining.',
        'generic_refusal_body' => 'Поръчка :id не можа да бъде обработена — може вече да не съществува, или състоянието ѝ се е променило след зареждането на страницата.',
        // Редактиране на поръчка (етап 4b-i, order-editing-design.md раздел 8)
        'edit' => 'Редактиране на поръчката',
        'edit_heading' => 'Редактиране на поръчка :id',
        'edit_description' => 'Намалете или махнете артикули, сменете доставката или промо кода. Сумата на поръчката и чакащото плащане се актуализират автоматично.',
        'edit_done' => 'Поръчка :id е обновена.',
        'edit_lines_hint' => 'Използвайте „Премахни“, за да махнете артикул от поръчката (може да се върне преди запис). Количеството може да се намалява тук, но не и да се увеличава.',
        // Добавяне на артикул (етап 4b-ii)
        'edit_add_heading' => 'Добавяне на продукт',
        'edit_add_hint' => 'Търсете по име, SKU или баркод и задайте колко бройки да се добавят. Цената идва от ценовата листа — тук не се въвежда ръчно.',
        'edit_add_product_placeholder' => 'Започнете да пишете…',
        'edit_add_quantity' => 'Количество за добавяне',
        'edit_add_merge_hint' => 'Тази поръчка вече продава тази вариация, затова бройките се добавят към този ред по неговата цена.',
        'edit_add_no_price' => 'За този продукт няма зададена цена.',
        'edit_add_unavailable' => 'Този продукт не може да се добави към поръчка.',
        'edit_add_unavailable_body' => 'Избраният продукт не може да се добави към тази поръчка: архивиран е, не е продаваем или вече не съществува. Нищо не е записано.',
        'edit_add_no_price_body' => 'За избрания продукт няма зададена цена във валутата на поръчката, затова не може да се добави. Нищо не е записано.',
        'edit_add_insufficient_stock_body' => 'Няма достатъчно наличност, за да се добави този артикул в исканото количество. Нищо не е записано — проверете наличностите и опитайте отново.',
        'edit_remove_line' => 'Премахни',
        'edit_restore_line' => 'Върни',
        'edit_remove_last_line' => 'Поръчката не може да остане без артикули. За да я отмените изцяло, използвайте действието „Откажи“.',
        'edit_current_quantity' => 'Сега',
        'edit_new_quantity' => 'Ново количество',
        'edit_discount' => 'Ръчна отстъпка',
        'edit_promotion_code' => 'Промо код',
        'edit_promotion_code_hint' => 'Оставете непроменен, за да запазите кода, или въведете друг код, за да го замените.',
        'edit_remove_promotion_code' => 'Премахни промо кода изцяло',
        'edit_nothing_to_change' => 'Нищо не е променено, затова няма какво да се запази.',
        'edit_stale_body' => 'Поръчка :id е променена от някой друг, след като тази форма е била отворена (ревизия :expected, сега :actual). Нищо не е записано - затворете и отворете „Редактиране на поръчката“ отново.',
        'edit_not_editable_status_body' => 'Поръчка :id вече не може да се редактира: тя е :status, а редактиране е възможно само докато поръчката е получена или потвърдена.',
        'edit_not_editable_payment_body' => 'Поръчка :id вече не може да се редактира: плащане по нея вече е получено. Използвайте отказ или връщане.',
        'edit_promotion_invalid_body' => 'Промо кодът „:code“ не може да се приложи към тази поръчка: :reason. Нищо не е записано - променете количествата или премахнете кода и опитайте отново.',
    ],

    // Кодовете за отказ на PromotionValidator, формулирани за диалога за редактиране.
    'promotion_refusal_reasons' => [
        'not_found' => 'такъв код не съществува',
        'inactive' => 'кодът не е активен',
        'not_yet_active' => 'кодът все още не е валиден',
        'expired' => 'кодът е изтекъл',
        'minimum_spend_not_met' => 'поръчката е под минималната сума на кода',
        'maximum_spend_exceeded' => 'поръчката е над максималната сума на кода',
        'new_customers_only' => 'кодът е само за нови клиенти',
        'account_scope_mismatch' => 'кодът не е достъпен за този клиент',
        'usage_limit_reached' => 'кодът е достигнал лимита си на използване',
        'usage_limit_per_customer_reached' => 'този клиент е използвал кода максималния брой пъти',
        'no_matching_lines' => 'никой от артикулите в поръчката не отговаря на кода',
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
    'payment_history' => [
        'heading' => 'История на плащанията',
        'amount' => 'Сума',
        'why' => 'Защо не е текущо',
        'failed' => 'Неуспешен опит',
        'superseded' => 'Заменено',
    ],
    'not_available' => '—',
    'guest' => 'Гост',
    'promotion_redeemed_yes' => 'използван',
    'promotion_redeemed_no' => 'неизползван',
    'legacy_line_note' => 'Записано преди пълния запис',
    // order_events.staff_id/staff_name NULL — записът е направен от конзолата
    // или фонова задача (виж en/orders.php's own comment).
    'system_actor' => 'Системата',

    // The names each LINE puts in front of its own values on the View page's
    // Lines table (admin-panel-design.md §14): a table wide enough to scroll
    // sideways scrolls its header row out of sight, so every line names its own
    // numbers, in the words the merchant reads them off in.
    'line_labels' => [
        'sku' => 'Арт. №',
        'returned_or_removed' => 'върнати или премахнати: :count',
        'quantity' => 'Бройка',
        'unit_price' => 'Цена',
        'discount' => 'Отстъпка',
        'amount' => 'Сума',
    ],

];
