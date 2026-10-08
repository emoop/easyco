## Shipping overview {#action-shipping-overview}

The "Shipping" page is the entry point to everything that decides **what delivery costs and which methods the customer sees**. From it you reach the editing of zones, methods and classes, and at the bottom there is the "Try it" tool.

Shipping is made of three things that work together:

- **Zone**: where you deliver (countries and, optionally, specific settlements or postcodes).
- **Method**: how and at what price you deliver in that zone (for example "Courier to address").
- **Class**: a label on a product (for example "Bulky") that can change a method's price.

If some products have no shipping class, the page shows a short warning with their number and how to fix it (see "Giving existing products a class").

## Creating a zone {#action-zone-editor}

Press "New zone" and fill in:

- **Name**: for you only, the customer does not see it.
- **Countries**: one or more countries the zone covers.
- **Settlements (optional)**: if the zone should apply only to certain places.
- **Postcodes (optional)**: if you want to narrow it by postcode.

Leave both optional fields empty and the zone covers the whole country. You then add methods to the zone.

## The order of zones {#action-zone-order}

Zones are checked **in order, from the top, and the first one that matches** the customer's address wins. The others are not considered.

So a narrow zone (for example "Sofia") must sit **above** a broader one ("Bulgaria"). If it is below, the broad zone matches first and the narrow one is never reached.

"Move up" and "Move down" shift a zone by one place. If no zone matches the address, the customer is offered no shipping method.

## How settlements and postcodes are matched {#action-zone-settlement-matching}

A zone with only a country matches every address in that country. Once you add **settlements** or **postcodes**, the zone matches only addresses that fit them.

- An empty field means "no restriction on this".
- This lets you make, for example, a "Big cities" zone with cheaper shipping **above** a "Whole country" zone.

After a change, check the result with "Try it": the surest way to see which zone wins for a given address.

## Shipping methods {#action-method-editor}

A method is one concrete way of delivering in one zone. Press "New method" and set:

- **Zone**: the zone it belongs to.
- **Name**: what the customer sees.
- **Kind**: how the price is worked out (see the next topic).
- **Active**: an inactive method is not offered but stays saved.
- **Price**: for the kinds that have a price.
- **Free shipping above (optional)**: see "Free shipping above an amount".
- **Courier (optional)** and **Delivery type (optional)**: for grouping at checkout.

A zone can have several methods; the customer chooses among the active ones.

## The kinds of shipping method {#action-method-kinds}

| Kind | How the price works |
|---|---|
| Flat price | The same amount for every order. |
| Free | Always 0. |
| Price by shipping class | A base price that the classes of the products in the cart can replace or adjust. |
| Carrier | A courier method; tied to a **Carrier code** and may need a pickup point. |

If the method is for collection from an office or locker, tick "Needs a pickup point (office or locker)": the customer then has to choose a point.

## How classes act on the price {#action-method-class-mode}

Every product has one shipping class. When a method is "Price by shipping class", the classes of the products in the cart influence its price. **How much**, you set in each method, in "Class amounts". The amounts belong to **the method**, not to the class, so the same class can cost differently with different couriers.

How the amounts are applied depends on the class mode: "Replace the price" or "Adjust the price". A class with no amount in the method is not an error:

- in "Replace the price" it is charged the method's base price;
- in "Adjust the price" it adds nothing.

## Replace mode {#action-method-replace}

In "Replace the price" mode, the class amount **replaces** the base price.

- If the cart has products of several classes, the **highest** amount among them applies: they are not added up.
- A product whose class has no amount is charged the method's base price.

Example: base price €5.00; "Bulky" €9.00. A cart with one ordinary and one bulky product costs €9.00. A cart with only ordinary products costs €5.00.

It suits cases where each class has a clear price of its own. Amounts cannot be negative.

## Adjust mode {#action-method-adjust}

In "Adjust the price" mode the base price stays and each class adds to it or takes from it. An amount can be **positive** (surcharge) or **negative** (discount).

- Each class is counted **only once**, however many products of that class are in the cart.
- A class with no amount adds nothing.
- The price **never goes below 0**.

Example: base price €5.00; "Bulky" +€4.00; "Light" −€1.00. A cart with two bulky products and one light one costs 5 + 4 − 1 = €8.00.

## Class mode: replace or adjust {#action-class-mode}

You choose it in the method. In short:

- **Replace the price**: for each class you say "for this class the price is so much". The highest wins.
- **Adjust the price**: for each class you say "add or take off so much". The sum over the different classes is added to the base price.

