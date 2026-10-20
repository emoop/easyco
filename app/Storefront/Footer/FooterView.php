<?php

namespace App\Storefront\Footer;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/**
 * Turns a VALIDATED footer layout plus what its blocks need (the compiled menus and HTML blocks) into what the footer views print (FT-1). A block with no output is dropped (a
 * `newsletter` block while the subscription route does not exist, a menu with no links, a missing HTML block, an empty list), a column that keeps no block with output is dropped
 * (a spacer alone is not output), a row with no column is dropped, and a level with nothing to show is not printed. The year token is replaced by line(), when the view prints, never
 * in what is stored or cached: the footer stays right on 1 January.
 */
final class FooterView
{
    /**
     * @param  array<string, mixed>  $layout  validated
     * @param  array<string, list<array<string, mixed>>>  $menus  validated compiled menus by key (`m12`, `footer`)
     * @param  array<string, string>  $blocks  sanitized HTML by block id
     * @return array{upper: ?array<string, mixed>, lower: ?array<string, mixed>}
     */
    public static function prepare(array $layout, array $menus, array $blocks): array
    {
        $catalog = FooterCatalog::all();
        $upper = null;

        if ($layout['upper']['enabled']) {
            $rows = [];

            foreach ($layout['upper']['rows'] as $row) {
                $columns = [];

                foreach ($row['columns'] as $column) {
                    $kept = [];
                    $output = false;

                    foreach ($column['blocks'] as $block) {
                        $prepared = self::block($block, $catalog, $menus, $blocks);

                        if ($prepared === null) {
                            continue;
                        }

                        $kept[] = $prepared;
                        $output = $output || $block['type'] !== 'spacer';
                    }

                    if ($output) {
                        $columns[] = ['span' => $column['span'], 'align' => $column['align'], 'blocks' => $kept];
                    }
                }

                if ($columns !== []) {
                    $rows[] = ['units' => array_sum(array_column($columns, 'span')), 'columns' => $columns];
                }
            }

            $upper = $rows === [] ? null : ['padding' => $layout['upper']['padding'], 'rows' => $rows];
        }

        $lower = null;

        if ($layout['lower']['enabled']) {
            // The TEMPLATE is carried, not the finished line: the view calls line() when it prints, so the year is the clock's at that moment.
            $template = $layout['lower']['text_template'];
            $lower = trim($template) === '' ? null : ['padding' => $layout['lower']['padding'], 'align' => $layout['lower']['align'], 'template' => $template];
        }

        return ['upper' => $upper, 'lower' => $lower];
    }

    /** The lower line with the CURRENT year in place of `[year]` (plain text: the view escapes it; any other `[...]` stays as written). */
    public static function line(string $template): string
    {
        $zone = (string) (config('services.site.default_timezone') ?: config('app.timezone'));

        return trim(str_replace(FooterLayout::YEAR_TOKEN, (string) CarbonImmutable::now($zone)->year, $template));
    }

    /**
     * @param  array{type: string, settings: array<string, mixed>}  $block
     * @param  array<string, array<string, mixed>>  $catalog
     * @param  array<string, list<array<string, mixed>>>  $menus
     * @param  array<string, string>  $blocks
     * @return array{type: string, view: string, settings: array<string, mixed>, data: array<string, mixed>}|null
     */
    private static function block(array $block, array $catalog, array $menus, array $blocks): ?array
    {
        $type = $block['type'];
        $settings = $block['settings'];
        $descriptor = $catalog[$type] ?? null;

        if ($descriptor === null || ! View::exists($descriptor['view'])) {
            return null;
        }

        $data = [];

        switch ($type) {
            case 'menu':
                $data['items'] = $menus[FooterCompiler::menuKey($settings['menu'])] ?? [];

                if ($data['items'] === []) {
                    return null;
                }

                break;
            case 'html_block':
                $data['html'] = HtmlBlockSanitizer::render($blocks[(string) $settings['html_block']] ?? '');

                if ($data['html'] === '') {
                    return null;
                }

                break;
            case 'text':
                if ($settings['heading'] === '' && $settings['body'] === '') {
                    return null;
                }

                break;
            case 'newsletter':
                if (! Route::has(FooterCatalog::NEWSLETTER_ROUTE)) {
                    return null;
                }

                break;
            case 'social':
                if ($settings['items'] === []) {
                    return null;
                }

                break;
            case 'payments':
                if ($settings['methods'] === []) {
                    return null;
                }

                break;
            case 'contacts':
                if ($settings['phone'] === '' && $settings['email'] === '' && $settings['address'] === '' && $settings['hours'] === '') {
                    return null;
                }

                break;
        }

        return ['type' => $type, 'view' => $descriptor['view'], 'settings' => $settings, 'data' => $data];
    }
}
