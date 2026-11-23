# Site icon, logo and shop name (LOGO-1)

One tab, **«Лого»**, on «Админ → Настройки» (the page `LocaleSettings`, permission `SETTINGS_MANAGE`, the fifth tab) sets the shop's **name**, the **site icon** and the **logos** once, for the storefront, the admin and the customer emails. **Until the merchant sets something, EasyCo's own files and `APP_NAME` stay in use**: nothing is «empty», nothing breaks, and every output is byte for byte what it was.

The word «brand» is not used in our own names (product «Марки» exist). Filament's own method names (`brandLogo()` …) are not ours.

## The settings (the merchant's choices)

| Key | Value | Used by |
|---|---|---|
| `site.name` | plain text, 1–80 characters; empty = `config('app.name')` (`APP_NAME`) | the `<title>` of every storefront page, the logo `alt`, the mail `{shop_name}`, the footer's default line, the home `<h1>`, JSON-LD, `og:site_name`, the admin's page titles |
| `site_icon.media_id` | ONE square PNG or WebP, 512×512 at least | the storefront AND the admin (one favicon) |
| `logo.main` | a wide PNG or WebP, 200 px wide at least | the customer emails, the admin sidebar and login |
| `logo.dark` | OPTIONAL, PNG or WebP, 200 px wide at least | the admin's dark mode only (empty = dark mode uses `logo.main`) |
| `logo.compiled` | JSON written by a save: urls, widths, heights, a hash per part | what is PRINTED |

The pictures come from the media picker (attach-or-upload), restricted by the stored content MIME. **SVG and ICO are not accepted anywhere**: the library does not take them, SVG would need sanitising, and mail clients do not show SVG.

