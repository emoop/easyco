<?php

namespace App\Filament\Resources;

use App\Filament\NavigationGroup;
use App\Filament\Resources\MediaResource\Pages\ListMedia;
use App\Media\DeleteOutcome;
use App\Media\MediaAltText;
use App\Media\MediaDeleter;
use App\Media\MediaFilters;
use App\Media\Usage\MediaUsageRegistry;
use App\Rules\PlainText;
use App\Services\ActivityLogger;
use App\Services\AuthenticatedStaffResolver;
use BackedEnum;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;

/**
 * «Медия» (ML-1a, media-library-design.md §6): every uploaded picture and video in one grid: search by file name and alt text, filters (kind, use, state, month), sort, a details slide-over
 * with the alt text to fix and «Къде се ползва». A RESOURCE with the list page only (no create / edit / view pages, no delete: ML-1b), because Filament's table gives the grid, search,
 * filters, sort and pagination with keyboard access and a Livewire action layer for free; a Page would rebuild all of it. Access: `content_manage` OR `product_manage`; every action re-checks.
 */
class MediaResource extends Resource
{
    public const PER_PAGE = 48;

    protected static ?string $model = MediaAssetModel::class;

    protected static ?string $slug = 'media';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    public static function getNavigationLabel(): string
    {
        return __('media.library.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('media.library.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('media.library.plural');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CONTENT;
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    // ---- access ------------------------------------------------------------------------------------------------------------------

    /** The content permission OR the catalog one; a fresh resolver lookup (memoized per request), so a permission taken away stops the very next action. */
    public static function staffMayUse(): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof StaffModel) {
            return false;
        }

        $staff = app(AuthenticatedStaffResolver::class)->resolveById((string) $user->getAuthIdentifier());

        return $staff !== null && ($staff->can(Permission::CONTENT_MANAGE) || $staff->can(Permission::PRODUCT_MANAGE));
    }

