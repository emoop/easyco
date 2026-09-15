# EasyCo — Production Requirements

**Status:** Describes what a server needs to run EasyCo in production —
separate from `ai-installation-assistant.md`'s local-development
walkthrough. Host-agnostic by design: nothing here ties EasyCo to a
specific hosting provider. EasyCo is meant to be self-hostable on any
server meeting the checklist below, from a managed platform to a bare
VPS you administer yourself.

## Required

- **PHP 8.3+** and **Composer** (per this project's own `composer.json`).
- **MySQL or MariaDB — not SQLite.** Even in WAL mode, SQLite allows
  only one write transaction at a time; real concurrent activity (a POS
  sale and a web checkout happening at the same moment, for instance)
  needs the row-level locking only MySQL/MariaDB provide here. No
  schema changes are needed either way — every migration in this
  project already targets both drivers — this is purely a
  `DB_CONNECTION` choice.
- **A persistent queue worker running as a real system service** —
  Supervisor on Linux, NSSM (or an equivalent Windows service wrapper)
  on Windows, not a terminal window left open. Required for
  image-variant processing (the Media domain) to actually run; without
  it, uploaded images stay pending forever. After any deploy touching
  queued job code, the worker needs `php artisan queue:restart` (or an
  equivalent reload) — it silently keeps running the old code
  otherwise.
- **Node.js 20.19+ or 22.12+, for the build step only.** `npm run
  build` compiles static assets at deploy time; no Node process needs
  to stay running on the server afterward.

## Recommended for real traffic

- **Redis**, for the application cache and the queue driver. Not
  APCu — APCu is per-process/per-server, so behind more than one app
  server each one builds and holds its own independent cache, and a
  write on server A never invalidates the stale entry sitting on
  server B. Redis is a single shared store every app server reads from,
  which stops being optional the moment there's more than one server.
- **A full-page cache layer in front of the app — Varnish is the
  reference choice.** It sits between the web server and PHP for
  anonymous, cacheable responses (product/catalog pages), so a page
  that hasn't changed is served without touching PHP or the database
  at all. This is genuinely host-agnostic: Varnish is free, open-source
  software that installs on essentially any Linux server behind Nginx
  or Apache — it is not tied to any specific hosting company. Some
  managed hosts bundle it already; on a self-administered VPS (for
  instance, a low-cost provider with a self-installed control panel
  such as CloudPanel, aaPanel, or RunCloud), it's a standard,
  well-documented install. A CDN (Cloudflare or otherwise) is an
  optional, additive layer on top of this for geographic distribution
  only — never a requirement. EasyCo does not assume or depend on any
  specific CDN.
  - **Not yet built into EasyCo's own application code as of this
    writing.** See `performance-and-channel-strategy.md` §1 for the
    caching architecture decision (caching belongs only in the
    `Persistence/Eloquent` layer — domain classes never know a cache
    exists) and the storefront frontend work for how anonymous,
    cacheable routes get separated from stateful ones. Cart, checkout,
    and account pages must never be cached, full stop — a page carrying
    someone's session could otherwise be served to a different visitor.
- **Object storage for media (product photos, category/brand images,
  banners, carousels, video), not local disk, once a store's media
  library grows.** EasyCo's own Media domain already writes through a
  storage abstraction (`MediaStorageAdapter`) precisely so this is a
  configuration choice, not an application-code change — any
  S3-compatible object storage (DigitalOcean Spaces, AWS S3, or a
  self-hosted MinIO) works via Laravel's standard `filesystems.php`
  disk configuration. How much storage a given store needs varies
  enormously by merchant — a handful of products with a few photos
  each looks nothing like a catalog of tens of thousands of items,
  each with several images, on top of category imagery, homepage
  banners/carousels, and video — so no specific figure is prescribed
  here. The risk isn't that local disk is invalid for a small store;
  it's that a store which starts small and never migrates off local
  disk risks real growing pains later (disk exhaustion, slower
  backups, harder server migration) that object storage sidesteps
  entirely by being effectively unbounded and provider-managed.
  Deciding this before a media library grows large, not after, avoids
  a disruptive later migration.

## Explicitly not required

- No CDN, no Cloudflare, no specific hosting provider. Everything above
  runs on a plain Linux or Windows server you fully control.
- No separate JS runtime kept alive in production — Node.js is a
  build-time tool only, per above.

## Where to go next

- `ai-installation-assistant.md` — the local-development walkthrough
  (composer install, migrations, first run). Start there to get EasyCo
  running to look at the code.
- `performance-and-channel-strategy.md` — the reasoning behind the
  caching architecture referenced above, and the planned admin UI
  (Filament) direction.
- `staff-access-domain-design.md` — the permission system every
  merchant-facing endpoint sits behind.