<?php

namespace App\Filament\Forms\Components;

use App\Media\MediaPickerAccess;
use App\Rules\MediaAssetId;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Filament\Actions\Action;
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

    protected function setUp(): void
    {
        parent::setUp();

        // the thumbnail beside the buttons is drawn from the state, so a change is shown at once
        $this->live();
        $this->rule(fn (): MediaAssetId => new MediaAssetId($this->maxItems));
        $this->dehydrateStateUsing(fn (mixed $state): mixed => $this->maxItems === null
            ? (filled($state) ? (int) $state : null)
            : array_values(array_map('intval', array_filter(is_array($state) ? $state : [], fn ($id): bool => filled($id)))));
        $this->registerActions([$this->openAction('pick', 'library'), $this->openAction('upload', 'upload')]);
    }

    /** A list of at most `$max` pictures instead of one (built for the product gallery of ML-3). */
    public function multiple(int $max = 10): static
    {
        $this->maxItems = max(1, $max);
        $this->default([]);

        return $this;
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
            ->label(fn (MediaPicker $component): string => __($tab === 'upload'
                ? ($component->isMultiple() ? 'media.picker.upload_many' : 'media.picker.upload')
                : ($component->isMultiple() ? 'media.picker.add' : ($component->getIds() === [] ? 'media.picker.pick' : 'media.picker.change'))))
            ->color($tab === 'upload' ? 'gray' : 'primary')
            ->size('sm')
            ->outlined(fn (MediaPicker $component): bool => $tab === 'upload' || $component->getIds() !== [])
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
            ]))
            ->action(fn () => null);
    }
}