When you switch from "Replace" to "Adjust", the amounts you entered mean something else (a price versus a surcharge), so the system asks "Switch to adjustments?" and offers "Switch to adjustments" and "Back to the form". Review the amounts after switching.

## Free shipping above an amount {#action-method-free-above}

The "Free shipping above (optional)" field makes the method free once the goods reach the threshold.

- It applies when the goods total is **equal to or above** the threshold.
- The total is the goods **after discounts**, without shipping itself.
- Free shipping **wins over classes**: once the threshold is reached, classes add nothing.
- An empty field means the method has no such threshold.

In the cart the customer sees a hint of how much more they need for free shipping, and once reached, that shipping is free.

## Shipping classes {#action-class-editor}

A class is a label you put on products with similar shipping needs (bulky, fragile, heavy). Press "New class" and set:

- **Name**: how you see it in lists.
- **Code**: a short permanent identifier. **It cannot be changed after creation**, because products and methods refer to it.
- **Description (optional)**: a note for you.
- **Use as default class**: the class pre-selected for new products.

A class holds no amounts. Amounts are entered in each method.

## Why a class cannot be deleted {#action-class-delete-blocked}

A class cannot be deleted while it is in use, otherwise the shipping price of those items would become undefined.

- While a method has an amount for the class, or a product or variation has the class, deletion is refused and the message says how many methods and products use it. First remove its amounts from the methods and clear it from the products, then delete it.
- The store's **default** class cannot be deleted either. First make another class the default.
- The code of a class never changes. If you dislike the name, change the **name**, not the code.

## The shipping class in a product {#action-product-class-field}

In the product editor (each variation) there is a "Shipping class" field with the prompt "Choose a class". The class is kept per variation, so variations of one product can have different classes (for example a large and a small pack).

The field is required in the admin forms.

## Try it: what the customer will see {#action-try-it}

The "Try it" tool at the bottom of the "Shipping" page shows what a customer would get for a given address and cart.

1. Enter "Country" and, optionally, "Settlement" and "Postcode". The "Pickup point (office or locker)" box shows pickup-point methods.
2. Enter "Goods total after discount" and the cart lines: for each a "Shipping class" and "Quantity" ("Add a line" / "Remove").
3. Press "Show the result".

You see which zone wins and which methods are offered, at what price. Nothing is saved and no order is created. Use it after every change to zones and methods.

## Copying a method to other zones {#action-method-copy}

If the same method is needed in several zones, do not write it again. In the methods list choose "Copy to zones", tick the zones and confirm with "Copy the method".

The copy is independent and is added at the end of the method order in the zone. It takes all the settings (kind, price, class amounts, free-shipping threshold, courier). Then you change only what differs, for example the price for a more remote zone.

A method cannot be moved to another zone: copy it there and delete the old one.

## Shipping classes list {#action-classes}

The "Shipping classes" menu lists all classes: name, code, a "default" marker and how many methods and products use each ("Not used" if none). From here you open the editor or create a "New class".

Only one class can be the default. If the store has no class yet, the "Create the default class" button creates the class "Standard" (code `standard`), which you can rename.

## Assigning a class to a product {#action-class-assignment}

The class is set in the product's "Shipping class" field (prompt "Choose a class"). For a product with variations, the class is set on each variation separately.

The default class is pre-selected for new products. Products created before classes existed get it through the command in "Giving existing products a class": do not edit them one by one.

## Grouping methods by courier {#action-method-grouping}

The "Courier (optional)" and "Delivery type (optional)" fields help show the methods in order.

- **Courier**: free text (for example "Econt"). Methods with the same courier are grouped together, regardless of letter case and spaces around the name.
- **Delivery type**: "To address", "To office", "To locker" or "Other".

This lets the customer first choose a courier, then address, office or locker. Each method keeps its own price; the group shows the lowest ("from").

## Why the shipping class is required {#action-class-required}

Without a class the system does not know which class amount to add for the item. That is why the admin forms do not allow saving without one.

The default class makes this painless: when nothing is special, pick it. If everything you sell is "ordinary", the default class and a flat method price are enough: the price stays whatever you set.

## Giving existing products a class {#action-class-migration}

Products created before classes existed have none. The "Shipping" page shows how many. One command fixes them:

1. First a dry run (nothing changes):  
   `php artisan shipping-classes:assign-missing --create-default`
2. If the result is right, apply it:  
   `php artisan shipping-classes:assign-missing --create-default --force`

Options: `--class=CODE` assigns a specific class; `--create-default` creates the default class if there is none; `--limit` and `--chunk` process a large catalog in parts.

If you name no class and the store has no default class, the command stops and says so: create a default class or add `--create-default`.
