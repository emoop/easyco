<?php

namespace App\Filament\Resources;

use App\Content\Exceptions\PageInvalidException;
use App\Content\Models\PageModel;
use App\Content\PageSlug;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Filament\Resources\PageResource\Pages\CreatePage;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Filament\Resources\PageResource\Pages\ViewPageRevision;
use App\Filament\Resources\PageResource\RelationManagers\RevisionsRelationManager;
use App\Http\Controllers\Storefront\PageController;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * "Страници" / Pages (pages-design.md section 3): the shop's static content pages: terms, privacy, delivery, returns, contacts.
 * Every write is ONE call to App\Content\PageWriter (it sanitizes the body, writes the revision, refuses a bad slug): a form never
 * saves a page itself. Everything here is gated by `content_manage`. There is NO delete: a page is archived (draft + out of the
 * footer) because its revisions are evidence of what a customer accepted.
 *
 * The RichEditor's toolbar offers exactly what the storefront's allow-list keeps (DescriptionSanitizer): H2-H4, bold, italic, lists,
 * a link, a blockquote and a table: nothing the sanitizer would drop is offered.
 */
class PageResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = PageModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getModelLabel(): string
    {
        return __('pages.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pages.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('pages.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CONTENT;
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    protected static function viewPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    /** Never offered: a page is archived, not deleted (its revisions are evidence). The permission is left undeclared, so every role fails closed. */
    protected static function deletePermission(): ?Permission
    {
        return null;
    }

    // ---- the form -------------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('title')
                ->label(__('pages.fields.title'))
                ->required()
                ->maxLength(200)
                ->live(onBlur: true)
                // Suggested from the title while the slug is still empty; always editable afterwards.
                ->afterStateUpdated(function (?string $state, $set, $get): void {
                    if (blank($get('slug')) && filled($state)) {
                        $set('slug', PageSlug::suggest($state));
                    }
                }),
            TextInput::make('slug')
                ->label(__('pages.fields.slug'))
                ->helperText(__('pages.help.slug'))
                ->required()
                ->maxLength(PageSlug::MAX_LENGTH)
                ->live(onBlur: true),
            HelpLink::component('pages-slug', 'pages'),
            Text::make(__('pages.help.slug_changed'))
                ->color('danger')
                ->size('sm')
                ->visible(fn ($get, ?PageModel $record): bool => $record !== null && $record->isPublished() && filled($get('slug')) && $get('slug') !== $record->slug),
            RichEditor::make('body')
                ->label(__('pages.fields.body'))
                ->toolbarButtons([
                    ['bold', 'italic', 'link'],
                    ['h2', 'h3', 'h4'],
                    ['bulletList', 'orderedList', 'blockquote'],
                    ['table'],
                    ['undo', 'redo'],
                ])
                ->helperText(__('pages.help.body')),
            Select::make('status')
                ->label(__('pages.fields.status'))
                ->options(static::statusOptions())
                ->default(PageModel::DRAFT)
                ->required(),
            HelpLink::component('pages-publish', 'pages'),
            Section::make(__('pages.sections.seo'))
                ->collapsed()
                ->schema([
                    TextInput::make('meta_title')->label(__('pages.fields.meta_title'))->maxLength(200),
                    TextInput::make('meta_description')->label(__('pages.fields.meta_description'))->maxLength(320),
                    Toggle::make('noindex')->label(__('pages.fields.noindex'))->helperText(__('pages.help.noindex')),
                ]),
            // D3: the footer is the `footer` menu now (Менюта -> Футър); the page no longer carries a flag or an order of its own.
            Section::make(__('pages.sections.footer'))
                ->collapsed()
                ->schema([
                    HelpLink::component('appearance-menus', 'appearance'),
                    Text::make(fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(e(__('pages.footer_hint')).' <a class="underline" href="'.e(MenuResource::getUrl('index')).'">'.e(__('pages.footer_hint_link')).'</a>')),
                ]),
        ]);
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [PageModel::DRAFT => __('pages.status.draft'), PageModel::PUBLISHED => __('pages.status.published')];
    }

    // ---- the list -------------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->emptyStateHeading(__('pages.empty'))
            ->columns([
                TextColumn::make('title')->label(__('pages.fields.title'))->searchable()->weight('bold')->wrap(),
                TextColumn::make('slug')->label(__('pages.fields.slug'))->searchable()->prefix('/info/'),
                TextColumn::make('status')
                    ->label(__('pages.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === PageModel::PUBLISHED ? 'success' : 'gray'),
                IconColumn::make('in_footer')->label(__('pages.fields.in_footer'))->boolean(),
                TextColumn::make('revision')->label(__('pages.fields.revision'))->prefix('#'),
                TextColumn::make('updated_at')->label(__('pages.fields.updated_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('edit_page')
                    ->label(__('pages.actions.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (PageModel $record): string => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (PageModel $record): bool => static::canEdit($record)),
                static::previewAction(),
            ]);
    }

    /** The Preview: the page as the storefront renders it, through a temporary signed url (drafts too; never indexed). */
    public static function previewAction(): Action
    {
        return Action::make('preview_page')
            ->label(__('pages.actions.preview'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->url(fn (PageModel $record): string => PageController::previewUrl($record))
            ->openUrlInNewTab()
            ->visible(fn (PageModel $record): bool => static::canView($record));
    }

    public static function getRelations(): array
    {
        return [RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
            'revision' => ViewPageRevision::route('/{record}/revisions/{revision}'),
        ];
    }

    // ---- shared by the pages ---------------------------------------------------------------------------------------

    /**
     * A writer refusal as the form's own field errors (the form's state lives under `data`).
     *
     * @throws ValidationException
     */
    public static function fieldErrors(PageInvalidException $exception): never
    {
        $messages = [];

        foreach ($exception->errors as $field => $list) {
            $messages['data.'.$field] = $list;
        }

        throw ValidationException::withMessages($messages);
    }
}
