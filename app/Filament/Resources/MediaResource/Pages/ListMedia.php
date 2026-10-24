<?php

namespace App\Filament\Resources\MediaResource\Pages;

use App\Filament\Resources\MediaResource;
use App\Media\Usage\MediaUsageRegistry;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * The «Медия» grid. Besides the table it keeps ONE batched usage count for the page of cards it shows: the registry is asked once per provider for all the ids of the page (never once per
 * card), and the card view reads the result through usageCount(). The registry and the counts are plain properties of the request, never Livewire state.
 */
class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    protected string $view = 'filament.pages.media.list';

    /** @var array<int, int>|null id => uses, for the ids of the page being shown */
    protected ?array $usageCounts = null;

    public function getTitle(): string|Htmlable
    {
        return __('media.library.title');
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        abort_unless(MediaResource::staffMayUse(), 403);

        $records = parent::getTableRecords();

        if ($this->usageCounts === null) {
            $ids = collect($records instanceof Collection ? $records->all() : $records->items())->map(fn ($record): int => (int) $record->getKey())->all();
            $this->usageCounts = $ids === [] ? [] : (new MediaUsageRegistry)->counts($ids);
        }

        return $records;
    }

    /** How many places use the picture (0 = unused); read from the batch, never a query of its own. */
    public function usageCount(int $id): int
    {
        return $this->usageCounts[$id] ?? 0;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
