# Media library «Медия»: browse, search, where-used, safe delete, and a picker in every picture field

Status: approved by the owner on 2026-10-24 (answers in §11). Base: `main` at 70fc3ed (DS-2c).
Builds on `media-domain-design.md` (MediaAsset, WebP pipeline, storage adapter, §8.5 "detach never deletes the asset") and `media-cleanup-and-storage-optimization-note.md` §1 (orphans), and is the prerequisite of DS-4 in `design-screen-design.md` §9.

Owner's wish (2026-10-20): browse and search already uploaded images (also for products), attach existing ones instead of uploading again, delete wrongly uploaded files.

## 0. Decisions this document takes

| # | Decision |
|---|---|
| ML-D1 | The library shows **`catalog_media` rows (MediaAssets)** only. One asset = one original + its WebP variants. No folders in v1: search + filters + "where used" do the job (WordPress has no folders either). |
| ML-D2 | **"Where used" is computed live** by a registry of usage providers (one per place that can hold a media id), batched per page. No usage index table to keep in sync. Extensions add providers through a hook. |
| ML-D3 | **An asset that is used anywhere cannot be deleted.** The rule is enforced three times: the UI (button disabled with the reason), the delete service (re-check inside a transaction with a row lock), and the **database** (the foreign keys that today CASCADE a deleted asset out of products are changed to RESTRICT — CLAUDE.md rule 2). |
| ML-D4 | Files are removed **after** the database row is gone (after commit), original + every variant; a failure is logged and retried, never leaves a row pointing at nothing. |
| ML-D5 | **One picker component** (`MediaPicker`) for every picture field: «Избери от медията» / «Качи нова» / «Махни». It stores a media id. Upload goes through the existing storage adapter + `ProcessMediaAssetJob`, as today. |
| ML-D6 | The storefront does not change: it keeps reading variants exactly as now; no query is added to any storefront page. |
| ML-D7 | **WordPress behaviour everywhere (owner):** every place that shows a picture offers BOTH «Качи нова» and «Избери от медията», and removing a picture from a product / block / zone only detaches it, never deletes the file. This includes the **rich-text editor of HTML blocks**: its picture button opens the library (pick or upload) instead of saving loose files. |
| ML-D8 | **Originals are stored without metadata** (EXIF / GPS / XMP / IPTC removed losslessly at upload); existing originals are cleaned once by a command (owner: "ненужни и опасни"). |
| ML-D9 | **Delete is final**, after a warning that names what disappears: the original and ALL its size variants (count and total size). Anyone who can open «Медия» may delete; the activity log records who. |

## 1. What exists today (checked in the code at 70fc3ed)

- `catalog_media` (`type`, legacy `url`, `alt_text`, `disk`, `path`, `processing_status`, `processing_failure_reason`, `variants` JSON, timestamps). Originals under `uploads/YYYY/MM/{uuid}.{ext}` on the configured disk (default `public`); variants `thumbnail` 400, `medium` 900, `large` 1600, `admin_grid` 42×42, WebP.
- **Places that hold a media id:**

| Place | How | On asset delete today |
|---|---|---|
| Product photos / video | `catalog_product_media.media_id` FK | **CASCADE** (the photo silently disappears from the product) |
| Variation photos | `catalog_variation_media.media_id` FK | **CASCADE** |
| Brand logo | `catalog_brands.logo_media_asset_id` FK | SET NULL |
| HTML block pictures (MENU-5) | `html_blocks.pictures` JSON `[{media_asset_id, alt, url}]` | nothing (skipped at render) |
| HTML block body (after ML-2) | `<img src="/storage/uploads/…">` in `html_blocks.body` | the `<img>` would point at a missing file — prevented by the usage check |
| Design zones (DS-4 onward) | `design.*` settings JSON, `media` field kind | nothing (skipped at compile) |

