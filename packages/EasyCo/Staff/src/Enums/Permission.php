<?php

namespace EasyCo\Staff\Enums;

/**
 * The fixed permission vocabulary — see staff-access-domain-design.md §3.
 * Permissions are a PHP enum, not database rows: they are the vocabulary
 * the code itself checks against, so a permission that no code enforces
 * would be a lie, and a permission the code enforces but the enum lacks
 * would not compile. Roles (see Role.php) are data; permissions are code.
 *
 * No methods on this enum — pure vocabulary.
 */
enum Permission: string
{
    // Catalog
    /** see products and variations at all */
    case PRODUCT_VIEW = 'product_view';
    /** create and edit products, variations, media */
    case PRODUCT_MANAGE = 'product_manage';
    /** permanently delete a STANDARD variation, or an ARCHIVED product, and free its identifiers */
    case PRODUCT_DELETE = 'product_delete';
    /** brands, categories, tags, seasons, attribute definitions and values */
    case TAXONOMY_MANAGE = 'taxonomy_manage';

    // Cost and pricing
    /** see a variation's cost price, anywhere it appears */
    case COST_VIEW = 'cost_view';
    /** set or change it */
    case COST_MANAGE = 'cost_manage';
    /** price lists and price list items */
    case PRICE_MANAGE = 'price_manage';

    // Orders
    case ORDER_VIEW = 'order_view';
    /** status transitions, fulfilment, editing order information */
    case ORDER_MANAGE = 'order_manage';
    /** return money from the register, in cash */
    case REFUND_CASH = 'refund_cash';
    /** a refund that goes through a bank: card reversals, transfers */
    case REFUND_BANK = 'refund_bank';

    // Point of sale
    /** take sales at the register */
    case POS_OPERATE = 'pos_operate';
    /** apply a discretionary discount at the register */
    case POS_DISCOUNT = 'pos_discount';

    // Marketing
    /** promotion codes and their scopes */
    case PROMOTION_MANAGE = 'promotion_manage';

    // Reporting
    /** any report, including anything showing revenue or margin */
    case REPORT_VIEW = 'report_view';

    // System
    /** site settings, including payment configuration */
    case SETTINGS_MANAGE = 'settings_manage';
    /** create, edit, deactivate staff and assign their roles */
    case STAFF_MANAGE = 'staff_manage';
    /** configuration of the AI-facing functionality */
    case AI_MANAGE = 'ai_manage';
}
