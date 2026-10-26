<?php

namespace App\Livewire;

use App\Filament\Resources\MediaResource;
use App\Media\MediaAltText;
use App\Media\MediaFilters;
use App\Media\MediaPickerAccess;
use App\Media\MediaUploader;
use App\Rules\MediaAssetId;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The modal of the media picker (ML-2, media-library-design.md §7): ONE Livewire component for every picture field. Library tab: a search, one toggle «Само неизползвани», a grid of images
 * that are READY or still being processed (24 at a time, «Още»), a click selects, a second click unselects, a double click selects and confirms; the selected picture's alt text is edited in a
 * narrow column and saved when «Избери» is pressed. Upload tab: the same MediaUploader as the «Медия» page; the new pictures come back SELECTED on the library tab.
 *
 * Nothing the browser sends is trusted: the field name, the limit and the mode are locked properties; every action checks MediaPickerAccess again; the chosen ids are validated by the
 * MediaAssetId rule (positive, existing, a picture, at most `max`) before the `media-picked` event leaves; the alt text goes through MediaAltText (plain text, at most 200).
 */
class MediaPickerBrowser extends Component
{
    use WithFileUploads;

    public const PER_PAGE = 24;

    /** The most cards one request may load: `$limit` is a public property, so the browser could send any number (ML-3). */
    public const MAX_LIMIT = 480;

    /** The state path of the field that opened the modal; the event names it, so only that field listens. */
    #[Locked]
    public string $field = '';

    #[Locked]
    public bool $multiple = false;

    #[Locked]
    public int $max = 1;

    /** `image` | `video` (ML-3: the product video field lists videos only) */
    #[Locked]
    public string $kind = 'image';

    /** LG-1: only these MIME types (the logo: PNG and WebP); null = every picture type. The type is the stored `catalog_media.mime`, never the file name. @var list<string>|null */
    #[Locked]
    public ?array $mimes = null;

    /** ML-3 review: `image` assets only (not `social_image`), as `plainImagesOnly()` says on the field */
    #[Locked]
    public bool $plain = false;

    /** ML-3, product photos: the smallest picture the field takes (null = no minimum) */
    #[Locked]
    public ?int $minWidth = null;

    #[Locked]
    public ?int $minHeight = null;

    /** The text editor's tool also asks for the size of the picture in the text. */
    #[Locked]
    public bool $withSize = false;

    /** `library` | `upload` */
    public string $tab = 'library';

    public string $search = '';

    public bool $onlyUnused = false;

    public int $limit = self::PER_PAGE;

    /** @var list<int> the selected ids */
    public array $selected = [];

    public ?string $alt = '';

    /** `medium` | `large`: the size of a picture put into a text */
    public string $size = 'large';

    /** @var array<int, mixed> the files of the upload tab */
    public array $files = [];

    /** @var list<array{name: string, reason: string}> */
    public array $refused = [];

