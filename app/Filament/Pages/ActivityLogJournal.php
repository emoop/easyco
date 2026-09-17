<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The GLOBAL activity log view — every entity_type, not scoped to one
 * Product the way ProductResource\Pages\ProductActivityLog is (that
 * page's own per-record History action stays; this is the separate,
 * "browse everything" surface). Mirrors that page's Page+HasTable+
 * InteractsWithTable shape.
 *
 * NAV-VISIBLE ONLY WHEN THE SETTING IS ON (shouldRegisterNavigation())
 * — and mount() independently enforces the SAME combined "setting on
 * AND SETTINGS_MANAGE" condition (isAccessible() below), so a direct
 * URL hit still 403s under either failing condition, Administrator
 * included, even with the nav link hidden — the established
 * "canAccess() re-checked at mount(), never trusted from the nav
 * render alone" pattern every other gated page in this project already
 * follows (see LocaleSettings::canAccess()'s own docblock for why the
 * permission half of this lives here, not on the shared trait).
 */
class ActivityLogJournal extends Page implements HasTable
{
    use AuthorizesViaStaffPermission;
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public function getTitle(): string
    {
        return __('journal.title');
    }

    /** Right after Settings (sort 30) — see LocaleSettings::getNavigationSort()'s own docblock for the group/sort reasoning. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 40;
    }

    public static function getNavigationLabel(): string
    {
        return __('journal.navigation_label');
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::SETTINGS_MANAGE;
    }

    /** See LocaleSettings::canAccess()'s own docblock for why this is defined locally, not on the shared trait. */
    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    /**
     * BOTH conditions, independently of each other: the setting off
     * hides the link even for an Administrator (nothing to browse, by
     * the merchant's own choice), and lacking SETTINGS_MANAGE hides it
     * even when the setting is on (a Manager/Product Entry never sees
     * this regardless — SETTINGS_MANAGE is Administrator-only,
     * confirmed against StaffSystemRolesSeeder). shouldRegisterNavigation()
     * itself is the real, current Filament\Pages\Page method (confirmed
     * against the installed v5.8.1 source) — this is purely a nav-link
     * visibility gate; canAccess()/mount() below enforce the real
     * authorization boundary independently, so a direct URL hit still
     * 403s under either condition.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::isAccessible();
    }

    /**
     * Shared by shouldRegisterNavigation() and mount() — a REAL,
     * confirmed gap found while testing: mount() checking canAccess()
     * ALONE let an Administrator reach this page via a direct URL hit
     * even with the setting off (SETTINGS_MANAGE alone was satisfied;
     * nothing else stopped it). While the setting is off, this page
     * has nothing to show by the merchant's own choice — direct-URL
     * access must 403 for EVERY role then, Administrator included, not
     * just the nav link staying hidden.
     */
    private static function isAccessible(): bool
    {
        return app(SiteSettingsRepository::class)->get('admin.activity_log_enabled') === '1'
            && static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::isAccessible(), 403);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ActivityLogModel::query())
            // Same real tie-breaker reasoning as ProductActivityLog's
            // own identical defaultSort() — see that page's docblock.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('journal.columns.occurred_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('entity_type')
                    ->label(__('journal.columns.entity_type')),
                TextColumn::make('entity_id')
                    ->label(__('journal.columns.entity_id')),
                TextColumn::make('action')
                    ->label(__('journal.columns.action'))
                    ->formatStateUsing(fn (ActivityLogModel $record): string => static::actionLabel($record)),
                TextColumn::make('old_value')
                    ->label(__('journal.columns.old_value')),
                TextColumn::make('new_value')
                    ->label(__('journal.columns.new_value')),
                TextColumn::make('staff_name')
                    ->label(__('journal.columns.staff_name'))
                    ->formatStateUsing(fn (?string $state): string => $state ?? __('journal.system_actor')),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->label(__('journal.columns.entity_type'))
                    ->options(fn (): array => ActivityLogModel::query()
                        ->distinct()
                        ->orderBy('entity_type')
                        ->pluck('entity_type', 'entity_type')
                        ->all()),
                SelectFilter::make('staff_name')
                    ->label(__('journal.columns.staff_name'))
                    ->options(fn (): array => ActivityLogModel::query()
                        ->whereNotNull('staff_name')
                        ->distinct()
                        ->orderBy('staff_name')
                        ->pluck('staff_name', 'staff_name')
                        ->all()),
                Filter::make('occurred_at')
                    ->label(__('journal.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('journal.filters.date_from')),
                        DatePicker::make('until')
                            ->label(__('journal.filters.date_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $from): Builder => $query->whereDate('occurred_at', '>=', $from),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $until): Builder => $query->whereDate('occurred_at', '<=', $until),
                            );
                    }),
            ]);
    }

    /**
     * A comparably simple version of ProductActivityLog::actionLabel()
     * — not reused as-is, since that one resolves field labels against
     * products.fields.* / AttributeDefinitionModel specifically, which
     * makes no sense for a non-product entity_type. Here, 'updated'
     * shows the raw field name — this global view has no single
     * per-entity-type translation table to consult.
     */
    protected static function actionLabel(ActivityLogModel $record): string
    {
        if ($record->action === 'created') {
            return __('journal.action_created');
        }

        return (string) $record->field;
    }
}
