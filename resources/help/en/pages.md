## Pages {#action-pages-overview}

"Pages" are the static text of your shop: terms and conditions, privacy policy, delivery, returns, contacts. They are under "Content" → "Pages" and are reachable at `/info/…`. **Administrators and managers** can work with pages (the "Manage content pages" permission).

A page is a **draft** until you publish it: customers see it only when it is **published**.

The platform **ships no ready-made legal texts**: the terms and the privacy policy depend on the country and the shop, so a lawyer of yours prepares them (see "Legal texts" below).

## Page address (slug) {#action-pages-slug}

The page address is `/info/` plus the **slug** you enter in the "Address (slug)" field: for example `/info/terms`. The slug is suggested from the title and you can change it.

- Allowed: lower-case letters (Cyrillic too), digits and hyphens. No spaces, no hyphen at the start or end.
- The slug is **unique**: two pages cannot share one.
- **If you change the slug of a published page**, old links (in emails, with customers, in Google) stop working. The system warns you. There are no redirects yet.

## The editor {#action-pages-editor}

The editor is **limited on purpose**: headings (three levels), bold and italic, lists, links, a quote and a table. Images, video, colours and embedded code are not possible.

The text is **cleaned on save and on display**: anything outside the allowed list (scripts, `style`, unsafe links) is removed. Text pasted from elsewhere cannot harm your site. Links with `https:`, `mailto:` and internal links are allowed. External links open with `nofollow`.

The maximum size is about 200,000 characters. A longer text is refused with a message, not silently cut.

## Publishing {#action-pages-publish}

The status ("Status") is one of two:

- **Draft**: customers see the usual "not found" page.
- **Published**: available to everyone at `/info/…`.

The search engine fields ("SEO title", "SEO description", "Do not index (noindex)") are optional. When empty, the title and the start of the text are used. "Do not index (noindex)" tells Google not to list the page.

## Preview before publishing {#action-pages-preview}

The **"Preview"** button opens the page as customers will see it, even while it is a draft. The address is temporary and signed: it works for a limited time, is not indexed and should not be shared publicly.

## Revisions (history) {#action-pages-revisions}

Every change to the **title or text** is stored as a **revision** (1, 2, 3 …). Changing only the search fields or the footer does not create one.

- Revisions are **read only**: never edited or deleted. They are evidence of what was published.
- There is no "Restore" button. To get an old version back, open the revision, copy the text and paste it into the page.
- The revision number is part of the terms version on an order (`p3r7`): so you can always see what the customer accepted (see "Admin" → "Checkout").

## Footer {#action-pages-footer}

If you tick **"Show in the footer"**, the published page appears as a link at the bottom of every shop page. The "Footer order" number sets the sequence (lower first; ties by title). A draft is not shown in the footer even if ticked.

A freer menu (main menu, several footer menus) is planned.

## Archive instead of delete {#action-pages-archive}

A page is **not deleted**: its revisions are evidence. You **archive** it instead ("Archive"): it goes back to draft and leaves the footer.

A page selected as the **terms** or **privacy policy** in the checkout settings, or one that already-placed orders point to, cannot be removed: first pick another page or address in the settings.

## Legal texts {#action-pages-legal}

The platform creates two empty drafts, "Terms and conditions" and "Privacy policy", so you can see where they go. They are **empty and unpublished**. Fill them with text prepared by a lawyer, publish them, then choose them in the "Admin" → "Checkout" settings. Until the terms are published and chosen, the terms checkbox does not appear at checkout.

This help and the platform give no legal advice.
