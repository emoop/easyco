# Pages (P1) — static content pages: terms, privacy, delivery, returns, contacts

**Status:** design only, accepted by the owner 2026-10-14 (§10). One build stage, P1 (§9). **Date:** 2026-10-14.
**Why now:** checkout form v2 (C1d-B) asks for a terms URL and a privacy URL, and the platform has nowhere to put those texts.
**Builds on:** `storefront-design.md` (routes, `SeoMeta`, `SecurityHeaders`, the cookie-free storefront group, S4 caching),
`app/Storefront/Support/DescriptionSanitizer.php` (the storefront's one raw-HTML filter), `checkout-form-v2-design.md` §6.3–6.4,
`order-context-design.md` §9.1 (the terms proof), `staff-access-domain-design.md` (permissions), `site-settings-design.md`.

---

## 0. Decisions (recommended defaults; §10 lists what the owner confirms)

| # | Decision |
|---|---|
| P-D1 | A page lives under the prefix **`/info/{slug}`** (e.g. `/info/politika-za-lichni-danni`) — owner decision. The prefix keeps pages from ever colliding with other routes. When the current shop migrates from WooCommerce, its old root-level page URLs get **301 redirects** to `/info/…` (a migration task, named here so it is not forgotten). |
| P-D2 | **Every saved change of a page's text is kept as a revision** (append-only). The terms version recorded on an order points at the exact revision the customer accepted, so "what did she agree to?" always has an answer. |
| P-D3 | The checkout settings **pick a page** for the terms and for the privacy policy (a select), with an external URL as the alternative. When the chosen terms page's text changes, the terms version changes **by itself**. |
| P-D4 | A new staff permission **`content_manage`**, given to the **Administrator and Manager** roles (existing installs included). |
| P-D5 | Pages can be placed in the **footer** (a flag + an order) now. Later: a **menu builder** where managers/admins create menus (footer, header) — a separate task, the flag then becomes the default footer menu's content. |
| P-D6 | HTML from the editor is cleaned by the **existing** `DescriptionSanitizer` allow-list, on save AND on render. No new sanitizer, no second set of rules. |

---

## 1. Scope and non-goals

**In:** pages table + revisions; admin resource (list, create, edit, publish, revisions view, preview); storefront page route; footer
links; checkout settings picking a page; terms version from the page revision; SEO fields.

**Not in (named so nobody assumes them):** a menu builder; page templates/blocks/page builder; images inside pages (the media
domain's picker comes later — the sanitizer drops `img` today); multi-language pages (the store has one language); a blog;
a sitemap (S-stage); ready-made legal texts (the platform must not ship legal advice — §8).

---

## 2. Schema

```php
Schema::create('pages', function (Blueprint $table) {
    $table->id();
    $table->string('slug', 120)->unique('pages_slug_unique');
    $table->string('title', 200);
    $table->mediumText('body');                       // SANITIZED HTML (never raw editor output)
    $table->string('status', 16);                     // draft | published
    $table->unsignedInteger('revision');              // current revision number, starts at 1
    $table->string('meta_title', 200)->nullable();
    $table->string('meta_description', 320)->nullable();
    $table->boolean('noindex')->default(false);
    $table->boolean('in_footer')->default(false);
    $table->unsignedSmallInteger('footer_order')->default(0);
    $table->timestamp('published_at')->nullable();    // first publication
    $table->timestamps();
});

Schema::create('page_revisions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('page_id')->constrained('pages')->restrictOnDelete();
    $table->unsignedInteger('revision');
    $table->string('title', 200);
    $table->mediumText('body');                       // sanitized, exactly what was published/saved
    $table->unsignedBigInteger('staff_id')->nullable(); // who saved it (no FK: staff can be removed, the history stays)
    $table->timestamp('created_at');
    $table->unique(['page_id', 'revision'], 'prev_page_revision_unique');
});
```

- CHECK (MySQL/MariaDB): `pages_status_check` (`status IN ('draft','published')`), `pages_slug_check` (lower-case letters of any
  script, digits and single hyphens; no leading/trailing hyphen — the same rule as §3.2), `pages_revision_check` (`revision >= 1`).
- **A revision is written only when the TITLE or the BODY changes** (after sanitizing; a save that changes nothing in them, or only
  SEO/footer fields, writes none and keeps the number). Revisions are never updated or deleted.
- **Deleting a page:** refused while it is the selected terms or privacy page, or while any order's `terms_version` points at it
  (§5.2) — the restrict FK on revisions plus an explicit check with a clear message. Otherwise a page can be **archived**
  (status draft + out of the footer); hard delete is not offered in P1 (the revisions are evidence).

---

## 3. Admin (Filament resource «Страници», group Content/Съдържание)

### 3.1 Form
Title · Slug (auto-suggested from the title, editable) · Body (Filament RichEditor, toolbar limited to what the allow-list keeps:
H2/H3/H4, bold, italic, bullet and numbered lists, link, blockquote, table) · Status (draft/published) · SEO: meta title,
meta description, noindex · Footer: show in footer, order.

### 3.2 Slug rules
- Lower-case; letters of any script (Cyrillic slugs are allowed, like product slugs), digits, hyphens; 1–120 chars; collapsed and
  trimmed hyphens. Unique.
- No reserved words are needed: the `/info/` prefix belongs to pages alone (P-D1). The prefix itself is a route constant, not a setting.
- **Changing the slug of a published page** is allowed with a warning ("old links will stop working"). Redirects are a later task
  (named, not built).

### 3.3 Actions
- **Preview** — opens the page as the storefront renders it, through a temporary signed URL (works for drafts; never indexed;
  `noindex` header).
- **Revisions** — a read-only list (number, date, who) and a view of any revision's text. No "restore" in P1 (copy-paste is
  enough; restore is a later convenience).
- Permission: `content_manage` for everything in the resource.

---

## 4. Storefront

- Route `GET /info/{slug}` in the storefront group, constraint `[^/]+` — the controller decides, so an unknown or draft slug gives
  the normal storefront 404 page (same body as a missing product).
- View: the shop layout, `<h1>` title, the body printed with `{!! DescriptionSanitizer::clean($page->body) !!}` — sanitized again
  at render, so a row edited by hand in the database still cannot carry a script. Prose styles reuse `.sf-description`.
- SEO via `SeoMeta`: title = meta title or title; description = meta description or the first ~160 characters of the text;
  canonical = the page URL; `noindex` → `<meta name="robots" content="noindex">`.
- Footer: published pages with `in_footer`, by `footer_order` then title — ONE query, cached with the settings memo (the footer
  is on every page: its query is pinned by a test).
- Cookie-free and cacheable like the rest of the storefront (S4).

---

## 5. The checkout link (D3)

### 5.1 Settings
`CheckoutSettings` → «Полета при поръчка»: for the terms and for the privacy policy, a choice **Page** (select of published pages)
**or External URL** (today's field). New keys `checkout.terms_page_id`, `checkout.privacy_page_id`; a page takes precedence over a
URL; `CheckoutFormSettings` resolves the link (one `getMany` as today + the page lookup in the SAME query as the footer's, or one
indexed query — pin it).

### 5.2 The terms version
- With a **page**: the version is `p{page_id}r{revision}` (e.g. `p3r7`, ≤ 32 chars) — it changes exactly when the page's text
  changes; the «Правилата са обновени» button is hidden (not needed). The order's `terms_version` therefore names the revision row
  that holds the text she accepted (P-D2).
- With an **external URL**: today's behaviour (date set when the URL changes + the button).
- A selected terms page that is **unpublished or archived** = no terms link → the checkbox is not shown and the settings page
  warns (same as "no URL").

### 5.3 Order view
The «Order details» card's terms line links to the accepted revision (`p3r7` → the revision view) when the version has that shape.

---

## 6. Security

- Editor output is untrusted input: sanitized by `DescriptionSanitizer` **before saving** (body and revisions store only clean
  HTML) and **again at render**. The allow-list is the existing one (§0 P-D6); links get `rel="noopener nofollow"` and only
  `https:`, `mailto:` or relative URLs.
- Title, meta fields and slug are plain text (`PlainText`, length limits) and always escaped.
- The preview URL is signed and short-lived; it bypasses only the "published" check, nothing else.
- Max body size: the sanitizer's 200 000-byte limit; a longer body is a validation error, not a silent empty page.

---

## 7. Personal data

None by design. A page is shop content; `staff_id` on a revision is staff data, already governed by the staff domain.

---

## 8. Legal texts

The platform ships **no** ready-made terms or privacy text: they depend on the shop, the country and its lawyer. P1 may seed two
**empty drafts** («Общи условия», «Политика за поверителност») so the merchant sees where they go — empty body, status draft,
never published by default. A content outline for the owner's lawyer is a separate, non-code deliverable on request.

---

## 9. Stage P1 (one commit)

Migration (pages, page_revisions, CHECKs) · Page entity/repository (app layer or a small `EasyCo/Content` package — the coder
follows the existing package pattern) · Filament resource with preview and revisions · `content_manage` permission + granting it
to the Administrator role on existing installs · storefront route, view, footer, SEO · checkout settings page/URL choice and the
version rule · seeding of two empty drafts · docs (`storefront-design.md`, `checkout-form-v2-design.md` §6.3, `site-settings-design.md`,
`staff-access-domain-design.md`).

Tests (★ mutation-checked): sanitizing on save and on render (a `<script>`, `onerror`, `javascript:` link, `style` all gone) ★;
a revision written only when title/body changes ★; revisions never updated; invalid and duplicate slugs refused ★; a draft is 404
publicly and visible through the signed preview; footer list and its pinned query; checkout: page chosen → link + version
`p{id}r{n}`, text change → new version → a stale page version refused (`terms_version_changed`) ★; unpublished terms page → no
checkbox; deleting a page in use refused; permission gate; the 404 body for an unknown slug is the storefront's.

---

## 10. The owner's answers (2026-10-14)

1. URL → prefix `/info/{slug}` (P-D1); redirects from the old shop's URLs at migration.
2. Keep every text revision → yes (P-D2).
3. `content_manage` → Administrator **and Manager** (P-D4).
4. Footer flag now → yes; later a menu builder for managers/admins (P-D5).
5. Two empty drafts at install → yes (§8).
