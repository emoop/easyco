<?php

namespace App\Filament\Support;

use Illuminate\Support\Facades\Cache;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use RuntimeException;

/**
 * Renders a help topic's Markdown (Help 1) with league/commonmark: the core CommonMark, GitHub-style TABLES and
 * the ATTRIBUTES extension (so `## Title {#anchor}` gives a heading its stable id). Raw HTML in the Markdown is
 * STRIPPED (`html_input: strip`) and unsafe links refused (`allow_unsafe_links: false`): a file under
 * resources/help can never put markup or a javascript: link on the page.
 *
 * Returns the HTML, with an "↑ Contents" link closing every level-2 section, and the contents list (the h2/h3
 * headings and their ids). The result is cached per topic, locale and the file's mtime in the FILE cache store
 * — not the application's default store, which is the database in production: reading help must cost no query.
 */
final class HelpRenderer
{
    /** Bumped when the rendering changes, so an old cache entry is never served for new code. */
    private const VERSION = 'v1';

    /**
     * @return array{html: string, toc: list<array{id: string, title: string, level: int}>, locale: string}
     */
    public function render(string $topic, string $locale): array
    {
        $path = HelpTopics::path($topic, $locale);
        $usedLocale = $locale;

        if ($path === null) {
            $usedLocale = 'en';
            $path = HelpTopics::path($topic, 'en');
        }

        if ($path === null) {
            throw new RuntimeException("Help: no file exists for topic \"{$topic}\".");
        }

        $key = sprintf('help.%s.%s.%s.%d', $topic, $usedLocale, self::VERSION, filemtime($path));

        return Cache::store('file')->rememberForever($key, fn (): array => $this->build($path, $usedLocale));
    }

    /** @return array{html: string, toc: list<array{id: string, title: string, level: int}>, locale: string} */
    private function build(string $path, string $locale): array
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new AttributesExtension());

        $document = (new MarkdownParser($environment))->parse((string) file_get_contents($path));
        $toc = [];

        foreach ($document->iterator() as $node) {
            if (! $node instanceof Heading || ! in_array($node->getLevel(), [2, 3], true)) {
                continue;
            }

            $attributes = $node->data->get('attributes', []);
            $id = is_array($attributes) ? ($attributes['id'] ?? null) : null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $title = '';

            foreach ($node->iterator() as $child) {
                if ($child instanceof Text) {
                    $title .= $child->getLiteral();
                }
            }

            $toc[] = ['id' => $id, 'title' => trim($title), 'level' => $node->getLevel()];
        }

        $html = (string) (new HtmlRenderer($environment))->renderDocument($document);

        // "↑ Contents" closes every level-2 section.
        $back = '<p class="help-top"><a href="#contents">↑ '.e(__('help.contents', [], $locale)).'</a></p>';
        $sections = preg_split('/(?=<h2[\s>])/', $html) ?: [$html];
        $html = '';

        foreach ($sections as $i => $section) {
            $html .= $section.($i > 0 ? $back : '');
        }

        return ['html' => $html, 'toc' => $toc, 'locale' => $locale];
    }
}
