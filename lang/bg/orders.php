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
        'payment_voided' => 'Чакащо плащане анулирано',
        'note_added' => 'Вътрешна бележка',
        // Draft — flagged for the owner's review (§0's own posture for new copy).
        'edited' => 'Поръчката е редактирана',
        'tracking_recorded' => 'Записан е номер за проследяване',
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
