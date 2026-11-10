## Price lists {#action-price-list-overview}

A price list is a **percent-off campaign**, for example «Spring-26 collection −30%». You find them under "Marketing" → "Price lists" (the "Manage prices" permission is needed).

A campaign has a name, a percentage, an optional start and end (in the shop time zone, to the minute) and a scope. With no dates it applies as soon as it is saved and active. A switched-off, not-yet-started or expired campaign changes no price.

A price list is **never deleted**: you switch it off with the "Active" switch or from the "Actions" menu. The two system lists ("Regular Prices" and "Manual Sale") are your regular and manual sale prices from the product form: they show in the list as "System" and are not changed from here.

Two campaigns with exactly the same scope cannot exist at the same time. If two different campaigns affect the same product, **only one** applies (the newest): discounts are not added up.

## The scope of a campaign {#action-price-list-scope}

The scope picks the products by brand, category, tag, **season**, product or attribute value. It works like the shop filters:

- **AND between different kinds:** «Brand X AND season Autumn-26» are the products that are both.
- **OR inside one kind:** «tags A, B AND brand X» is (A or B) and X; two brands mean one or the other.

**An empty scope means ALL products.** The form warns you (the yellow message) and asks you to confirm explicitly before saving. On the edit page you see "Affects N products".

## Replaces the manual sale price {#action-price-list-manual-sale}

Every product can have a manual sale price (from the product form).

- **Off (the default):** a product with a manual sale price keeps it, the campaign does not touch it. A product with no manual sale price gets the campaign price.
- **On:** when a product has both a manual sale price and a campaign price, the customer gets the **LOWER** of the two. A campaign can never raise a price.

Example: regular 100 €, manual sale 85 €. Campaign −30% (on) → 70 €; campaign −10% (on) → 85 €; campaign −30% (off) → 85 €.

The sort by price in the shop follows the same price that the card and the cart show; the order is refreshed within seconds after a save, and within a minute when a campaign starts or ends.
