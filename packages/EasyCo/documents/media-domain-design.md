# Media Domain Design

**Status:** v1.1 — domain layer implemented: `MediaAsset` (`create()`/`reconstituteFromStorage()`, guarded PENDING→PROCESSING→READY|FAILED transitions, video types permanently READY per §4), `MediaVariant`, `MediaType`/`ProcessingStatus` enums. Migrations relocated to `packages/EasyCo/Media/database/migrations/` and `catalog_media` updated to match (§9). Still not implemented: Eloquent model/repository, the `ProductMedia`/`VariationMedia` domain classes for the two existing pivots, the actual image-processing pipeline (§3), storage adapter (§5), and any HTTP surface. Originally framed as "design only... before the corresponding code is written" (see `performance-and-channel-strategy.md`'s same framing) — that framing applied to v1.0; this document now trails real implementation rather than preceding it.
**Builds on:** the domain/persistence isolation principle from `catalog-domain-design.md`/`pricing-domain-design.md` (a domain class never knows about Laravel, a database, a cache, or a storage disk); the config-driven, fail-appropriately pattern established by `EasyCo\Pricing\DefaultCurrency` (§5); `extensibility-design-and-hooks.md`'s Hook mechanism (referenced, not extended, in §7); Laravel's first-party `Illuminate\Image` API (§3 — requires a version prerequisite, called out explicitly, not assumed).
**Relates to:** `catalog-domain-design.md` §1, which briefly stated "Catalog owns the reference (`catalog_media`), never the storage provider/CDN" at a point when no dedicated Media domain existed yet. This document supersedes that one line with a full design and reassigns ownership of the *domain logic* governing those tables to a new `EasyCo\Media` domain (§9) — Catalog's own `Product`/`Variation` classes are unaffected and remain unaware Media exists at all (cross-domain-by-id, per CLAUDE.md rule 9).

---

## 1. Scope & existing foundation

Three tables already exist — `catalog_media`, `catalog_product_media`, `catalog_variation_media`. `catalog_media`'s original migration (`packages/EasyCo/Catalog/database/migrations/2026_08_23_000012_create_catalog_media_tables.php` at the time this document was first written) has since been relocated to `packages/EasyCo/Media/database/migrations/`, same filename, and a second migration brought its schema in line with `MediaAsset` (§9): `url` dropped, `disk`/`path`/`processing_status`/`processing_failure_reason`/`variants` added. The two pivots (`catalog_product_media`, `catalog_variation_media`) remain exactly as originally created — a straightforward `product_id`/`variation_id` + `media_id` + `sort_order`, each with a `unique(parent_id, media_id)` constraint — and are still untouched by any domain class as of this update. `EasyCo\Media\MediaAsset` (the domain class) now exists; there is still no Eloquent model, no repository, no processing pipeline, and no HTTP surface for any of this.

This document designs: the domain class(es) that will own these tables, the image-processing pipeline, storage configuration, upload limits, and the two closely-related-but-distinct concepts (Hero Slider/Grid, Site settings) that keep getting mentioned in the same breath as "media" but are **not** the same thing (§2). It does not implement any of it — no code changes accompany this document.

---

## 2. Three separate concepts, deliberately not conflated

This separation was a real decision made with the domain owner, not an assumption made while writing this document — recorded explicitly so a future contributor doesn't casually merge these back together because they all involve "images."

### 2.1 Product/Variation Media

Photos/videos attached to a specific catalog item — what `catalog_media`/`catalog_product_media`/`catalog_variation_media` already exist for. Owned by the new `EasyCo\Media` domain going forward (§9), referencing Catalog's `product_id`/`variation_id` as plain ids in the pivot tables' FK columns — a database-level foreign key, not a PHP-level package dependency, so this does not violate CLAUDE.md rule 9 any more than `catalog_variations.product_id` itself does. `Product`/`Variation` never import anything from `EasyCo\Media`; Media's persistence layer is simply told "attach media X to product/variation id Y."

### 2.2 Site Hero Slider/Grid

Manually admin-curated homepage content — an admin uploads specific images (and/or short promotional copy/links) intended for the storefront's front page. **Not** driven by Catalog/Pricing data at all; this is curated content, the opposite of the dynamic, auto-populated widget described in the local note referenced in §10.

**The domain only needs to support multiple simultaneous active "front page" images** — it does not assume a strictly linear, one-at-a-time slider. Whether the storefront renders that set as a rotating carousel, a static grid, or something else is a **presentation-layer/design decision**, entirely outside this domain's concern — the same separation Catalog already draws between `catalog_visibility` (a fact) and how a storefront template chooses to lay listings out (not Catalog's business). The domain's job is just: which media items are currently active for the front page, and in what order.