**The header logo is NOT here.** It stays a component in «Дизайн» (placement, size and picture are decided there; with no picture chosen it prints EasyCo's `logo.svg`). The tab says so and links to «Дизайн». The «Дизайн» header editor, the header and checkout-header compilers and the header logo are untouched. (Only the `alt` text of the two header views follows the shop name.)

## What a save generates

Synchronously, with **GD** (the default driver; Imagick is absent on dev and CI), **only for a picture that changed**, never on an unrelated save, never through the queue (a stopped worker changes nothing).

- from `site_icon`: `icon-{hash}-32.png`, `icon-{hash}-180.png` (the iOS touch icon) and a real `icon-{hash}.ico`. GD and Imagick cannot write ICO, but the format only wraps PNG bytes: the file is built by hand (a 6-byte header, a 16-byte entry per image, then the PNGs at 16, 32 and 48 px).
- from `logo.main`: `logo-{hash}.png`, at most 400 px wide, **never upscaled**, transparency kept.
- from `logo.dark`: `logo-{hash}-dark.png`, the same treatment (admin only).

The source is the stored ORIGINAL (already metadata-stripped by the library); the queued WebP variants are not needed. Before decoding, the header is read (`getimagesizefromstring`): a source over 16 megapixels (or 25 MB) is refused with its numbers, so a huge upload cannot exhaust memory. `imagetypes()` is checked at runtime; a server without PNG / WebP support in GD refuses with a plain message. Errors are plain sentences with the numbers («Иконата трябва да е квадратна: 640×480 не е. Минимум 512×512.») and the tab opens on the field.

**Where the files live:** the public disk, folder `appearance/` (the folder of the token stylesheets), served as `/storage/appearance/...`. The file names carry a content hash. When a picture is replaced or removed, the files of the previous generation are deleted. **The cleanup only ever deletes names of the exact shape `icon-{12 hex}[-suffix].png|ico` and `logo-{12 hex}[-suffix].png`**: `tokens-*`, `cookie-preview-*`, `header-preview-*` and anything else in the folder are never touched (the token stylesheets are pruned by the appearance compiler's own, older rule). No symlink, no `proc_open` / `exec` / `shell_exec` / `popen`, no external request, no new library.

## How it is read (no extra query)

`site.name` and `logo.compiled` are in `AppearanceSettings::READ_KEYS`; `StorefrontAppearance::build()` puts `site => {name, logo}` into the cached payload (key `storefront.appearance`). It is **validated again on every read** (`SiteLogo::cleanName`, `SiteLogo::parseCompiled`): an address that is not `/storage/appearance/{icon|logo}-…`, a bad hash, a wrong size, an unknown part, a name with control characters: the whole value is ignored and every output falls back to today's static files and `APP_NAME`. An old cache entry without `site` is rebuilt once.

- **`ShopName::current()`** is the ONE resolver of the shop name: the saved name, else `APP_NAME`. It never throws (any problem is `APP_NAME`) and guards against re-entry while the payload is being built. A test (`SiteLogoMailAndGuardTest`) finds any raw `config('app.name')` in `app/` or `resources/views`. **Left on purpose:** the sender's default name (`mail.from.<sender>.name`, `MailConfigurator`), the admin-login alarm's subject, a one-off migrator, the Laravel welcome page and the Design screen's own sample text.
- **A saved name changes only the DEFAULT and legacy footer line.** A published footer keeps its literal text (it was typed in «Дизайн»).
- **Storefront `<head>`:** with an icon, three tags replace the one SVG tag: `rel="icon"` PNG 32 (`type`, `sizes`), `rel="icon" href="/favicon.ico" sizes="any"`, `rel="apple-touch-icon"` 180. **Without an icon the head is byte for byte today's one SVG tag.** No manifest, no `theme-color` (not asked).
- **Admin:** `favicon()`, `brandName()`, `brandLogo()` and `darkModeBrandLogo()` in `AdminPanelProvider` are closures over `AdminLogo`, which reads the cached payload and falls back to today's static files when nothing is set or anything at all goes wrong. The login page runs before auth: nothing needs a user and nothing can make it fail. Dark mode: `logo.dark`, else `logo.main`, else EasyCo's white file.
  **The «no extra SELECT» caveat:** on Redis (the Docker default) and the array store a warm admin request adds **0** SELECTs. On `CACHE_STORE=database` (the `.env.example` default) the payload read is one cheap primary-key lookup in the `cache` table, shared by all four closures. A cold cache costs the one existing appearance `getMany`.
- **Customer emails:** `TemplateRenderer::wrap()` (code, never the merchant's template: `HtmlSanitizer` keeps `img` out on purpose) prints the logo as the header of **every** template key (all nine go through `wrap()`): the raster PNG, an **absolute** URL built from `APP_URL`, `width` / `height` attributes, shown at most 200 px wide, `alt` = the shop name, escaped. With no `logo.main`, or with no absolute `APP_URL`, nothing is printed and the mail is byte for byte what it was: EasyCo's own logo is never put into a shop's mails. The plain-text part never has a logo.

## `/favicon.ico`

A Laravel route `seo.favicon` in the **sessionless** storefront group (the `/robots.txt` pattern): no session, no cookie, no `Set-Cookie`. With an icon: `200`, `Content-Type: image/x-icon`, the generated `.ico`, and the contract's headers (`Cache-Control: public, max-age=0, s-maxage=604800` + `xkey: all …`, because the route name is in `StorefrontCacheHeaders::ROUTES`); the page cache is purged when the merchant saves. **With no icon (or a missing file, or a damaged value): an empty `404`, `Cache-Control: private, no-store`, not cached.** No default ICO is shipped; the head keeps the static SVG tag. The empty `public/favicon.ico` that used to answer `200` with no body is deleted (it would shadow the route).

`docker/nginx/default.conf.template` has `location = /favicon.ico { try_files /__through_the_app__ /index.php?$query_string; }`, placed BEFORE the image-extension rule (whose `try_files … =404` would answer for it and never reach the app), like the one for `/robots.txt`.

**After the Redeploy, check with `curl -I https://<shop>/favicon.ico`:**
1. with no icon set: `HTTP/… 404`, no `Set-Cookie`, `Cache-Control: … no-store`;
2. after saving an icon in «Лого»: `HTTP/… 200`, `Content-Type: image/x-icon`, **no `Set-Cookie`**, `Cache-Control: public, max-age=0, s-maxage=604800`, and an `xkey` header;
3. `curl -I` of the `apple-touch-icon` URL in the page's `<head>`: `200`, `image/png`.
If (1) answers `200` with an empty body, the old static `public/favicon.ico` is still in the image or nginx still has the old rule: rebuild the image.

## Cache and purge

A save that changes the name, an icon or a logo runs the SAME refresh as a «Дизайн» publish and the cookie page: `AppearanceRecompiler::run` (the token stylesheet, the payload, ONE purge of `all`). An unrelated save of the page (the locale, the time zone) runs none of it and generates nothing. The `site.*`, `site_icon.*` and `logo.*` keys are storefront-visible, so the settings repository also collects `all` on every write (one batch).

## The usage registry («Медия»)

`SiteIconUsage` and `SiteLogoUsage` are registered in `MediaUsageRegistry` the way the header logo (`DesignZones`) and the product-brand logo are. A picture used as the site icon, the logo or the dark logo cannot be deleted from «Медия»: the delete button is disabled, the details say where it is used («Настройки → Лого — иконата на сайта», «… лого (имейли и админ)», «… тъмно лого на админа», linking to the tab) and `MediaDeleter` answers «Файлът се използва и не може да бъде изтрит.» if asked anyway. **When a picture is replaced**, the old one is no longer used and can be deleted (its generated files are already gone).

## Out of scope (not built)

Contact data (phone, email, address, hours; it stays in the footer block), a site-wide `og:image`, `theme-color`, a web manifest, an Organization `logo` in JSON-LD, the admin login page's own design, invoices (none exist), error pages, the installer, the VCL.
