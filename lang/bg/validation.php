<?php

/*
 * Собствените съобщения за валидация на EasyCo.
 *
 * ЗАЩО ТОЗИ ФАЙЛ МОЖЕ ДА СЪДЪРЖА САМО ДВА КЛЮЧА: зареждащият механизъм ползва
 * И папката на фреймуърка, И папката lang на проекта, и слепва съдържанието им
 * ключ по ключ (TranslationServiceProvider::registerLoader() и
 * FileLoader::loadPaths()). Добавен тук ключ се ДОБАВЯ към набора на
 * фреймуърка, а не го заменя: всички останали съобщения за валидация (в това
 * число и `validation.required`) продължават да работят както досега.
 *
 * Ползват се от App\Rules\PlainText (plain_text) и от правилата за суми в
 * PromotionController (money_format).
 */
return [
    'plain_text' => 'Полето :attribute не може да съдържа контролни символи или символи за двупосочно форматиране.',
    'plain_multiline_text' => 'Полето :attribute не може да съдържа контролни символи (освен нови редове) или символи за двупосочно форматиране.',
    // Checkout RN-1: the merchant made the region required for the delivery country (Settings > Checkout).
    'region_required' => 'Областта е задължителна за тази държава.',
    // UX-0: the standard messages in Bulgarian. Without them an admin form said "The категория field is required." (English around a Bulgarian field name).
    'accepted' => 'Полето :attribute трябва да бъде прието.',
    'array' => 'Полето :attribute трябва да бъде списък.',
    'between' => [
        'array' => 'Полето :attribute трябва да има между :min и :max елемента.',
        'numeric' => 'Полето :attribute трябва да е между :min и :max.',
        'string' => 'Полето :attribute трябва да е между :min и :max знака.',
    ],
    'boolean' => 'Полето :attribute трябва да е „да“ или „не“.',
    'date' => 'Полето :attribute не е валидна дата.',
    'distinct' => 'Полето :attribute има повтаряща се стойност.',
    'email' => 'Полето :attribute трябва да е валиден имейл адрес.',
    'filled' => 'Полето :attribute не може да е празно.',
    'image' => 'Полето :attribute трябва да е снимка.',
    'in' => 'Избраната стойност за :attribute не е валидна.',
    'integer' => 'Полето :attribute трябва да е цяло число.',
    'max' => [
        'array' => 'Полето :attribute не може да има повече от :max елемента.',
        'numeric' => 'Полето :attribute не може да е по-голямо от :max.',
        'string' => 'Полето :attribute не може да е по-дълго от :max знака.',
    ],
    'min' => [
        'array' => 'Полето :attribute трябва да има поне :min елемента.',
        'numeric' => 'Полето :attribute трябва да е поне :min.',
        'string' => 'Полето :attribute трябва да е поне :min знака.',
    ],
    'numeric' => 'Полето :attribute трябва да е число.',
    'regex' => 'Форматът на полето :attribute не е валиден.',
    'required' => 'Полето :attribute е задължително.',
    'required_if' => 'Полето :attribute е задължително.',
    'required_with' => 'Полето :attribute е задължително.',
    'string' => 'Полето :attribute трябва да е текст.',
    'unique' => 'Стойността на :attribute вече се използва.',
    'url' => 'Полето :attribute трябва да е валиден адрес.',
    'money_format' => 'Полето :attribute трябва да е обикновена сума като 10.00 — цифри с незадължителна десетична точка, без знак, без интервали и с не повече десетични знаци, отколкото използва валутата.',
];