- **Not MediaAssets today:** pictures attached inside the HTML block **rich-text editor** (`attachFiles` → `public/html-blocks/…`, referenced as `<img src="/storage/html-blocks/…">` in the body) — ML-2 moves this button onto the library (§7); and the shop logo (`public/images/logo.svg`), which stays a theme file.
- Variant paths follow the original deterministically: `uploads/Y/m/{uuid}.jpg` → `uploads/Y/m/{uuid}-{tier}.webp` (`LaravelMediaImageProcessor`). So any `<img src>` that points at a variant or the original can be mapped back to its asset by path, without a JSON search.
- Nothing deletes a `catalog_media` row today. Order lines store no image (the admin order view looks up the product's current first photo), so history is not affected by deleting a photo (CLAUDE.md rule 4: media pivots are configuration).
- Uploads through a Filament `FileUpload` store the bytes immediately, but the asset row is created only when the form is saved: an abandoned form leaves a **file with no row** (see §5, sweep).

## 2. Model additions (one migration + one backfill command)

`catalog_media` gains (all nullable, filled for new uploads, backfilled for old ones):

| Column | Why |
|---|---|
| `original_name` (string 200, plain text) | search; shown in the grid |
| `mime` (string 50), `size_bytes` (unsigned int) | details, «голям файл» hint |
| `width`, `height` (unsigned int) | details; layout of the picker; the original's dimensions |
| `uploaded_by` (unsigned bigint, nullable, no FK — staff may be deleted) | details, activity |
| index `(type, created_at)`; index `original_name` (prefix) | the list and the search |

`php artisan media:backfill-meta` (idempotent, dry-run by default): reads each row's original once (dimensions, size, mime by `finfo`, never the extension) and `original_name` = the stored file name when unknown. Writes only empty columns.

## 3. Where used: the usage registry

```php
interface MediaUsageProvider {
    /** @param list<int> $ids  @return array<int, int>  id => number of uses (only ids that are used) */
    public function counts(array $ids): array;
    /** @return list<MediaUsage>  {label: «Продукт „Рокля Лято“ (галерия)», editUrl: ?string, kind: string} */
    public function usagesOf(int $id): array;
    /** a query fragment for the "Неизползвани" filter: `whereNotExists(...)` or a small id list */
    public function excludeUsed(Builder $query): void;
}
```

- `App\Media\Usage\MediaUsageRegistry` = built-ins ∪ `Hook::filter('media.usages', $providers)` (an extension that stores media ids registers its provider; added to the Hook Reference table in the same commit).
- Built-ins: **ProductPhotos** (counts archived / soft-deleted products too, labelled «архивиран продукт» — the photo is still theirs), **VariationPhotos**, **BrandLogo**, **HtmlBlockPictures** (one query reads all `pictures` columns; JSON scanned in PHP — dozens of rows), **HtmlBlockBody** (the same query also reads `body`; every `<img src>` under `/storage/uploads/` is mapped to its asset by the path rule of §1), **DesignZones** (one read of the `design.*` / header / footer draft AND published keys; drafts count as use).
- The grid shows a usage badge per asset with **one batched call per provider per page** (pinned query count for the page, independent of page size). The details panel lists every usage with a link to its edit page.

## 4. Delete

- Single delete in the details panel; bulk «Изтрий избраните» in the grid (selection of unused assets only; a used one in the selection is skipped and reported).
- **The warning before delete** (a confirmation modal, owner) says it plainly: «Ще бъдат изтрити окончателно оригиналът и N размера (миниатюра, среден, голям, за админа) — общо X MB. Това не може да се върне.» For a bulk delete: the number of pictures, files and the total size.
- `MediaDeleter::delete(int $id, int $staffId)`: transaction → `SELECT … FOR UPDATE` on the row → re-check every provider → delete the row → after commit dispatch `DeleteMediaFilesJob` (the original AND every variant listed in `variants`, plus any `{uuid}-*.webp` left next to the original by an earlier failed processing; missing file = fine; failure logged and retried 3×) → activity-log line (who, asset id, original name; never the file contents).
- **Migration (CLAUDE.md rule 2, rule 6):** `catalog_product_media.media_id` and `catalog_variation_media.media_id` FKs from `cascadeOnDelete` to `restrictOnDelete`; `catalog_brands.logo_media_asset_id` from `nullOnDelete` to `restrictOnDelete`. Explicit short constraint names (rule 5). Verified with `SHOW CREATE TABLE`. Deleting a PRODUCT still removes its pivots (the `product_id` side keeps CASCADE) — the Prompt Ж deletion flow is unchanged; this is tested.
- A JSON reference (HTML block, design) cannot be a DB constraint: the provider re-check inside the transaction covers it, and a stale id in JSON is already skipped at render/compile.
- **Not in v1:** a trash with undo (see question 2).

## 5. Files with no row: the sweep

`php artisan media:sweep-files` (dry-run by default; `--delete`): lists files under `uploads/` older than 24 hours that no `catalog_media.path` and no variant path points at (abandoned uploads), with their total size; `--delete` removes them. Run by hand (or weekly by the scheduler once deployment/cron exists). Never touches `html-blocks/`, `appearance/` or anything outside `uploads/`.

## 6. The admin page «Медия»

- Navigation: group «Съдържание», label «Медия». Access: the content permission OR the catalog permission (exact names from the code); delete needs the same. Every Livewire action re-checks.
- A Filament **table in grid layout** (`contentGrid`): search, filters, pagination, bulk actions come from Filament (cheap to build, keyboard-accessible). 48 per page.
  - Card: the `thumbnail` variant (or a «обработва се» / «грешка» placeholder; video: an icon + name), original name (shortened), dimensions, a usage badge («3 места» / «неизползвана»).
  - Search: original name and alt text. Filters: вид (снимка / видео), използване (използвани / неизползвани), състояние (готова / обработва се / грешка), месец на качване. Sort: newest first, largest file, name.
- **Details** (slide-over on click): large preview (`medium` variant), original name, size, dimensions, type, uploaded by / when, status (+ failure reason), **alt text editable** (PlainText ≤ 200; saved through the domain repository), «Копирай адреса» (the `large` variant's URL), **«Къде се ползва»** list with links, «Изтрий» (disabled with «Използва се на N места» when used; confirmation otherwise).
- **Upload** on the page: drag-and-drop, several files at once (≤ 20 per batch), same validation as product photos except the minimum dimensions (those stay product-only); each file → asset `pending` → `ProcessMediaAssetJob` (video: `ready`, no job — media-domain-design §3.6). The grid shows new items with «обработва се» and refreshes (Livewire poll every 3 s only while something is pending).
- Compact density as the «Дизайн» screen (§6.1 there) where it fits the Filament table; no custom JS library.

## 7. The picker `MediaPicker` (stage ML-2)

- A Filament field component: the current picture (thumbnail) or an empty frame, buttons «Избери от медията», «Качи нова», «Махни». «Избери» opens a modal with the same grid, search and filters (only READY images, or PENDING marked «обработва се»; `multiple()` for gallery fields with a max). «Качи нова» uploads into the library and selects the new asset. The field state is the media id (or a list of ids).
- Uses, in order: the `media` field kind of the Design screen (`FieldForms`), HTML block pictures (replaces `FileUpload` + hidden id), brand logo. **Product and variation photos** (ML-3) respect the existing count guards, the one-video rule and the unique `(product_id, media_id)`; the product form's photo order and «главна снимка» stay as they are.
- **Pictures inside the HTML block text editor (owner: keep them, WordPress-style):** the editor's picture button is replaced by «Снимка» that opens the same picker (pick from the library or upload a new one into it), then asks for the size (среден / голям) and the alt text (pre-filled from the asset), and inserts `<img src="{variant url}" alt="…" width="…" height="…">`. The sanitizer already allows exactly `src`, `alt`, `width`, `height` and our own `/storage/…` addresses; nothing new is allowed. Filament's own `attachFiles` (loose files in `html-blocks/`) is removed from that editor.
- **Existing loose files** in `public/html-blocks/`: `php artisan media:import-html-block-files` (dry-run by default) makes each one an asset, runs the pipeline, rewrites the `src` in the bodies to the asset's `large` variant and leaves the old file until a second run with `--delete-old` (two steps, so nothing breaks if processing fails). Product descriptions and pages do not allow pictures (`DescriptionSanitizer`) and stay so.
- **Detaching** (×/«Махни» in any field, removing an `<img>` from a text) never deletes the asset; it only becomes «неизползвана» in the library (media-domain-design §8.5, unchanged).

## 8. Privacy: originals without EXIF (decided: option A)

`orient()` and the WebP re-encode strip metadata from the **variants** only; the **original** is stored as uploaded, on the public disk, with its EXIF — a phone photo carries the **GPS position** where it was taken, the phone model and the time. The storefront prints only variant URLs, but the original is reachable by its (unguessable, uuid) URL, which the admin shows (Filament previews, «Копирай адреса» must not). **Decided (owner): option A.** At upload, before the original is stored, its metadata is removed **losslessly** (no re-encode, no quality loss): JPEG — drop APP1 (EXIF, XMP) and APP13 (IPTC) segments, keep APP2 (ICC colour profile); PNG — drop `tEXt`/`zTXt`/`iTXt`/`eXIf` chunks; WebP — drop the `EXIF`/`XMP ` chunks and clear their flags in `VP8X`; GIF — drop comment/application extensions except NETSCAPE (animation loop). HEIC/AVIF originals are converted on the server only where a decoder exists; if they cannot be stripped safely, they are stored as the processed JPEG of the oriented image instead (the variants are WebP anyway). The orientation is applied to the variants by `orient()` as today; since stripping removes the orientation tag, the strip runs on the ORIENTED copy for JPEG when the tag is not 1 (that one case is re-encoded at quality 95). `php artisan media:strip-metadata` (dry-run by default) cleans the existing originals the same way and reports counts. Option B (private disk for originals) is not needed.

## 9. Security and speed

Upload: type by content (`finfo`), allow-list jpeg / png / webp / gif (+ avif / heic only where the server decodes them, as today), **never SVG** (script inside); size limits from `config('services.media.*')`; ≤ 20 files per batch; the original name stored as plain text and always escaped; the client never sends a path, only ids; ids validated as positive integers and existing. Delete and alt edit authorised per action. The admin list has a pinned query count; the storefront is untouched (no new query, no change in output).

## 10. Stages (each one commit, each useful alone)

| Stage | Content | Result for the owner |
|---|---|---|
| **ML-0 privacy** | Lossless metadata strip at upload for every upload path (`MediaStorageAdapter::store`), the `media:strip-metadata` command for existing originals. | No more GPS in uploaded photos. Small. First, because it is a privacy fix. |
| **ML-1a** | Migration of §2 + backfill command; usage registry with the five providers + hook; page «Медия» (grid, search, filters, details, alt edit, where-used); upload on the page. | Browse, search, see where a picture is used, fix alt texts, upload. Medium. |
| **ML-1b** | Delete (single + bulk) with the warning of §4, FK migration to RESTRICT, file-deletion job (original + all variants), sweep command. | Delete wrong uploads safely; clean abandoned files. Small–medium. |
| **ML-2** | `MediaPicker`; Design `media` kind; HTML block pictures; brand logo; the «Снимка» button of the HTML block editor through the library + `HtmlBlockBody` usage provider + `media:import-html-block-files`. | Upload or pick an existing picture anywhere outside products. Medium. |
| **ML-3** | Picker in product and variation photos: upload directly OR attach already uploaded ones (WordPress behaviour). | Reuse photos across products. Medium (count guards, order, main photo, one video). |

Then DS-4a (home page components) builds on ML-2.

## 11. Owner's answers (2026-10-24)

1. Metadata of originals: **removed** — "ненужни и опасни" → ML-D8, §8, stage ML-0.
2. Delete: **final, with a warning first**; deleting a picture deletes the original and **all its size variants** → ML-D9, §4.
3. Pictures in the HTML block text editor: **keep them, WordPress-style** — every picture place offers upload AND attaching an already uploaded picture, and the editor's picture button goes through the library → ML-D7, §7.
4. Who may delete: **anyone with access** to «Медия»; the activity log records who → ML-D9.

## ML-0 as built (metadata stripped from originals)

- `EasyCo\Media\Image\MetadataStripper::strip()` (infrastructure, next to `LaravelMediaImageProcessor`) finds the format by the first bytes and walks the file: JPEG drops APP1 (EXIF, XMP), APP13, COM and every APP segment except APP0, APP2 (ICC) and APP14 (Adobe); PNG drops tEXt, zTXt, iTXt, eXIf, tIME; WebP drops EXIF and XMP and clears their VP8X flags; GIF drops comments and every application extension except NETSCAPE2.0 / ANIMEXTS1.0. Lossless (the scan, IDAT, VP8 bytes are copied unchanged). A structure that cannot be walked comes back unchanged, flagged `malformed`.
- The one lossy case: an EXIF Orientation other than 1 in a JPEG or WebP: decoded, turned upright, encoded again at quality 95, then stripped. The tag is read by a small TIFF reader (both byte orders), not by `exif_read_data`; the JPEG turn itself uses `Image::orient()`, which needs the `exif` extension (installed here), the WebP turn applies the tag value itself.
- HEIC / AVIF and any other format the server can decode become a JPEG (quality 95, upright, `.jpg`); one it cannot decode is refused with `ImageNotCleanableException` (message `lang/{bg,en}/media.php`). Video and what is not a picture pass through untouched.
- `LaravelMediaStorageAdapter::store()` runs it; the extension follows what is stored. Admin forms store through `EasyCo\Media\Storage\MediaUpload::path()`, which turns the refusal into a field error; the API answers 422.
- `php artisan media:strip-metadata [--execute] [--force] [--html-blocks]`: dry-run by default; `--execute` verifies every result (decodes, same size, swapped for orientations 5 to 8) and writes to the SAME path. HEIC / AVIF originals already stored are reported, not converted (a stored file keeps its name).
- **Trailers (ML-0.1).** The picture ENDS at its end marker and nothing after it is kept: JPEG after the first real EOI (the scans are walked: `FF 00` stuffed bytes, restart markers `FF D0-D7` and the several scans of a progressive file are never cut; no EOI = malformed, unchanged), PNG after `IEND`, WebP beyond the RIFF size (unknown chunks INSIDE it stay), GIF after the `0x3B` byte. Phones append other pictures there (MPF: an HDR gain map or depth map, each a complete JPEG with its own EXIF and GPS) and a Motion Photo's video; a JPEG's APP2 stays only as an ICC profile, the `MPF ` segment and any other APP2 go (reported as `APP2-MPF` / `APP2-other`, the trailer as `TRAILER` with its byte count). A file with nothing but a trailer is a changed file.
- Not done: video metadata (MP4 / MOV can carry a location).

## ML-1a as built (the «Медия» page: browse, search, where used, alt text)

- **Refusal of a damaged picture.** `LaravelMediaStorageAdapter::store()` throws `ImageNotCleanableException::malformed()` («Файлът е повреден или непълен — качете го отново.») for an image whose structure the stripper could not walk (a truncated JPEG): it used to be stored unchanged WITH its metadata. The API answers 422 (the controller now catches the exception), the forms show a field error. The command `media:strip-metadata` still only skips such files.
- **Details columns** (migration `2026_10_25_000001`): `original_name`, `mime`, `size_bytes`, `width`, `height`, `uploaded_by` (no FK), and `missing_at`, all nullable; indexes `cm_type_created_idx (type, created_at)` and `cm_original_name_idx (original_name)`. Plain data in the domain: `EasyCo\Media\MediaDetails`, carried by `StoredFile::$details` and `MediaAsset::details()`. The facts are those of the STORED bytes (after the strip); the name is plain text (control and bidi characters removed, at most 200). The staff id is `auth('staff')->id()` read by the adapter, so no caller passes it. An admin form keeps only the PATH between the upload and the save, so the adapter also leaves the details in the cache for 48 hours (`UploadDetailsStash`, key by disk and path) and `EloquentMediaAssetRepository::save()` takes them when it inserts the row; nothing found = empty columns until the backfill.
- **`php artisan media:backfill-meta [--execute]`**: dry-run by default, chunked by id, fills only EMPTY columns from the original (mime by `finfo`), `original_name` = the stored file name; a file that is not on the disk gets `missing_at` (cleared when the file is back). Idempotent.
- **«Missing file» without a disk call per card:** the state of a card comes from the row (`MediaResource::stateOf`): `missing_at` set, or a READY image with an empty `variants` list (unless the picture itself is a size variant, as the demoted main photo of an archived product is). The backfill is what records `missing_at` for files that are really gone.
- **Usage registry** `App\Media\Usage`: `MediaUsageProvider` (`counts`, `usagesOf`, `excludeUsed`), `MediaUsage {kind, label, editUrl}`, `MediaUsageRegistry` = the five built-ins (`ProductPhotos`, `VariationPhotos`, `BrandLogo`, `HtmlBlockPictures`, `DesignZones`) ∪ the `media.usages` filter hook. `counts()` is one query (or one JSON read) per provider for the whole page. `DesignZones` reads the draft and published keys of the header, footer and cart/checkout header in one query and finds a `media_asset_id` / `media_id` anywhere in the JSON; today nothing stores one (DS-4 will). «Използвани» is the complement of `excludeUsed` (`MediaUsageRegistry::onlyUsed`), so no provider has a second implementation.
- **The page** is a Resource with the list page only (`MediaResource`, no create / edit / view / delete): Filament's table gives the grid (`contentGrid` on a `Stack` layout), search, filters, sort, pagination and the slide-over action for free. The sort is a filter (`baseQuery`, because Filament runs a filter's `query()` inside a nested WHERE group where an ORDER BY is lost). Access `content_manage` OR `product_manage`, re-checked inside the action and in `saveAltText()`.
- Not done in ML-1a (done in ML-1b below): upload on the page, delete.

## ML-1b as built (upload on the page, safe delete, DB guard, file sweep)

- **`App\Media\MediaDeleter::delete(id, staffId)`** is the ONLY way an asset is deleted: a transaction, `lockForUpdate()` on the `catalog_media` row, EVERY usage provider asked again (a fresh `MediaUsageRegistry` per call: its providers remember what they read), the row deleted through `MediaAssetRepository::delete()`. Outcomes: `deleted`, `in_use` (with the count), `not_found`. After the commit `DeleteMediaFilesJob` (package Media; plain disk, path and variant paths, `tries = 3`, backoff 10 s / 60 s) removes the original, every listed variant and any `{same-base}-{tier}.webp` left next to it by a failed processing (the directory is listed once); a missing file is fine, an empty path or one with `..` is refused. A row with `missing_at` set queues no job at all.
- **The database refuses too** (migrations `2026_10_26_000001` in Media, `2026_10_26_000002` in Catalog): `catalog_product_media.media_id` (`cpm_media_fk`), `catalog_variation_media.media_id` (`cvm_media_fk`) and `catalog_brands.logo_media_asset_id` (`cb_logo_media_fk`) are RESTRICT now (were CASCADE, CASCADE and SET NULL). The `product_id` / `variation_id` side keeps CASCADE: deleting a product or a variation still removes its pivot rows and leaves the pictures in the library, unused (tested). SQLSTATE 23000 with MySQL 1451 / SQLite 19 is turned into `in_use`, never matched by message.
- **Delete in the page.** Details: «Изтрий» (disabled with «Използва се на N места»; the confirmation names the original and N sizes and the real total in MB, or «Файлът вече липсва — ще бъде изтрит само записът»). Selected: our own bulk action «Изтрий избраните» (never Filament's `DeleteBulkAction`), the modal says how many are selected, used (skipped) and will go. **All of the filter:** the header action is enabled only while the filter says `use = unused`; the modal shows the exact count and the total of the originals matching NOW, the merchant types the count; then `DeleteUnusedMediaJob` gets the filter VALUES (`App\Media\MediaFilters`, the same fragments the table uses), runs them again in chunks of 500 by id and calls `MediaDeleter` for every row. Progress is the cache record `media.bulk-delete.{runId}` (`BulkDeleteRun`: total / done / skipped / finished) behind ONE cache lock, so only one run at a time; the page polls every 3 s only while a run is active (or a card on the page is still processing).
- **Activity log:** one `deleted` line per single delete (snapshot: original name, path, size), ONE summary line per bulk run or selection (counts and the filter in words), never one per row.
- **Upload** (`App\Media\MediaUploader`, header action «Качи»): up to 20 files, the kind decided by `finfo` from a short list (JPEG, PNG, WebP, GIF, AVIF, HEIC; MP4, WebM, MOV), never SVG; the limits of `config('services.media.*')`, NO minimum dimensions. Each file goes `MediaStorageAdapter::store()` (the ML-0 strip and the ML-1a refusal) → `MediaAsset` with its details → `ProcessMediaAssetJob` for an image only. A refused file is named in the notification with the reason; the others are kept.
- **`php artisan media:sweep-files [--delete]`:** dry-run by default; files under `uploads/` older than 24 hours that no row (original or listed variant) refers to, and that are not a `{base}-{tier}.webp` of a live original; count and total size; never outside `uploads/`; the rows are read in chunks and only their paths are kept.
- The «Изтриване — скоро» note of ML-1a is gone (its test assertion is inverted).