**Toggleable, but enabled by default, admin-managed** — a single on/off switch for the whole feature, independent of any individual slide's own active/inactive state (an admin may want to hide the entire slider temporarily without deactivating every slide one at a time). **Resolved: this toggle does not belong to the Media domain's own storage at all.** It's a feature flag alongside other site-level configuration, so it belongs to the "Site settings" concept in §2.3 below — the same place logo/favicon will live — not to a Media-domain table. Its concrete design (and implementation) therefore waits on Site settings being designed, not on anything in this document (§10).

### 2.3 Site settings (logo, favicon)

**Not part of the Media domain at all.** A site logo or favicon is not curated *content* in the same sense as product photos or hero images — it's closer to configuration: exactly one active value at a time, no gallery, no variant-generation pipeline in the sense of §3 (a favicon in particular has its own fixed, tiny-dimension format conventions that have nothing to do with WebP thumbnail/medium/large generation). This is a simple, distinct config/settings concept — its own small model, likely a single-row settings table or a key/value store — explicitly called out here only so nobody later tries to route logo/favicon uploads through Media's processing pipeline or its `catalog_product_media`-style pivot pattern by default. Designing that settings concept itself is out of scope for this document.

---

## 3. Image processing pipeline (photos only — see §4 for why video is excluded)

**Decision:** built on Laravel's first-party `Illuminate\Image` API, which is driver-based on Intervention Image v4.

**Prerequisite — satisfied.** `composer.json` constrains `laravel/framework` to `^13.17`, but the actually-installed version (per the git-tracked `composer.lock`) is **13.25.0**, already above `Illuminate\Image`'s 13.20 minimum. No upgrade step is needed; this was verified by reading `composer.lock`, not assumed from the constraint alone.

### 3.1 Accepted input formats

**Always accepted** (both GD and Imagick drivers support them universally): JPEG, PNG, WebP. Note that `.jfif` is not a separate format — it is JPEG with a different file extension, and needs no special handling.

**Conditionally accepted, driver-dependent:** AVIF and HEIC/HEIF. The GD driver decodes only JPG/PNG/GIF/BMP/WebP; AVIF requires GD compiled with libavif, and **HEIC/HEIF is not supported by GD at all** — it requires ImageMagick. This matters practically: iPhones shoot HEIC by default, so a merchant photographing products on a phone and uploading directly will hit this on a GD-only server.

**Requirement:** support must be checked at runtime, never assumed. **There is no capability-query method to do this with** — an earlier draft of this section assumed `DriverInterface::supports()` existed; implementation confirmed it does not (`Illuminate\Contracts\Image\Driver` exposes only `process()`/`dimensions()`/`dominantColor()`/`transformUsing()`). The only real signal is attempting a decode and catching whatever it throws — and it must catch `Throwable`, not a specific exception type, since a corrupt file can fail with a `ValueError` or another GD/Imagick-originated error that never gets wrapped. An unsupported format must produce a clear, actionable message ("this format isn't supported on this server — install ImageMagick, or convert the image first"), never an obscure decode failure.

**`intervention/image` is a required Composer dependency, not merely an implementation detail.** `Illuminate\Image` is a thin Laravel wrapper over Intervention Image as its driver — the driver itself is a separate package that must be explicitly installed (`intervention/image ^4.0`, in `require`, not `require-dev`, since production image processing genuinely depends on it). This was discovered during implementation: the framework class exists without it, but nothing actually processes. Worth stating plainly here, since "first-party Laravel API" otherwise reads as "already available, nothing to install."