    public static function canViewAny(): bool
    {
        return static::staffMayUse();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    // ---- the grid ----------------------------------------------------------------------------------------------------------------

    /** The state of a card, from the row alone (no disk call): `ready` | `processing` | `failed` | `missing` | `video`. */
    public static function stateOf(MediaAssetModel $asset): string
    {
        if (in_array($asset->type, ['video', 'social_video'], true)) {
            return $asset->missing_at !== null ? 'missing' : 'video';
        }

        return match (true) {
            $asset->missing_at !== null => 'missing',
            in_array($asset->processing_status, ['pending', 'processing'], true) => 'processing',
            $asset->processing_status === 'failed' => 'failed',
            self::variantPath($asset, 'thumbnail') === null && ! self::isVariantFile($asset) => 'missing',
            default => 'ready',
        };
    }

    /** The path of one variant of the asset (`thumbnail`, `medium`, `large`), or null. */
    public static function variantPath(MediaAssetModel $asset, string $tier): ?string
    {
        foreach ((array) $asset->variants as $variant) {
            if (($variant['tier'] ?? null) === $tier && is_string($variant['path'] ?? null)) {
                return $variant['path'];
            }
        }

        return null;
    }

    /** A picture that IS a size variant (the main photo of an archived product is demoted to its own thumbnail file): shown as it is. */
    private static function isVariantFile(MediaAssetModel $asset): bool
    {
        return $asset->processing_status === 'ready' && preg_match('/-(thumbnail|medium|large|admin_grid)\.webp$/', (string) $asset->path) === 1;
    }

    /** The address of the preview of a card or of the details (never the original): a variant of the asked tier, else the thumbnail, else a variant file's own path. */
    public static function previewUrl(MediaAssetModel $asset, string $tier): ?string
    {
        if (self::stateOf($asset) !== 'ready') {
            return null;
        }

        $path = self::variantPath($asset, $tier) ?? self::variantPath($asset, 'thumbnail') ?? (self::isVariantFile($asset) ? $asset->path : null);

        return $path === null ? null : app(MediaStorageAdapter::class)->url((string) $asset->disk, $path);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Default)
            ->defaultSort('created_at', 'desc')
            ->paginated([self::PER_PAGE])
            ->defaultPaginationPageOption(self::PER_PAGE)
            ->extremePaginationLinks()
            ->contentGrid(['default' => 2, 'sm' => 3, 'md' => 4, 'xl' => 6, '2xl' => 8])
            ->columns([
                // a layout (Stack) is what turns Filament's table into the card grid
                Stack::make([
                    ViewColumn::make('card')->label(__('media.library.card'))->view('filament.pages.media.card')
                        ->viewData(fn (ListMedia $livewire, MediaAssetModel $record): array => ['uses' => $livewire->usageCount((int) $record->id)])
                        ->searchable(['original_name', 'alt_text']),
                ]),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('media.library.filters.kind'))->options(['image' => __('media.library.kind.image'), 'video' => __('media.library.kind.video')])
                    ->query(fn (Builder $query, array $data): Builder => MediaFilters::kind($query, $data['value'] ?? null)),
                SelectFilter::make('use')->label(__('media.library.filters.use'))->options(['used' => __('media.library.use.used'), 'unused' => __('media.library.use.unused')])
                    ->query(fn (Builder $query, array $data): Builder => MediaFilters::use($query, $data['value'] ?? null)),
                SelectFilter::make('state')->label(__('media.library.filters.state'))->options(['ready' => __('media.library.state.ready'), 'processing' => __('media.library.state.processing'), 'failed' => __('media.library.state.failed'), 'missing' => __('media.library.state.missing')])
                    ->query(fn (Builder $query, array $data): Builder => static::filterByState($query, $data['value'] ?? null)),
                Filter::make('month')->label(__('media.library.filters.month'))
                    ->schema([TextInput::make('month')->label(__('media.library.filters.month'))->type('month')])
                    ->query(fn (Builder $query, array $data): Builder => MediaFilters::month($query, (string) ($data['month'] ?? '')))
                    ->indicateUsing(fn (array $data): ?string => ! empty($data['month']) ? __('media.library.filters.month').': '.e((string) $data['month']) : null),
                SelectFilter::make('sort')->label(__('media.library.filters.sort'))->options(['newest' => __('media.library.sort.newest'), 'largest' => __('media.library.sort.largest'), 'name' => __('media.library.sort.name')])
                    ->default('newest')->selectablePlaceholder(false)
                    // baseQuery, not query: Filament runs query() inside a nested WHERE group, where an ORDER BY is lost
                    ->baseQuery(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'newest') {
                        'largest' => $query->reorder()->orderByDesc('catalog_media.size_bytes')->orderByDesc('catalog_media.id'),
                        'name' => $query->reorder()->orderBy('catalog_media.original_name')->orderBy('catalog_media.id'),
                        default => $query->reorder()->orderByDesc('catalog_media.created_at')->orderByDesc('catalog_media.id'),
                    })
                    ->query(fn (Builder $query): Builder => $query)
                    ->indicateUsing(fn (array $data): ?string => null),
            ])
            ->deferFilters(false)
            ->recordActions([static::detailsAction()])
            ->recordAction('details')
            // a bulk action of OUR own, never Filament's DeleteBulkAction: every delete goes through MediaDeleter
            ->toolbarActions([static::deleteSelectedAction()])
            ->emptyStateHeading(__('media.library.empty'));
    }

    /** The «състояние» filter in SQL: see MediaFilters (the «delete all of this filter» job re-runs the same fragments). */
    public static function filterByState(Builder $query, ?string $state): Builder
    {
        return MediaFilters::state($query, $state);
    }

    // ---- the details slide-over --------------------------------------------------------------------------------------------------

    private static function detailsAction(): Action
    {
        return Action::make('details')
            ->label(__('media.library.details.open'))
            ->icon('heroicon-o-information-circle')
            ->iconButton()
            ->slideOver()
            ->modalHeading(fn (MediaAssetModel $record): string => (string) ($record->original_name ?: basename((string) $record->path)))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(__('media.library.details.save'))
            ->modalCancelActionLabel(__('media.library.details.close'))
            ->extraModalFooterActions([static::deleteAction()])
            ->authorize(fn (): bool => static::staffMayUse())
            ->schema(fn (MediaAssetModel $record): array => [
                View::make('filament.pages.media.details')->viewData(fn (): array => static::detailsData($record)),
                TextInput::make('alt_text')->label(__('media.library.details.alt'))->helperText(__('media.library.details.alt_help'))->maxLength(200)->rules([new PlainText]),
            ])
            ->fillForm(fn (MediaAssetModel $record): array => ['alt_text' => $record->alt_text])
            ->action(function (array $data, MediaAssetModel $record): void {
                abort_unless(static::staffMayUse(), 403);

                static::saveAltText((int) $record->id, $data['alt_text'] ?? null);

                Notification::make()->title(__('media.library.details.saved'))->success()->send();
            });
    }

    // ---- delete ------------------------------------------------------------------------------------------------------------------

    /** How many places use the picture, asked live (a few queries, only when a delete button is drawn for ONE picture). */
    public static function usesOf(int $id): int
    {
        return (new MediaUsageRegistry)->counts([$id])[$id] ?? 0;
    }

    /** @return array{0: int, 1: int} the number of size variants and the bytes of the original plus every variant that is on the disk (a few size reads, for ONE picture) */
    public static function deletionSummary(MediaAssetModel $asset): array
    {
        $paths = array_values(array_filter(array_map(fn ($v): ?string => is_array($v) ? ($v['path'] ?? null) : null, (array) $asset->variants)));
        $bytes = (int) $asset->size_bytes;
        $disk = Storage::disk((string) $asset->disk);

        foreach ($paths as $path) {
            try {
                $disk->exists($path) && $bytes += (int) $disk->size($path);
            } catch (\Throwable) {
                // an unreadable variant adds nothing to the total
            }
        }

        return [count($paths), $bytes];
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 2).' MB' : number_format($bytes / 1024, 1).' KB';
    }

    /** The delete button of the details panel: disabled with the reason while the picture is used, a confirmation that names what disappears otherwise. */
    private static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(function (MediaAssetModel $record): string {
                $uses = static::usesOf((int) $record->id);

                return $uses > 0 ? __('media.library.delete.used', ['n' => $uses]) : __('media.library.delete.button');
            })
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->disabled(fn (MediaAssetModel $record): bool => static::usesOf((int) $record->id) > 0)
            ->authorize(fn (): bool => static::staffMayUse())
            ->requiresConfirmation()
            ->modalHeading(__('media.library.delete.heading'))
            ->modalDescription(function (MediaAssetModel $record): string {
                if (static::stateOf($record) === 'missing') {
                    return __('media.library.delete.summary_missing');
                }

                [$variants, $bytes] = static::deletionSummary($record);

                return __('media.library.delete.summary', ['n' => $variants, 'size' => static::humanSize($bytes)]);
            })
            ->action(function (MediaAssetModel $record, Action $action): void {
                abort_unless(static::staffMayUse(), 403);

                $snapshot = ['original_name' => $record->original_name, 'path' => $record->path, 'size_bytes' => $record->size_bytes];
                $outcome = app(MediaDeleter::class)->delete((int) $record->id, static::staffId());

                if ($outcome->status === DeleteOutcome::IN_USE) {
                    Notification::make()->title(__('media.library.delete.in_use'))->danger()->send();

                    return;
                }

                $outcome->wasDeleted() && app(ActivityLogger::class)->logDeleted('media', (string) $record->id, $snapshot);
                Notification::make()->title(__('media.library.delete.done'))->success()->send();
                $action->cancelParentActions();
            });
    }

    /** «Изтрий избраните»: the used ones are skipped and counted, the modal says so before. */
    private static function deleteSelectedAction(): BulkAction
    {
        return BulkAction::make('deleteSelected')
            ->label(__('media.library.delete.selected'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize(fn (): bool => static::staffMayUse())
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->modalHeading(__('media.library.delete.selected_heading'))
            ->modalDescription(function (Collection $records): string {
                $counts = (new MediaUsageRegistry)->counts($records->pluck('id')->map(fn ($id): int => (int) $id)->all());
                $free = $records->filter(fn ($r): bool => ! isset($counts[(int) $r->id]));

                return __('media.library.delete.selected_summary', ['selected' => $records->count(), 'used' => $records->count() - $free->count(), 'count' => $free->count(), 'size' => static::humanSize((int) $free->sum('size_bytes'))]);
            })
            ->action(function (Collection $records): void {
                abort_unless(static::staffMayUse(), 403);

                $deleter = app(MediaDeleter::class);
                $deleted = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    $deleter->delete((int) $record->id, static::staffId())->wasDeleted() ? $deleted++ : $skipped++;
                }

                $deleted + $skipped > 0 && app(ActivityLogger::class)->logDeleted('media', 'bulk-selected', ['deleted' => $deleted, 'skipped_as_used' => $skipped, 'filter' => 'selected on the page']);
                Notification::make()->title(__('media.library.delete.selected_done', ['deleted' => $deleted, 'skipped' => $skipped]))->success()->send();
            });
    }

    /** The signed-in staff member's id, for the deleter and the log. */
    public static function staffId(): ?int
    {
        $id = Filament::auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }

    /** @return array<string, mixed> everything the details view prints, read only when the panel is opened */
    public static function detailsData(MediaAssetModel $asset): array
    {
        abort_unless(static::staffMayUse(), 403);

        $uploader = $asset->uploaded_by !== null ? StaffModel::query()->whereKey($asset->uploaded_by)->value('name') : null;
        $state = static::stateOf($asset);

        return [
            'asset' => $asset,
            'state' => $state,
            'preview' => static::previewUrl($asset, 'medium'),
            'copyUrl' => $state === 'ready' && static::variantPath($asset, 'large') !== null ? static::previewUrl($asset, 'large') : null,
            'uploader' => $uploader,
            'usages' => (new MediaUsageRegistry)->usagesOf((int) $asset->id),
        ];
    }

    /** The alt text of an asset, for someone who may use the page (the work is MediaAltText, shared with the media picker). */
    public static function saveAltText(int $id, mixed $value): void
    {
        abort_unless(static::staffMayUse(), 403);

        MediaAltText::save($id, $value);
    }

    public static function getPages(): array
    {
        return ['index' => ListMedia::route('/')];
    }
}
