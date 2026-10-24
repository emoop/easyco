<?php

namespace App\Livewire;

use App\Filament\Resources\MediaResource;
use App\Media\MediaAltText;
use App\Media\MediaFilters;
use App\Media\MediaPickerAccess;
use App\Media\MediaUploader;
use App\Rules\MediaAssetId;
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

    /** The state path of the field that opened the modal; the event names it, so only that field listens. */
    #[Locked]
    public string $field = '';

    #[Locked]
    public bool $multiple = false;

    #[Locked]
    public int $max = 1;

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

    public function mount(string $field = '', bool $multiple = false, int $max = 1, bool $withSize = false, string $tab = 'library'): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        $this->field = $field;
        $this->multiple = $multiple;
        $this->max = $multiple ? max(1, $max) : 1;
        $this->withSize = $withSize;
        $this->tab = $tab === 'upload' ? 'upload' : 'library';
    }

    // ---- the library -------------------------------------------------------------------------------------------------------------

    /** The pictures the merchant may pick: images that are ready (with their sizes) or still processing, never a missing or failed one. */
    private function query(): Builder
    {
        $query = MediaAssetModel::query()
            ->whereIn('catalog_media.type', MediaAssetId::TYPES)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $r) => MediaFilters::state($r, 'ready'))
                ->orWhere(fn (Builder $r) => MediaFilters::state($r, 'processing')));

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

        $this->limit += self::PER_PAGE;
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

        $result = app(MediaUploader::class)->upload($files, imagesOnly: true);
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

        $check = Validator::make(['ids' => $this->multiple ? $ids : $ids[0]], ['ids' => [new MediaAssetId($this->multiple ? $this->max : null)]]);

        if ($check->fails()) {
            throw ValidationException::withMessages(['selected' => $check->errors()->first('ids')]);
        }

        // the alt text is saved with the asset, for a single pick (the narrow column belongs to ONE picture)
        if (! $this->multiple) {
            MediaAltText::save($ids[0], $this->alt, 'alt');
        }

        $this->dispatch('media-picked', field: $this->field, ids: $ids, size: $this->size === 'medium' ? 'medium' : 'large');
    }

    public function render(): View
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        $rows = $this->query()->limit($this->limit + 1)->get();
        $hasMore = $rows->count() > $this->limit;
        $rows = $rows->take($this->limit);

        $current = $this->selected === [] ? null : MediaAssetModel::query()->find((int) end($this->selected));

        return view('livewire.media-picker-browser', [
            'rows' => $rows,
            'hasMore' => $hasMore,
            'current' => $current,
            'currentPreview' => $current === null ? null : MediaResource::previewUrl($current, 'medium'),
            'maxFiles' => MediaUploader::MAX_FILES,
            'accept' => implode(',', array_filter(MediaUploader::acceptedMimes(), fn (string $mime): bool => str_starts_with($mime, 'image/'))),
        ]);
    }
}
