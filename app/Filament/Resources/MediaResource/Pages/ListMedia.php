<?php

namespace App\Filament\Resources\MediaResource\Pages;

use App\Filament\Resources\MediaResource;
use App\Media\BulkDeleteRun;
use App\Media\DeleteUnusedMediaJob;
use App\Media\MediaFilters;
use App\Media\MediaUploader;
use App\Media\Usage\MediaUsageRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The «Медия» grid. Besides the table it keeps ONE batched usage count for the page of cards it shows: the registry is asked once per provider for all the ids of the page (never once per
 * card), and the card view reads the result through usageCount(). The registry and the counts are plain properties of the request, never Livewire state.
 *
 * Header actions (ML-1b): «Качи» (several files at once, MediaUploader) and «Изтрий всички неизползвани от този филтър» (enabled only while the filter says «неизползвани»; the merchant types the
 * exact count; the work is a queued job with the filter VALUES, and the page polls its progress every 3 s only while it runs).
 */
class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    protected string $view = 'filament.pages.media.list';

    /** @var array<int, int>|null id => uses, for the ids of the page being shown */
    protected ?array $usageCounts = null;

    /** The «delete all of this filter» run this page is watching ('' = none). */
    #[Locked]
    public string $bulkRunId = '';

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

    /** Does any card of THIS page still wait for its variants (the grid then polls)? Reads the page already loaded: no query of its own. */
    public function somethingIsProcessing(): bool
    {
        $records = $this->getTableRecords();
        $items = $records instanceof Collection ? $records : collect($records->items());

        return $items->contains(fn ($record): bool => in_array($record->processing_status, ['pending', 'processing'], true) && $record->missing_at === null);
    }

    // ---- the delete-all run ------------------------------------------------------------------------------------------------------

    /** The filter values the table has now, flat: `kind`, `use`, `state`, `month`. @return array<string, string> */
    public function currentFilters(): array
    {
        $filters = [];

        foreach (['kind', 'use', 'state'] as $key) {
            $value = $this->tableFilters[$key]['value'] ?? null;
            is_string($value) && $value !== '' && $filters[$key] = $value;
        }

        $month = $this->tableFilters['month']['month'] ?? null;
        is_string($month) && $month !== '' && $filters['month'] = $month;

        return $filters;
    }

    /** @return array{0: int, 1: int} how many rows the filter matches NOW, and the bytes of their originals */
    public function matchNow(): array
    {
        $query = MediaFilters::query($this->currentFilters());

        return [(int) (clone $query)->count(), (int) (clone $query)->sum('size_bytes')];
    }

    public function filterIsUnused(): bool
    {
        return ($this->currentFilters()['use'] ?? null) === 'unused';
    }

    /** @return array{total: int, done: int, skipped: int, finished: bool, error: ?string}|null */
    public function progress(): ?array
    {
        return $this->bulkRunId === '' ? null : BulkDeleteRun::progress($this->bulkRunId);
    }

    /** The poll: reads the cache, says it once when the run ends. */
    public function refreshBulkProgress(): void
    {
        $progress = $this->progress();

        if ($progress === null || ! $progress['finished']) {
            return;
        }

        $this->bulkRunId = '';

        $progress['error'] !== null
            ? Notification::make()->title(__('media.library.delete.failed', ['error' => $progress['error']]))->danger()->send()
            : Notification::make()->title(__('media.library.delete.finished', ['done' => $progress['done'], 'skipped' => $progress['skipped']]))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [$this->uploadAction(), $this->deleteAllAction()];
    }

    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('media.library.upload.button'))
            ->icon('heroicon-o-arrow-up-tray')
            ->authorize(fn (): bool => MediaResource::staffMayUse())
            ->modalHeading(__('media.library.upload.heading'))
            ->modalSubmitActionLabel(__('media.library.upload.button'))
            ->schema([
                FileUpload::make('files')->label(__('media.library.upload.files'))->helperText(__('media.library.upload.help', ['max' => MediaUploader::MAX_FILES]))
                    ->multiple()->maxFiles(MediaUploader::MAX_FILES)->storeFiles(false)->required()
                    ->acceptedFileTypes(MediaUploader::acceptedMimes())
                    ->maxSize((int) config('services.media.max_video_size_kb', 204800)),
            ])
            ->action(function (array $data): void {
                abort_unless(MediaResource::staffMayUse(), 403);

                $files = collect($data['files'] ?? [])->filter()->take(MediaUploader::MAX_FILES)->map(fn ($file): array => ['name' => (string) $file->getClientOriginalName(), 'content' => (string) $file->get()])->values()->all();

                if ($files === []) {
                    Notification::make()->title(__('media.library.upload.empty'))->warning()->send();

                    return;
                }

                $result = app(MediaUploader::class)->upload($files);

                $result['stored'] !== [] && Notification::make()->title(__('media.library.upload.done', ['count' => count($result['stored'])]))->success()->send();

                $result['refused'] !== [] && Notification::make()->title(__('media.library.upload.refused', ['names' => implode('; ', array_map(fn (array $r): string => $r['name'].' ('.$r['reason'].')', $result['refused']))]))->danger()->persistent()->send();
            });
    }

    private function deleteAllAction(): Action
    {
        return Action::make('deleteAllUnused')
            ->label(__('media.library.delete.all'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize(fn (): bool => MediaResource::staffMayUse())
            ->disabled(fn (): bool => ! $this->filterIsUnused())
            ->tooltip(fn (): ?string => $this->filterIsUnused() ? null : __('media.library.delete.all_disabled'))
            ->modalHeading(__('media.library.delete.all_heading'))
            ->modalSubmitActionLabel(__('media.library.delete.all_submit'))
            ->modalDescription(function (): string {
                [$count, $bytes] = $this->matchNow();

                return __('media.library.delete.all_summary', ['count' => $count, 'size' => MediaResource::humanSize($bytes)]);
            })
            ->schema(function (): array {
                [$count] = $this->matchNow();

                return [TextInput::make('confirm')->label(__('media.library.delete.all_confirm', ['count' => $count]))->required()->autocomplete(false)];
            })
            ->action(function (array $data): void {
                abort_unless(MediaResource::staffMayUse(), 403);
                abort_unless($this->filterIsUnused(), 403);

                [$count] = $this->matchNow();

                if ($count === 0) {
                    Notification::make()->title(__('media.library.delete.all_nothing'))->warning()->send();

                    return;
                }

                if (trim((string) ($data['confirm'] ?? '')) !== (string) $count) {
                    throw ValidationException::withMessages(['mountedActions.0.data.confirm' => __('media.library.delete.all_wrong')]);
                }

                $runId = BulkDeleteRun::start($this->currentFilters(), $count, MediaResource::staffId());

                if ($runId === null) {
                    Notification::make()->title(__('media.library.delete.all_running'))->warning()->send();

                    return;
                }

                $this->bulkRunId = $runId;
                DeleteUnusedMediaJob::dispatch($runId, $this->currentFilters(), MediaResource::staffId());
                Notification::make()->title(__('media.library.delete.all_started'))->success()->send();
            });
    }
}
