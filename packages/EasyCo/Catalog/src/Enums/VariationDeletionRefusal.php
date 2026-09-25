<?php

namespace EasyCo\Catalog\Enums;

/**
 * WHY a STANDARD variation cannot be hard-deleted — catalog-domain-design.md
 * §3.19.8 D / G-D2. Two reasons, and only two.
 *
 * A REASON CODE, DELIBERATELY NOT A MESSAGE. The merchant-facing sentence is
 * rendered by the application layer in the current locale
 * (App\Services\VariationDeletionRefusalMessage), while
 * VariationNotDeletableException keeps its own English sentence for logs,
 * exception dumps and support. A domain package must not know about
 * app-layer translation keys: the domain states the fact, the UI says it in
 * the merchant's language.
 *
 * The VALUES are the key fragments the application uses
 * (`products.deletion.refusal.{value}`), so the mapping cannot drift — and
 * the enum case list IS the list of renderable refusals.
 */
enum VariationDeletionRefusal: string
{
    /** At least one `operational_sales_sale_lines` row references it; history is never deleted. */
    case HAS_HISTORY = 'has_history';

    /** Its stock on hand is not exactly zero. */
    case HAS_STOCK = 'has_stock';
}
