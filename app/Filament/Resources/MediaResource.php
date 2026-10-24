<?php

namespace App\Filament\Resources;

use App\Filament\NavigationGroup;
use App\Filament\Resources\MediaResource\Pages\ListMedia;
use App\Media\Usage\MediaUsageRegistry;
use App\Rules\PlainText;
use App\Services\ActivityLogger;
use App\Services\AuthenticatedStaffResolver;
use BackedEnum;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Actions\Action;
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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'image' => $query->whereIn('catalog_media.type', ['image', 'social_image']),
                        'video' => $query->whereIn('catalog_media.type', ['video', 'social_video']),
                        default => $query,
                    }),
                SelectFilter::make('use')->label(__('media.library.filters.use'))->options(['used' => __('media.library.use.used'), 'unused' => __('media.library.use.unused')])
                    ->query(function (Builder $query, array $data): Builder {
                        $registry = new MediaUsageRegistry;

                        match ($data['value'] ?? null) {
                            'used' => $registry->onlyUsed($query),
                            'unused' => $registry->excludeUsed($query),
                            default => null,
                        };

                        return $query;
                    }),
                SelectFilter::make('state')->label(__('media.library.filters.state'))->options(['ready' => __('media.library.state.ready'), 'processing' => __('media.library.state.processing'), 'failed' => __('media.library.state.failed'), 'missing' => __('media.library.state.missing')])
                    ->query(fn (Builder $query, array $data): Builder => static::filterByState($query, $data['value'] ?? null)),
                Filter::make('month')->label(__('media.library.filters.month'))
                    ->schema([TextInput::make('month')->label(__('media.library.filters.month'))->type('month')])
                    ->query(function (Builder $query, array $data): Builder {
                        $month = (string) ($data['month'] ?? '');

                        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
                            return $query;
                        }

                        $start = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00');

                        return $query->where('catalog_media.created_at', '>=', $start)->where('catalog_media.created_at', '<', $start->copy()->addMonth());
                    })
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
            ->emptyStateHeading(__('media.library.empty'));
    }

    /** The «състояние» filter in SQL, mirroring stateOf(): the rule is the same, written once for the rows and once for the query. */
    public static function filterByState(Builder $query, ?string $state): Builder
    {
        $missing = fn (Builder $q): Builder => $q->whereNotNull('catalog_media.missing_at');
        $noVariants = fn (Builder $q): Builder => $q->where(fn ($i) => $i->whereNull('catalog_media.variants')->orWhere('catalog_media.variants', '[]'));
        $notVariantFile = fn (Builder $q): Builder => $q->where('catalog_media.path', 'not like', '%-thumbnail.webp')->where('catalog_media.path', 'not like', '%-medium.webp')->where('catalog_media.path', 'not like', '%-large.webp')->where('catalog_media.path', 'not like', '%-admin_grid.webp');

        return match ($state) {
            'processing' => $query->whereNull('catalog_media.missing_at')->whereIn('catalog_media.processing_status', ['pending', 'processing'])->whereIn('catalog_media.type', ['image', 'social_image']),
            'failed' => $query->whereNull('catalog_media.missing_at')->where('catalog_media.processing_status', 'failed')->whereIn('catalog_media.type', ['image', 'social_image']),
            'missing' => $query->where(fn ($q) => $q
                ->whereNotNull('catalog_media.missing_at')
                ->orWhere(fn ($i) => $i->whereIn('catalog_media.type', ['image', 'social_image'])->where('catalog_media.processing_status', 'ready')->where(fn ($v) => $noVariants($v))->where(fn ($v) => $notVariantFile($v)))),
            'ready' => $query->whereNull('catalog_media.missing_at')->where('catalog_media.processing_status', 'ready')
                ->where(fn ($q) => $q->whereIn('catalog_media.type', ['video', 'social_video'])->orWhere(fn ($i) => $i->whereNotNull('catalog_media.variants')->where('catalog_media.variants', '!=', '[]')->orWhere(fn ($v) => $v->where('catalog_media.path', 'like', '%-thumbnail.webp')->orWhere('catalog_media.path', 'like', '%-medium.webp')->orWhere('catalog_media.path', 'like', '%-large.webp')->orWhere('catalog_media.path', 'like', '%-admin_grid.webp')))),
            default => $query,
        };
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

    /** Plain text (control characters already refused by the rule), at most 200, empty = none; through the domain repository; the log line says who and which asset, never the text. */
    public static function saveAltText(int $id, mixed $value): void
    {
        abort_unless(static::staffMayUse(), 403);

        $text = is_string($value) ? trim($value) : '';

        if (mb_strlen($text) > 200 || preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text) !== 0) {
            throw ValidationException::withMessages(['alt_text' => __('media.library.details.alt_invalid')]);
        }

        $repository = app(MediaAssetRepository::class);
        $asset = $repository->findById((string) $id);

        if ($asset === null) {
            return;
        }

        $asset->updateAltText($text === '' ? null : $text);
        $repository->save($asset);

        app(ActivityLogger::class)->logFieldChanged('media_asset', (string) $id, 'alt_text', null, 'saved');
    }

    public static function getPages(): array
    {
        return ['index' => ListMedia::route('/')];
    }
}
