<?php

namespace App\Filament\Forms\Components;

use App\Media\MediaPickerAccess;
use App\Rules\MediaAssetId;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Filament\Actions\Action;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Support\Enums\Width;

/**
 * The media picker field (ML-2, media-library-design.md ML-D5 / ML-D7 / §7): «Избери от медията» and «Качи нова» in EVERY place that shows a picture, «Махни» only detaches (the file stays in
 * «Медия»). The state is a media id (or `null`); `multiple($max)` makes it a list of ids. Both buttons open the same modal (App\Livewire\MediaPickerBrowser) as a Filament action; the modal sends
 * back `media-picked` and the modal wrapper puts the ids into the state. The server checks the state again on every save (MediaAssetId: positive, existing, a picture, at most `max`): the
 * browser's word is never taken for it.
 */
class MediaPicker extends Field
{
    protected string $view = 'filament.forms.components.media-picker';

    protected ?int $maxItems = null;

    /** `image` | `video` (ML-3: the product video field) */
    protected string $kind = 'image';

    /** ML-3: product photos are `image` assets only (a `social_image` is not a product photo) */
    protected bool $plainImagesOnly = false;

    protected ?int $minWidth = null;

    protected ?int $minHeight = null;

    /** @var Closure|array<int, int|string>|null ids the record already has: not checked against the minimum size */
    protected Closure|array|null $exemptIds = null;

    protected function setUp(): void
    {
        parent::setUp();

        // the thumbnail beside the buttons is drawn from the state, so a change is shown at once
        $this->live();
        // the closure takes the field as `$component`: inside a repeater the field is cloned per row, and `$this` would be the original (its `Get` would read the wrong row)
        $this->rule(fn (MediaPicker $component): MediaAssetId => new MediaAssetId(
            $component->maxItems,
            $component->kind === 'video' ? MediaAssetId::VIDEO_TYPES : ($component->plainImagesOnly ? ['image'] : MediaAssetId::TYPES),
            $component->minWidth,
            $component->minHeight,
            array_values((array) $component->evaluate($component->exemptIds ?? [])),
        ));
        $this->dehydrateStateUsing(fn (mixed $state): mixed => $this->maxItems === null
            ? (filled($state) ? (int) $state : null)
            : array_values(array_map('intval', array_filter(is_array($state) ? $state : [], fn ($id): bool => filled($id)))));
        $this->registerActions([$this->openAction('pick', 'library')]);
    }

    /** A list of at most `$max` pictures instead of one (built for the product gallery of ML-3). */
    public function multiple(int $max = 10): static
    {
        $this->maxItems = max(1, $max);
        $this->default([]);

        return $this;
    }

    /**
     * ML-3: only pictures of at least this size (default: `config('services.media.min_image_dimension_px')`, the same number the API checks). The library lists the pictures that fit (and the ones
     * with no stored size yet, marked, refused on «Избери»), the upload tab refuses a smaller file, and the save checks the ids again.
     */
    public function minDimensions(?int $width = null, ?int $height = null): static
    {
        $min = (int) config('services.media.min_image_dimension_px', 600);
        $this->minWidth = $width ?? $min;
        $this->minHeight = $height ?? $min;

        return $this;
    }

    /** ids the minimum size is NOT checked for (the pictures the record already has); a closure may take `$record`. */
    public function exemptFromMinimum(Closure|array $ids): static
    {
        $this->exemptIds = $ids;

        return $this;
    }

    /** ML-3: the save accepts `image` assets only, not `social_image`. */
    public function plainImagesOnly(bool $only = true): static
    {
        $this->plainImagesOnly = $only;

        return $this;
    }

    /** ML-3: a single VIDEO instead of a picture (the library lists videos, the upload tab takes the video types and limit). */
    public function videos(): static
    {
        $this->kind = 'video';

        return $this;
    }

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }

    public function isMultiple(): bool
    {
        return $this->maxItems !== null;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems ?? 1;
    }

    /** @return list<int> the ids in the state, in order */
    public function getIds(): array
    {
        $state = $this->getState();
        $ids = is_array($state) ? $state : [$state];

        return array_values(array_map('intval', array_filter($ids, fn ($id): bool => is_numeric($id) && (int) $id > 0)));
    }

    /** @return array<int, MediaAssetModel> the assets of the state by id (one query) */
    public function getAssets(): array
    {
        $ids = $this->getIds();

        return $ids === [] ? [] : MediaAssetModel::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    private function openAction(string $name, string $tab): Action
    {
        // the closures take the field as `$component`: an action is built before the field is in its schema, so `$this` has no state to read yet
        return Action::make($name)
            // ONE button per field (the modal itself offers the library and the upload tab): «Добави изображение(я)» / «Добави видео» when there is nothing, «Смени» for a filled single field
            ->label(fn (MediaPicker $component): string => __($component->isMultiple()
                ? 'media.picker.add_images'
                : ($component->getIds() === [] ? ($component->isVideo() ? 'media.picker.add_video' : 'media.picker.add_image') : 'media.picker.change')))
            ->color('primary')
            ->size('sm')
            ->outlined(fn (MediaPicker $component): bool => ! $component->isMultiple() && $component->getIds() !== [])
            ->authorize(fn (): bool => MediaPickerAccess::allowed())
            ->modalHeading(__('media.picker.title'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->closeModalByClickingAway(false)
            ->modalContent(fn (MediaPicker $component): \Illuminate\Contracts\View\View => view('filament.forms.components.media-picker-modal', [
                'field' => $component->getStatePath(),
                'multiple' => $component->isMultiple(),
                'max' => $component->getMaxItems(),
                'tab' => $tab,
                'kind' => $component->kind,
                'minWidth' => $component->minWidth,
                'minHeight' => $component->minHeight,
            ]))
            ->action(fn () => null);
    }
}
