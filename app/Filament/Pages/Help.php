<?php

namespace App\Filament\Pages;

use App\Filament\Support\HelpRenderer;
use App\Filament\Support\HelpTopics;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;

/**
 * The in-app help (Help 1): `/admin/help/{topic?}`, opened in a NEW tab from the top bar's help icon and from the
 * "Help: how this works →" line of the order page's dialogs. Reading only: it renders a Markdown file of the
 * store's locale (resources/help/{locale}/{topic}.md, see HelpRenderer) and shows a contents list.
 *
 * NOT IN THE NAVIGATION, and readable by ANY authenticated staff member: the panel's own authentication is the
 * whole gate (no permission is needed to read how the screens work). It is still re-checked at mount, the
 * project's usual pattern, so a direct URL never trusts the route's middleware alone. An unknown topic is a 404:
 * only a key in HelpTopics ever becomes a file path.
 *
 * It runs NO database query of its own: the content is a file, cached in the file store.
 */
class Help extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.help';

    public string $topic = HelpTopics::DEFAULT;

    public static function getRoutePath(Panel $panel): string
    {
        return '/'.static::getSlug($panel).'/{topic?}';
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->check();
    }

    public function mount(?string $topic = null): void
    {
        abort_unless(static::canAccess(), 403);

        $topic ??= HelpTopics::DEFAULT;

        abort_unless(HelpTopics::has($topic), 404);

        $this->topic = $topic;
    }

    public function getTitle(): string
    {
        return __('help.title');
    }

    /** @return array{html: string, toc: list<array{id: string, title: string, level: int}>, locale: string} */
    public function getHelp(): array
    {
        return app(HelpRenderer::class)->render($this->topic, app()->getLocale());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'help' => $this->getHelp(),
            'topicLabel' => HelpTopics::label($this->topic),
        ];
    }
}
