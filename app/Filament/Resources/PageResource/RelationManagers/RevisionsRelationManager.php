<?php

namespace App\Filament\Resources\PageResource\RelationManagers;

use App\Content\Models\PageRevisionModel;
use App\Filament\Resources\PageResource;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The page's revisions (pages-design.md 3.3): a READ-ONLY list (number, date, who) and a link to a revision's text. No create, edit,
 * delete or restore: the history is append-only (restore is a later convenience; copy and paste is enough).
 */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pages.revisions.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PageResource::canViewAny();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->leftJoin('staff', 'staff.id', '=', 'page_revisions.staff_id')
                ->select('page_revisions.*', 'staff.name as staff_name')
                ->orderByDesc('page_revisions.revision'))
            ->defaultSort(fn (Builder $query): Builder => $query)
            ->paginated(false)
            ->columns([
                TextColumn::make('revision')->label(__('pages.revisions.number'))->prefix('#')->weight('bold'),
                TextColumn::make('title')->label(__('pages.fields.title'))->wrap(),
                TextColumn::make('created_at')->label(__('pages.revisions.date'))->dateTime(),
                TextColumn::make('staff_name')->label(__('pages.revisions.who'))->placeholder(__('pages.revisions.unknown_staff')),
            ])
            ->recordActions([
                Action::make('view_revision')
                    ->label(__('pages.revisions.view'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (PageRevisionModel $record): string => PageResource::getUrl('revision', ['record' => $record->page_id, 'revision' => $record->revision])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