**ImageMagick is recommended, not required.** It goes in `composer.json`'s `suggest` block, not `require` — mirroring the same posture as the SQLite/MySQL decision (§ installation docs): require what's genuinely necessary, don't block installation over an enhancement. JPEG/PNG/WebP work fine on GD; Imagick adds HEIC/AVIF. The installation documentation must state plainly what is lost without it.

**Output is always WebP**, regardless of input format.

### 3.2 Variant tiers

Every tier's numbers are **configurable, never hardcoded** — an explicit requirement from the domain owner.

| Tier | Bound | Method | Purpose |
|---|---|---|---|
| `thumbnail` | max 400px | `scale()` | Product lists, category grids |
| `medium` | max 900px | `scale()` | Product page, standard view |
| `large` | max 1600px | `scale()` | Zoom on hover/click |
| `admin_grid` | 42×42 fixed | `cover()` | Admin product-table thumbnails only |

The three customer-facing tiers use **`scale()`, which fits the image inside the bound while preserving its own aspect ratio and never crops.** A single number per tier is therefore sufficient: "max 400" yields 400×400 for a square source, 320×400 for a 4:5 source. `admin_grid` is the **only** tier using `cover()` (fixed square, cropping permitted) — it is a tiny UI affordance in the admin product table, not customer-facing imagery.

### 3.3 Store-wide aspect ratio is a layout setting, NOT a processing input

The merchant picks one aspect ratio for their store at setup — **1:1, 4:5, 3:4, or 2:3** — stored via the Site Settings mechanism (`site-settings-design.md`), default 1:1.

**Critically: this setting does not affect image processing at all.** It is purely a layout hint for templates — how much space to reserve for a product image so the storefront looks consistent. The pipeline never crops to it. If a merchant selects 4:5 but uploads square photos, those photos stay square; they simply won't fill the reserved space.

**Reasoning, from the domain owner, recorded so it isn't re-litigated:** cropping to force a ratio risks cutting off a detail that matters, and the system has no way to know which part of a given photo is important. Ensuring photos roughly match the chosen ratio is the merchant's responsibility, not something the platform should attempt to fix magically without context.

**Why these four ratios:** 1:1 is the universal catalog/Instagram default and easiest to keep visually consistent; 4:5 and 3:4 both suit clothing and footwear well and use more vertical space on mobile (3:4 vertical is also exactly what phone cameras produce natively, since phone sensors shoot 4:3); 2:3 is the native ratio of DSLR/mirrorless cameras, for merchants shooting on real cameras rather than phones. 16:9 is deliberately excluded — too wide for product photography, useful for banners/hero images instead.

### 3.4 Pipeline order

1. **`orient()` first** — applies the image's EXIF orientation and strips the tag, so a photo taken on a phone held sideways renders right-side-up. This must run before any resizing: resizing an unoriented image can compound the visual error.
2. **Generate WebP variants** per §3.2's tiers, all config-driven.
3. **Scaling is always downward-only — never upscale.** Laravel's own `scale()`/`cover()` already refuse to enlarge past the source's native resolution. Concrete motivating example from the domain owner: a real product photo already delivered at 1000×1200px / ~200KB should not be artificially stretched to fill a 1600px `large` tier — that image's `large` variant is simply its own original dimensions, not a fabricated larger image with fabricated detail.

**Processing runs as a queued job, not synchronously during the upload request** — Laravel's own documented recommendation for non-trivial image manipulation, doubly relevant when one upload produces several variants. The upload request validates, stores the original, and creates the `catalog_media` row in `pending` (§9); a queued job then runs the pipeline and marks the row `ready` with its variants, or `failed` with a reason.

### 3.5 Partial failure: all-or-nothing

If some variants generate successfully but a later one fails, **the already-generated variants are deleted** and the asset is marked `failed`. The merchant re-uploads.

**Reasoning:** orphaned partial variants would consume storage indefinitely while the asset is unusable anyway. A clean failed state that prompts a re-upload is simpler and more honest than a partially-ready state the `ProcessingStatus` enum cannot express. This also means `markFailed()`'s existing "leaves variants untouched" behavior applies to the *domain object*; deleting the actual stored files is the pipeline job's responsibility, not the entity's.

### 3.6 Who decides to dispatch the job — an open item for the upload endpoint