    public function mount(string $field = '', bool $multiple = false, int $max = 1, bool $withSize = false, string $tab = 'library', string $kind = 'image', ?int $minWidth = null, ?int $minHeight = null, ?array $mimes = null, bool $plain = false): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        $this->field = $field;
        $this->multiple = $multiple;
        $this->max = $multiple ? max(1, $max) : 1;
        $this->withSize = $withSize;
        $this->kind = $kind === 'video' ? 'video' : 'image';
        $this->minWidth = $this->kind === 'image' ? $minWidth : null;
        $this->minHeight = $this->kind === 'image' ? $minHeight : null;
        $this->mimes = $this->kind === 'image' && $mimes !== null ? array_values(array_filter(array_map('strval', $mimes), static fn (string $mime): bool => preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $mime) === 1)) : null;
        $this->plain = $this->kind === 'image' && $plain;
        $this->tab = $tab === 'upload' ? 'upload' : 'library';
    }

    // ---- the library -------------------------------------------------------------------------------------------------------------

    /** The rows the merchant may pick: images that are ready (with their sizes) or still processing, never a missing or failed one; in video mode the ready videos. */
    private function query(): Builder
    {
        $query = $this->kind === 'video'
            ? MediaAssetModel::query()->whereIn('catalog_media.type', MediaAssetId::VIDEO_TYPES)->where(fn (Builder $r) => MediaFilters::state($r, 'ready'))
            : MediaAssetModel::query()
                ->whereIn('catalog_media.type', $this->plain ? ['image'] : MediaAssetId::TYPES)
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $r) => MediaFilters::state($r, 'ready'))
                    ->orWhere(fn (Builder $r) => MediaFilters::state($r, 'processing')));

        // LG-1: only the allowed types; a row whose type is not known yet (not backfilled) stays listed (marked, and refused on «Избери»)
        if ($this->mimes !== null) {
            $query->where(fn (Builder $q) => $q->whereIn('catalog_media.mime', $this->mimes)->orWhereNull('catalog_media.mime'));
        }

        // ML-3: only pictures that are big enough; one with no stored size yet stays listed (marked, and refused on «Избери» until the backfill)
        if ($this->minWidth !== null || $this->minHeight !== null) {
            $query->where(fn (Builder $q) => $q
                ->whereNull('catalog_media.width')->orWhereNull('catalog_media.height')
                ->orWhere(fn (Builder $b) => $b->where('catalog_media.width', '>=', (int) $this->minWidth)->where('catalog_media.height', '>=', (int) $this->minHeight)));
        }

        $term = trim($this->search);

        if ($term !== '') {
            $like = '%'.addcslashes(mb_substr($term, 0, 100), '%_'.chr(92)).'%';
            $query->where(fn (Builder $q) => $q->where('catalog_media.original_name', 'like', $like)->orWhere('catalog_media.alt_text', 'like', $like));
        }

        $this->onlyUnused && MediaFilters::use($query, 'unused');

        return $query->orderByDesc('catalog_media.created_at')->orderByDesc('catalog_media.id');
    }

    public function toggle(int $id): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));
        } elseif ($this->multiple) {
            count($this->selected) < $this->max && $this->selected[] = $id;
        } else {
            $this->selected = [$id];
        }

        $this->loadAlt();
    }

    /** A double click: select this one and finish. */
    public function choose(int $id): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        if (! in_array($id, $this->selected, true)) {
            $this->toggle($id);
        }

        $this->confirm();
    }

    public function more(): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        $this->limit = min($this->limit + self::PER_PAGE, self::MAX_LIMIT);
    }

    /** The property is public (a browser can set it): whatever arrives is brought back into [PER_PAGE, MAX_LIMIT]. */
    public function updatedLimit(): void
    {
        $this->limit = max(self::PER_PAGE, min((int) $this->limit, self::MAX_LIMIT));
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PER_PAGE;
    }

    public function updatedOnlyUnused(): void
    {
        $this->limit = self::PER_PAGE;
    }

    public function showTab(string $tab): void
    {
        $this->tab = $tab === 'upload' ? 'upload' : 'library';
    }

    /** The alt of the picture in the narrow column: the last selected one. */
    private function loadAlt(): void
    {
        $id = $this->selected === [] ? null : (int) end($this->selected);
        $this->alt = $id === null ? '' : (string) MediaAssetModel::query()->whereKey($id)->value('alt_text');
    }

    // ---- upload ------------------------------------------------------------------------------------------------------------------

    public function updatedFiles(): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        $files = collect($this->files)->filter()->take(MediaUploader::MAX_FILES)->map(fn ($file): array => ['name' => (string) $file->getClientOriginalName(), 'content' => (string) $file->get()])->values()->all();
        $this->files = [];

        if ($files === []) {
            return;
        }

        $result = app(MediaUploader::class)->upload($files, imagesOnly: $this->kind === 'image', videosOnly: $this->kind === 'video', minWidth: $this->minWidth, minHeight: $this->minHeight, mimes: $this->mimes);
        $this->refused = $result['refused'];

        if ($result['stored'] !== []) {
            $this->selected = $this->multiple ? array_slice($result['stored'], 0, $this->max) : [$result['stored'][0]];
            $this->loadAlt();
            $this->search = '';
            $this->onlyUnused = false;
            $this->limit = self::PER_PAGE;
            $this->tab = 'library';
        }
    }

    // ---- finish ------------------------------------------------------------------------------------------------------------------

    public function confirm(): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        $ids = array_values(array_map('intval', $this->selected));

        if ($ids === []) {
            return;
        }

        $check = Validator::make(['ids' => $this->multiple ? $ids : $ids[0]], ['ids' => [new MediaAssetId($this->multiple ? $this->max : null, $this->kind === 'video' ? MediaAssetId::VIDEO_TYPES : ($this->plain ? ['image'] : MediaAssetId::TYPES), $this->minWidth, $this->minHeight, [], $this->mimes)]]);

        if ($check->fails()) {
            throw ValidationException::withMessages(['selected' => $check->errors()->first('ids')]);
        }

        // the alt text is saved with the asset, for a single pick (the narrow column belongs to ONE picture)
        if (! $this->multiple && $this->kind !== 'video') {
            MediaAltText::save($ids[0], $this->alt, 'alt');
        }

        $this->dispatch('media-picked', field: $this->field, ids: $ids, size: $this->size === 'medium' ? 'medium' : 'large');
    }

    public function render(): View
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        // `limit` comes from the browser: clamped here, whatever it holds (ML-3)
        $limit = max(self::PER_PAGE, min($this->limit, self::MAX_LIMIT));
        $rows = $this->query()->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $current = $this->selected === [] ? null : MediaAssetModel::query()->find((int) end($this->selected));

        return view('livewire.media-picker-browser', [
            'rows' => $rows,
            'hasMore' => $hasMore,
            'current' => $current,
            'currentPreview' => $current === null || $this->kind === 'video' ? null : MediaResource::previewUrl($current, 'medium'),
            'currentVideoUrl' => $current === null || $this->kind !== 'video' ? null : app(MediaStorageAdapter::class)->url((string) $current->disk, (string) $current->path),
            'maxFiles' => MediaUploader::MAX_FILES,
            'accept' => implode(',', array_filter(MediaUploader::acceptedMimes(), fn (string $mime): bool => str_starts_with($mime, $this->kind === 'video' ? 'video/' : 'image/') && ($this->mimes === null || in_array($mime, $this->mimes, true)))),
        ]);
    }
}
