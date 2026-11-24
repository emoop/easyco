<?php

namespace App\Livewire;

use App\Media\MediaPickerAccess;
use App\Media\ProcessingWindow;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * MEDIA-UP-3: the little watcher that makes a picture FIELD in a form (`.mpf`) show a picture the queue has just finished, without a click. The field lives inside the page's own Livewire component, and
 * a `wire:poll` there would re-render the WHOLE page (the form) every 2 s. So the field mounts this tiny component of its own, ONLY while one of its pictures waits (ProcessingWindow): the poll belongs to
 * the watcher and touches nothing of the page's state. When no picture waits any more it says so ONCE with a browser event (`media-field-settled`, naming the field); the field's Alpine answers with
 * one `$wire.$refresh()` of the page, the same render any click would cause, and the watcher is then removed. Nothing is ever written.
 */
class MediaFieldWatcher extends Component
{
    /** The state path of the field (the event names it, so only that field answers). */
    #[Locked]
    public string $field = '';

    /** @var list<int> the picture ids the field shows */
    #[Locked]
    public array $ids = [];

    /** Nothing of the field waits any more (and the page has been told). */
    public bool $settled = false;

    /** @param  array<int, mixed>  $ids */
    public function mount(string $field, array $ids): void
    {
        $this->field = $field;
        $this->ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $this->settle();
    }

    /** The poll: look again at the pictures (one query), and tell the page once when none waits. */
    public function check(): void
    {
        $this->settle();
    }

    private function settle(): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        if ($this->settled) {
            return;
        }

        $waiting = $this->ids !== [] && MediaAssetModel::query()->whereIn('id', $this->ids)->get()->contains(fn (MediaAssetModel $row): bool => ProcessingWindow::waiting($row));

        if (! $waiting) {
            $this->settled = true;
            $this->dispatch('media-field-settled', field: $this->field);
        }
    }

    public function render(): View
    {
        return view('livewire.media-field-watcher');
    }
}