`ProcessMediaAssetJob` assumes it is only ever dispatched for an image asset that is currently `PENDING`. It calls `markProcessing()` before its own try/catch, so two situations crash the job rather than exiting cleanly:

- **A VIDEO/SOCIAL_VIDEO asset.** `markProcessing()` rejects video outright (§4 — video never enters the processing lifecycle), throwing immediately.
- **An already-processed asset.** A duplicate or re-queued dispatch hits an asset already `READY`/`FAILED`, and `markProcessing()` only accepts `PENDING`.

Neither is reachable today, because nothing dispatches this job yet. **The upload endpoint — not yet built — is the correct place to decide this:** it should simply not dispatch for video, since a video asset is created `READY` and has nothing to process. Recorded here so it isn't rediscovered as a mysterious queue failure once that endpoint exists.

If a defensive guard inside the job is preferred later instead, it belongs at the very top of `handle()` (alongside the existing "asset was deleted" early return), not wrapped around `markProcessing()` — the early-return shape already established there is the right precedent.

---

## 4. Video handling: no processing pipeline in v1 (explicit, not an oversight)

Video is stored exactly as uploaded, on the configured disk (§5) — no thumbnail/poster-frame extraction, no transcoding, no format normalization. This is a deliberate v1 scope decision, not a gap:

- The domain owner already pre-processes video before upload (compression, format, whatever their own workflow requires) — EasyCo doesn't need to redo work already being done upstream.
- Video is used sparingly in the current real usage pattern — an expensive resource to process (CPU/queue time, storage, egress) for low actual volume.
- Building a transcoding/poster-frame pipeline speculatively, before real usage demands it, would be exactly the kind of premature scope this project's working conventions warn against.

**Revisit if usage grows** — if video volume or variety of source formats increases meaningfully, this section is the place to redesign, not a sign v1 missed something. Recorded as deferred in §10.

---

## 5. Storage

