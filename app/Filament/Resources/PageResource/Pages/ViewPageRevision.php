<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Content\Models\PageModel;
use App\Content\Models\PageRevisionModel;
use App\Filament\Resources\PageResource;
use App\Filament\Support\HelpLink;
use App\Storefront\Support\DescriptionSanitizer;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * One revision of a page, READ-ONLY (pages-design.md 3.3): the saved title and the saved text, exactly as they were. The body is
 * printed after DescriptionSanitizer::clean() again, like the storefront does: a revision row edited by hand cannot carry a script into
 * the admin either. The order view's terms line (`p{id}r{n}`) links here.
 */
class ViewPageRevision extends Page
{
    protected static string $resource = PageResource::class;

    // Not named `$record`: a typed property of that name makes Livewire's route binding hand mount() the model instead of the id.
    public PageModel $page;

    public PageRevisionModel $revisionRow;

    public function mount(int|string $record, int|string $revision): void
    {
        $this->page = PageModel::query()->findOrFail((int) $record);
        $this->revisionRow = PageRevisionModel::query()->where('page_id', $this->page->id)->where('revision', (int) $revision)->firstOrFail();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return PageResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('pages.revisions.heading', ['title' => $this->revisionRow->title, 'number' => $this->revisionRow->revision]);
    }

    /** «Помощ: как работи →» (new tab): revisions are read only evidence. */
    public function getSubheading(): string|Htmlable|null
    {
        return HelpLink::html('pages-revisions', 'pages');
    }

    public function getBreadcrumbs(): array
    {
        return [
            PageResource::getUrl('index') => __('pages.plural'),
            PageResource::getUrl('edit', ['record' => $this->page]) => $this->page->title,
            '#'.$this->revisionRow->revision,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_page')->label(__('pages.revisions.back'))->color('gray')->url(PageResource::getUrl('edit', ['record' => $this->page])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make($this->revisionRow->title)
                ->description(__('pages.revisions.saved_at', ['date' => $this->revisionRow->created_at?->format('Y-m-d H:i')]))
                ->schema([
                    Text::make(new HtmlString(DescriptionSanitizer::clean($this->revisionRow->body))),
                ]),
        ]);
    }
}
