<?php

/*
 * EasyCo's own validation messages.
 *
 * WHY THIS FILE MAY HOLD ONLY TWO KEYS: the translation loader is built with
 * BOTH paths — the framework's own lang directory first, this project's lang
 * directory second — and merges them key by key (Illuminate\Translation\
 * TranslationServiceProvider's registerLoader() and FileLoader::loadPaths()).
 * A key added here is therefore ADDED TO the framework's set, never a
 * replacement for it: `validation.required` and every other framework message
 * keep working exactly as before.
 *
 * Used by App\Rules\PlainText (plain_text) and PromotionController's amount
 * rules (money_format).
 */
return [
    'plain_text' => 'The :attribute may not contain control characters or bidirectional formatting characters.',
    'plain_multiline_text' => 'The :attribute may not contain control characters (other than line breaks) or bidirectional formatting characters.',
    // Checkout RN-1: the merchant made the region required for the delivery country (Settings > Checkout).
    'region_required' => 'The region is required for this country.',
    'money_format' => 'The :attribute must be a plain amount like 10.00 — digits with an optional decimal point, no sign, no spaces, and no more decimals than the currency uses.',
];