**Decision:** a configurable Laravel Filesystem/Flysystem disk — local by default, with S3-compatible providers (DigitalOcean Spaces, Cloudflare R2, etc.) available as drop-in alternatives purely via config, with no application code changes required to switch. This mirrors the config-driven pattern already established by `EasyCo\Pricing\DefaultCurrency`: the Media domain layer itself never touches `Illuminate\Support\Facades\Storage` directly (that's a `Persistence`/app-layer concern, same layering rule Catalog and Pricing already follow) — only the infrastructure adapter resolves a disk name from config and acts on it.

**One deliberate difference from `DefaultCurrency`'s fail-loud posture:** `DefaultCurrency::get()` throws if never configured, because *any* hardcoded currency default risks being silently, legally wrong (the whole reason that class exists — see its own docblock and CLAUDE.md rule 8). A storage disk doesn't carry that same risk profile: defaulting to Laravel's already-existing `local`/`public` disk when nothing else is configured is a safe, harmless default, not a guess that could be quietly incorrect. So: config-driven and overridable, like `DefaultCurrency`, but with a sensible default rather than a required, fail-loud configuration step.

**Folder structure: month-based (`uploads/YYYY/MM/...`).** Standard practice for avoiding a single directory accumulating an unbounded number of files. Confirmed this needs no special Laravel feature — `Storage::disk($disk)->putFile($path, $file)` (or `putFileAs()`) already accepts an arbitrary directory string; `"uploads/".now()->format('Y/m')` built at upload time is sufficient, nothing framework-specific to add.

---

## 6. Upload limits

**Decision:** configurable file-size caps, with **separate limits for images vs. video** (video files are naturally much larger) — config-driven, never hardcoded, so a merchant with different needs can tighten (or loosen) either limit without a code change.

**Proposed defaults, with reasoning (starting points, not fixed):**
- **Images: 10 MB.** The domain owner's own real example (a pre-processed product photo at 1000×1200px, ~200KB) is nowhere near this — 10MB gives generous headroom for an *unprocessed* phone-camera photo (modern phone cameras commonly produce 8–15MB JPEGs at full resolution) without inviting arbitrarily large uncompressed source files.
- **Video: 200 MB.** Even a short (30–90 second) 1080p or 4K product clip commonly lands in the tens-to-~150MB range depending on compression; 200MB leaves headroom for that real usage pattern (short, sparingly-used clips, already pre-processed by the domain owner per §4) without permitting multi-hour raw footage uploads through this endpoint.
- **Minimum image dimensions: 600×600px.** Checked **at upload time**, not in the processing job — a too-small image should be rejected with a clear warning while the merchant is still in the upload flow, not silently accepted and discovered later as a blurry `large` variant (since §3.4's never-upscale rule means a 400px source simply stays 400px everywhere). The value comes from the domain owner's own experience: real product photos at 600×600 still display acceptably in several views. Configurable like everything else here.

Both values must live in config (e.g. `config('services.media.max_image_size_kb')` / `max_video_size_kb`), not as inline validation-rule constants — consistent with every other "don't hardcode, make it configurable" decision in this document.

---

## 7. AI-generated media disclosure — cross-reference, not a design

If/when AI-generated product imagery is ever supported, the Media domain's model (§9) should be able to carry a generated/AI-disclosure flag **from day one of that future work** — noted here now so it isn't bolted on awkwardly later as a schema afterthought. This section only records that intent; it does not design the flag's mechanics (what values it takes, whether it's per-variant or per-original, how it's surfaced to a storefront/marketplace feed) — that's for whenever AI generation itself is actually being built.

**A discrepancy worth flagging rather than silently working around:** the task that produced this document referred to "`performance-and-channel-strategy.md`'s existing AI-content-disclosure section." Checked directly — that document's §3 ("Social Media & AI Agent Channel Strategy") covers Meta Pixel/CAPI, GDPR consent, and AI-agent product-feed readiness, but currently contains **no section about disclosing AI-generated content**. Rather than inventing a cross-reference to a section that doesn't exist, this is flagged here as an open item: either such a section should be added to `performance-and-channel-strategy.md` when this cross-reference actually matters, or the two documents should be reconciled at that time. Not resolved in this pass.

**Agreed resolution path (decided with the domain owner):** `performance-and-channel-strategy.md` should gain a dedicated AI-content-disclosure section, as a small, separate follow-up task — not part of this document, and not done now — so this section's cross-reference has something real to eventually point at.

---

## 8. Entities (domain model sketch)

```
MediaAsset                          (new domain class — EasyCo\Media)
├── id
├── type                            image | video | social_image | social_video —
│                                    the social_ prefix is a PROVENANCE tag, not a
│                                    processing distinction (see §9): it marks
│                                    content sourced from a social platform (e.g.
│                                    a repurposed Instagram post), mapping to
│                                    commerce-knowledge-layer-concept.md §3's
│                                    CUSTOMER GENERATED provenance category.
│                                    social_image is processed exactly like image
│                                    (§3); social_video is processed exactly like
│                                    video (§4, i.e. not at all)
├── disk, path                      infrastructure-layer concern (§5); the domain
│                                    class holds a disk/path pair, never a
│                                    hardcoded absolute URL
├── alt_text
├── processing_status               pending | processing | ready | failed (images
│                                    only — a video row goes straight to "ready"
│                                    since §4 has no pipeline for it)
├── variants[]                      generated WebP renditions (images only) —
│                                    tier name, width, height, quality, path
└── (future) ai_generated flag      NOT designed yet — see §7

ProductMedia / VariationMedia       (existing pivots — catalog_product_media /
                                     catalog_variation_media) — product_id or
                                     variation_id (plain ids, §2.1) + media_id +
                                     sort_order, unchanged in shape from the
                                     existing migration

HeroSlide                           (new — §2.2)
├── id, media_id                    → MediaAsset
├── sort_order
├── is_active                       per-slide toggle
├── link_url                        nullable — where the slide navigates to
└── (feature-wide enabled toggle    lives alongside this entity, exact storage
    shape an open implementation detail — see §2.2/§10)
```

`Site settings` (logo/favicon, §2.3) is explicitly **not** sketched here — it isn't part of this domain's model at all.

---

## 9. Database design

**New package, new migrations directory — done.** `EasyCo\Media` now owns its migrations under its own package's `database/migrations/`, matching `Pricing`/`Catalog`/`OperationalSales`. The original `2026_08_23_000012_create_catalog_media_tables.php` migration was relocated there (`git mv`, filename unchanged — Laravel tracks applied migrations by filename, not path, so this didn't re-run it), and `MediaServiceProvider::boot()` loads Media's migrations directory, mirroring `PricingServiceProvider`.

**Columns added to `catalog_media`:** `disk` (string), `path` (string), `processing_status` (string), `processing_failure_reason` (nullable text), and `variants` (nullable JSON) — a single column rather than a dedicated child table for v1, the same "smallest model that satisfies the actual need" reasoning `catalog-domain-design.md` §3.3 already applied to attribute definitions: nothing today needs to query "find all media with a `medium` variant of size X," so a child table would add relational overhead with no current payoff. Revisit as a dedicated table only if that kind of per-variant query actually becomes necessary. `url` was dropped (§5 — replaced by the `disk`/`path` pair). **One deviation from this section's original plan, worth recording:** `processing_status` has **no DB-level default** — the original plan proposed `default('pending')`, but `MediaAsset::create()`'s actual initial value branches by type (`PENDING` for IMAGE/SOCIAL_IMAGE, `READY` for VIDEO/SOCIAL_VIDEO, §4/§8), so a single DB-level default would have been wrong for video assets. The table was confirmed empty (0 rows) before this migration ran, so no backfill/default was needed either way.

**New table for Hero Slides** (name proposed, not final): `media_hero_slides` — `id`, `media_id` (FK → the media table), `sort_order`, `is_active`, `link_url` (nullable), timestamps. The feature-wide enabled/disabled toggle (§2.2) is **not** part of this table, or of the Media domain's storage at all — see §2.2/§10 for where it actually lives.

**Resolved: `catalog_media.type`'s `social_image`/`social_video` values.** These mark content whose *provenance* is a social platform (e.g. a repurposed Instagram post shown on a product page) rather than official product photography — distinct sourcing, not a distinct format. This maps directly onto `commerce-knowledge-layer-concept.md` §3's provenance model: `social_image`/`social_video` correspond to that model's **CUSTOMER GENERATED** category, as opposed to official product media, which is closer to a **MERCHANT OBSERVATION** (or, once captured, a plain **FACT**) — the `social_` prefix is EasyCo's Media-domain-level equivalent of that same provenance tagging discipline, applied to the one concept (media type) that already had a place to carry it. **Processing-wise, the prefix changes nothing:** `social_image` goes through the exact same pipeline as `image` (§3); `social_video` gets no processing, exactly like `video` (§4). The "social" qualifier is about where the content came from, never how it should be processed.

---

## 10. Explicitly deferred (documented, not accidental)

- **Video processing/transcoding** — see §4. Deferred deliberately, revisit if usage pattern changes.
- **The Product/Brand slider widget** — a *dynamic* storefront widget that reads existing Catalog/Pricing data live and renders cards automatically (product name, current price via `priceableId` → `PriceResolver`, a brand variant using `catalog_brands`) with **no upload or curation step at all**. This is fundamentally different from the Hero Slider (§2.2), which is 100% manually-curated static content — the two should never be designed as the same feature. Captured in more detail in a local development note (`notes/local/product-brand-slider-widget-note.md` — not tracked in version control; a permanent record should move into a real design document once Storefront/Admin UI work actually reaches this feature). Not part of the Media domain at all — noted here only to make the boundary explicit.
- **CDN-specific configuration beyond the generic disk abstraction** (§5) — cache invalidation, signed URLs, edge-specific transforms, etc. The Filesystem/Flysystem abstraction is deliberately the full extent of what this document designs; anything CDN-provider-specific is a future, separate concern.
- **Image moderation/content scanning** — no automated review of uploaded media (inappropriate content, copyright, etc.) is designed here.
- **The Hero Slider's feature-wide enabled/disabled toggle** — resolved as belonging to Site settings, not Media (§2.2). What remains deferred is its concrete design: it now explicitly waits on Site settings' own design (§2.3) being scheduled, not on anything in this document.
- **The `Site settings` (logo/favicon) model itself** — explicitly out of scope for this document (§2.3); needs its own, much smaller design whenever that work is scheduled. The Hero Slider toggle above will be part of that same future design.
