## Checkout settings {#action-checkout-overview}

The "Checkout" page (the "Admin" menu) decides **what the shop asks for at checkout** and **what it keeps for the order**. It has four parts: region, order fields, invoice and privacy. Changes apply at once to new orders. Orders already placed are not changed.

Nothing here can stop a customer from ordering unless you set it up that way (for example a required region for a country whose customers do not know their region). If in doubt, keep the defaults.

## Region {#action-checkout-region}

Some countries have regions, others do not. The "Region" field at checkout follows a rule you choose:

- **Shown, optional**: the field is shown, the customer may leave it empty.
- **Shown, required**: the customer cannot continue without a region.
- **Hidden**: the field is not shown at all.

You set a **default for all countries** ("Default for all countries") and **exceptions for single countries** ("Exceptions by country"). For example, "Shown, optional" by default and "Hidden" for Bulgaria, because Bulgarian addresses need no region. An exception beats the default for that country. The country used is the delivery country the customer picked.

## Order fields {#action-checkout-fields}

Here you switch the extra choices at checkout on or off. None of them is pre-ticked: the customer always decides.

- **"Call me before shipping"**: when on, the customer can ask for a call before the parcel leaves. The choice shows on the order. When off, the question is not asked at all.
- **"Subscribe me to the newsletter"**: when on, the customer can agree to receive a newsletter. **Proof of consent** is stored: the exact sentence the customer saw and when it was ticked. If the customer was never asked, the order says "not asked", not "no". The two cases are different on purpose.
- **Terms and conditions**: see the next section.

## Terms and privacy {#action-checkout-terms}

The "I have read and agree to the website's terms and conditions" checkbox is shown **only when there is a safe link to the terms**. No link = no checkbox (otherwise the customer would agree to unseen terms). It is never pre-ticked.

For the terms and for the privacy policy you choose a **source**:

- **Page**: pick one of your published pages (see "Pages"). This is the recommended way.
- **External address**: a full address starting with `https://`. Other kinds of address are rejected.

If a page is chosen and you later unpublish or archive it, the link disappears and the terms checkbox is not shown. The settings page warns you.

When the order is placed, **when** the customer accepted the terms and **which version** are stored with it.

## Terms version {#action-checkout-terms-version}

The version is what lets you prove later "what did the customer accept".

- **With a page**, the version is set automatically and looks like `p3r7` (page 3, revision 7). It changes exactly when the page text changes. The "The terms were updated" button is not needed and is not shown. On the order, the version link opens exactly that revision of the page.
- **With an external address**, the version is the date you last marked a change. When you change the external text, press **"The terms were updated"**. A customer who loaded the checkout with the old version is then asked to confirm again, instead of the order being stored with the wrong version.

If a customer orders with an old version, the shop refuses the order with a short message and the customer submits again, now seeing the new terms.

## Invoice {#action-checkout-invoice}

When on ("Offer an invoice at checkout"), the checkout offers an "I want an invoice" checkbox. Until it is ticked, the invoice fields are **not visible**. After ticking, the customer fills in the company details.

What is required depends on the country, so there are **rules per country**. For each country you set:

- **Name of the company tax number** (e.g. "VAT ID"): what the field is called for the customer. "A company must give its tax number" decides whether it is required, and **"Format of the tax number (regular expression)"**, optionally, how it is checked. If unsure, leave the pattern empty: then only length and allowed characters are checked. A wrong pattern can refuse every invoice for the country, so try it with a test order.
- **Name of the registration / VAT number**: what the field is called; "A company must give its registration number" makes it required (not every country asks for it).
- **Name of the representative**: what the representative field is called.

A country without a rule uses the general rule. The platform **does not issue invoices**: it only collects the data the customer wants on the invoice. Ask your accountant about the requirements for the invoice itself. Invoice data shows on the order only to people allowed to manage orders.

## Privacy: keeping the IP address {#action-checkout-privacy}

Every order stores the customer's IP address, for two uses: **protection from fake orders** (see "Protection against fake cash-on-delivery orders" in the "Orders and payments" help) and evidence in a dispute.

- **"Keep the full customer IP address for (days)"**: after that many days the address is **anonymised** (its last part is erased) and the full one cannot be recovered. The default is **30 days**.
- **0** means "do not keep the full IP at all": only the anonymised form. Blocking by IP for new orders is then not possible, while blocking by phone and email keeps working.
- The full IP is visible only to people allowed to manage orders.
- An IP you have **blocked** is kept for as long as the block lasts, regardless of this setting.

What period is allowed for you is a question for your lawyer: the platform gives no legal advice.

## Daily jobs on the server {#action-checkout-cron}

Anonymising IPs and removing expired blocks run from the server's scheduled jobs. If the server does not run the scheduler (`php artisan schedule:run` every minute), **none of this happens**: IPs stay full longer than you set. Ask whoever maintains the server to confirm the scheduler is on.
