<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage D2 (storefront-customization-design.md 4): the layout model and the element catalog (validation on save, on compile and on READ), the heights as CSS
 * variables, publish / draft / signed preview, elements of missing features, the html element, an extension's element, the markup (unique ids, landmarks, no
 * inline style) and the query numbers (the header rides the appearance read: no new query).
 */
class HeaderBuilderTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        Storage::fake('public');
        $this->seedPricing();
        $parent = $this->makeCategory(1, 'Clothing', 'clothing');
        $child = $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$parent, $child], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** The next request starts from the database: the reader's cache entry and the scoped instances are dropped. */
    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function layout(callable $change): array
    {
        return $change(HeaderLayout::default());
    }

    private function el(string $type, array $settings = []): array
    {
        return ['type' => $type, 'settings' => $settings];
    }

    private function publish(array $layout): void
    {
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $this->fresh();
    }

    // ---- validation ------------------------------------------------------------------------------------------------------------------

    public function test_the_seeded_default_is_valid_and_matches_the_owners_vision(): void
    {
        $validation = HeaderValidator::validate(HeaderLayout::default());

        $this->assertTrue($validation->passes(), json_encode($validation->errors));
        $layout = $validation->layout;
        $this->assertSame(['menu'], array_column($layout['desktop']['rows']['main']['left'], 'type'));
        $this->assertSame(['logo'], array_column($layout['desktop']['rows']['main']['center'], 'type'));
        $this->assertSame(['search', 'account', 'cart'], array_column($layout['desktop']['rows']['main']['right'], 'type'));
        $this->assertFalse($layout['desktop']['rows']['top']['enabled']);
        $this->assertFalse($layout['desktop']['rows']['bottom']['enabled'], 'the categories are IN the main row: no second nav row');
        $this->assertSame([64, 28], [$layout['desktop']['rows']['main']['height'], $layout['desktop']['rows']['main']['center'][0]['settings']['height']]);
        $this->assertSame(['menu_toggle'], array_column($layout['mobile']['rows']['main']['left'], 'type'));
        $this->assertSame([56, 26], [$layout['mobile']['rows']['main']['height'], $layout['mobile']['rows']['main']['center'][0]['settings']['height']]);
        $this->assertSame(['menu', 'account'], array_column($layout['mobile']['drawer'], 'type'));
        $this->assertSame('left', $layout['mobile']['drawer_side']);
        $this->assertTrue($layout['desktop']['sticky'] && $layout['mobile']['sticky']);
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> [mutation, the path that must carry the message] */
    public static function brokenLayouts(): array
    {
        $put = static fn (array $l, string $layout, string $row, string $slot, array $items): array => (function () use ($l, $layout, $row, $slot, $items) {
            $l[$layout]['rows'][$row][$slot] = $items;

            return $l;
        })();
        $el = static fn (string $type, array $settings = []): array => ['type' => $type, 'settings' => $settings];

        return [
            'an unknown type' => [fn (array $l): array => $put($l, 'desktop', 'main', 'left', [$el('evil')]), 'desktop.rows.main.left.0.type'],
            'an unknown setting' => [fn (array $l): array => $put($l, 'desktop', 'main', 'left', [$el('menu', ['onclick' => 'x'])]), 'desktop.rows.main.left.0.settings.onclick'],
            'an unknown top-level key' => [fn (array $l): array => $l + ['script' => 1], 'layout'],
            'an unknown row key' => [fn (array $l): array => (function () use ($l) { $l['desktop']['rows']['main']['css'] = 'x'; return $l; })(), 'desktop.rows.main'],
            'a row height under the range' => [fn (array $l): array => (function () use ($l) { $l['desktop']['rows']['main']['height'] = 43; return $l; })(), 'desktop.rows.main.height'],
            'a row height over the range' => [fn (array $l): array => (function () use ($l) { $l['mobile']['rows']['main']['height'] = 97; return $l; })(), 'mobile.rows.main.height'],
            'a row height that is not a number' => [fn (array $l): array => (function () use ($l) { $l['desktop']['rows']['top']['height'] = 'tall'; return $l; })(), 'desktop.rows.top.height'],
            'a logo taller than its row minus 8' => [fn (array $l): array => (function () use ($l) { $l['desktop']['rows']['main']['height'] = 44; $l['desktop']['rows']['main']['center'][0]['settings']['height'] = 37; return $l; })(), 'desktop.rows.main.center.0.settings.height'],
            'a logo over its own range' => [fn (array $l): array => (function () use ($l) { $l['mobile']['rows']['main']['center'][0]['settings']['height'] = 65; return $l; })(), 'mobile.rows.main.center.0.settings.height'],
            'seven elements in one slot' => [fn (array $l): array => $put($l, 'desktop', 'main', 'right', array_fill(0, 7, $el('spacer'))), 'desktop.rows.main.right'],
            'two logos in a layout' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('logo')]), 'desktop.rows'],
            'two menu buttons in a layout' => [fn (array $l): array => $put($l, 'mobile', 'top', 'left', [$el('menu_toggle')]), 'mobile.rows'],
            'a logo in the drawer' => [fn (array $l): array => (function () use ($l) { $l['mobile']['drawer'] = [['type' => 'logo', 'settings' => []]]; return $l; })(), 'mobile.drawer.0'],
            'a cart in the drawer' => [fn (array $l): array => (function () use ($l) { $l['mobile']['drawer'] = [['type' => 'cart', 'settings' => []]]; return $l; })(), 'mobile.drawer.0'],
            'nine drawer elements' => [fn (array $l): array => (function () use ($l) { $l['mobile']['drawer'] = array_fill(0, 9, ['type' => 'text', 'settings' => ['text' => 'x']]); return $l; })(), 'mobile.drawer'],
            'a javascript: link' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('button', ['label' => 'Go', 'url' => 'javascript:alert(1)'])]), 'desktop.rows.top.left.0.settings.url'],
            'a protocol-relative link' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('text', ['text' => 'Hi', 'url' => '//evil.test/x'])]), 'desktop.rows.top.left.0.settings.url'],
            'a plain http link' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('button', ['label' => 'Go', 'url' => 'http://example.com'])]), 'desktop.rows.top.left.0.settings.url'],
            'a button without a link' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('button', ['label' => 'Go'])]), 'desktop.rows.top.left.0.settings.url'],
            'a text that is too long' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('text', ['text' => str_repeat('a', 121)])]), 'desktop.rows.top.left.0.settings.text'],
            'a text with a bidirectional override' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('text', ['text' => "Hi\u{202E}x"])]), 'desktop.rows.top.left.0.settings.text'],
            'a menu style that is not offered' => [fn (array $l): array => $put($l, 'desktop', 'main', 'left', [$el('menu', ['style' => 'giant'])]), 'desktop.rows.main.left.0.settings.style'],
            'a social link that is not https' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('social', ['items' => [['network' => 'facebook', 'url' => '/page']]])]), 'desktop.rows.top.left.0.settings.items.0.url'],
            'a social network that is not in the list' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('social', ['items' => [['network' => 'myspace', 'url' => 'https://x.test']]])]), 'desktop.rows.top.left.0.settings.items.0.network'],
            'an html block that is too long' => [fn (array $l): array => $put($l, 'desktop', 'top', 'left', [$el('html', ['html' => '<p>'.str_repeat('a', 4001).'</p>'])]), 'desktop.rows.top.left.0.settings.html'],
            'a drawer side that is not left or right' => [fn (array $l): array => (function () use ($l) { $l['mobile']['drawer_side'] = 'top'; return $l; })(), 'mobile.drawer_side'],
        ];
    }

    #[DataProvider('brokenLayouts')]
    public function test_a_broken_layout_is_refused_with_a_message_on_the_right_field(callable $break, string $path): void
    {
        $validation = HeaderValidator::validate($break(HeaderLayout::default()));

        $this->assertFalse($validation->passes());
        $this->assertArrayHasKey($path, $validation->errors, 'errors: '.json_encode($validation->errors));
        $this->assertNotSame('', $validation->errors[$path]);
        $this->assertStringNotContainsString('header.errors.', $validation->errors[$path], 'a translated sentence, not a key');
    }

    public function test_the_messages_are_in_both_languages_and_the_logo_one_is_the_owners_sentence(): void
    {
        $layout = $this->layout(function (array $l): array {
            $l['desktop']['rows']['main']['height'] = 44;
            $l['desktop']['rows']['main']['center'][0]['settings']['height'] = 37;

            return $l;
        });

        $this->assertStringContainsString('taller than the row', HeaderValidator::validate($layout)->errors['desktop.rows.main.center.0.settings.height']);
        app()->setLocale('bg');
        $this->assertStringContainsString('Логото е по-високо от реда', HeaderValidator::validate($layout)->errors['desktop.rows.main.center.0.settings.height']);

        foreach (array_keys(trans('header.errors', [], 'en')) as $key) {
            $this->assertArrayHasKey($key, trans('header.errors', [], 'bg'), "bg has {$key}");
        }
    }

    public function test_the_boundaries_of_every_range_are_accepted(): void
    {
        foreach (HeaderLayout::ROW_HEIGHTS as $name => $rows) {
            foreach ($rows as $row => [$min, $max]) {
                foreach ([$min, $max] as $height) {
                    $layout = $this->layout(function (array $l) use ($name, $row, $height): array {
                        $l[$name]['rows'][$row]['height'] = $height;

                        return $l;
                    });
                    // The logo has to fit its row at the extremes.
                    foreach ($layout as $layoutName => $part) {
                        foreach ($part['rows'] as $rowName => $data) {
                            foreach (['left', 'center', 'right'] as $slot) {
                                foreach ($data[$slot] as $i => $element) {
                                    if ($element['type'] === 'logo') {
                                        $layout[$layoutName]['rows'][$rowName][$slot][$i]['settings']['height'] = 16;
                                    }
                                }
                            }
                        }
                    }

                    $validation = HeaderValidator::validate($layout);
                    $this->assertTrue($validation->passes(), "{$name}.{$row} = {$height}: ".json_encode($validation->errors));
                }
            }
        }
    }

    public function test_every_element_type_validates_with_its_defaults_and_is_normalised(): void
    {
        foreach (array_keys(HeaderCatalog::builtin()) as $type) {
            if ($type === 'logo') {
                continue; // exactly one per layout: the default's own logo is validated by the default test
            }

            $settings = HeaderCatalog::defaultsFor($type);

            if ($type === 'button') {
                $settings = ['label' => 'Go', 'url' => '/sale'] + $settings;
            }

            $layout = $this->layout(function (array $l) use ($type, $settings): array {
                $l['desktop']['rows']['top']['left'] = [['type' => $type, 'settings' => $settings]];

                return $l;
            });
            $validation = HeaderValidator::validate($layout);

            $this->assertTrue($validation->passes(), "{$type}: ".json_encode($validation->errors));
        }

        $missing = $this->layout(function (array $l): array {
            $l['desktop']['rows']['top']['left'] = [['type' => 'cart', 'settings' => []]];

            return $l;
        });
        $this->assertSame(['style' => 'icon'], HeaderValidator::validate($missing)->layout['desktop']['rows']['top']['left'][0]['settings'], 'missing settings are filled with their defaults');
    }

    public function test_the_html_element_is_sanitised_on_save(): void
    {
        $layout = $this->layout(function (array $l): array {
            $l['desktop']['rows']['top']['left'] = [['type' => 'html', 'settings' => ['html' => '<p onclick="x()" style="color:red">Hi <b>there</b></p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">bad</a>']]];

            return $l;
        });
        $clean = HeaderValidator::validate($layout)->layout['desktop']['rows']['top']['left'][0]['settings']['html'];

        $this->assertStringContainsString('Hi <b>there</b>', $clean);

        foreach (['<script', 'onclick', 'onerror', 'style=', 'javascript:'] as $bad) {
            $this->assertStringNotContainsString($bad, $clean, $bad);
        }
    }

    // ---- the cached payload is re-validated --------------------------------------------------------------------------------------------

    public function test_a_hand_edited_published_row_with_an_unknown_type_renders_the_default_layout(): void
    {
        $default = HeaderValidator::validate(HeaderLayout::default())->layout;
        $evil = $default;
        $evil['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'evil_widget', 'settings' => ['x' => 1]]], 'center' => [], 'right' => []];
        $this->settings()->set(AppearanceSettings::HEADER_COMPILED_KEY, json_encode(['hash' => 'abc', 'layout' => $evil]));
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('evil_widget', $html);
        $this->assertStringNotContainsString('sf-hdr-row-top', $html, 'the seeded default has no top row');
        $this->assertStringContainsString('sf-hdr-row-main', $html);
        $this->assertSame(HeaderLayout::default(), app(StorefrontAppearance::class)->header());
    }

    public function test_a_hand_edited_cache_entry_is_re_validated_too(): void
    {
        $this->get('/')->assertOk();
        $entry = Cache::get(StorefrontAppearance::CACHE_KEY);
        $entry['header'] = ['desktop' => ['sticky' => true, 'rows' => ['main' => ['enabled' => true, 'height' => 64, 'left' => [['type' => 'evil', 'settings' => []]], 'center' => [], 'right' => []]]], 'mobile' => []];
        Cache::forever(StorefrontAppearance::CACHE_KEY, $entry);
        $this->app->forgetScopedInstances();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringContainsString('class="sf-logo', $html);
    }

    public function test_a_hand_edited_html_element_cannot_carry_a_script_to_the_page(): void
    {
        $layout = $this->layout(function (array $l): array {
            $l['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'html', 'settings' => ['html' => '<p>Hello</p>']]], 'center' => [], 'right' => []];

            return $l;
        });
        $stored = HeaderValidator::validate($layout)->layout;
        $stored['desktop']['rows']['top']['left'][0]['settings']['html'] = '<p>Hello</p><script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->settings()->set(AppearanceSettings::HEADER_COMPILED_KEY, json_encode(['hash' => 'abc', 'layout' => $stored]));
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('alert(', $html);
        $this->assertDoesNotMatchRegularExpression('/<script/i', $html);
    }

    // ---- heights ----------------------------------------------------------------------------------------------------------------------

    public function test_non_default_heights_are_variables_in_the_token_stylesheet_and_nowhere_else(): void
    {
        $layout = $this->layout(function (array $l): array {
            $l['desktop']['rows']['main']['height'] = 52;
            $l['desktop']['rows']['main']['center'][0]['settings']['height'] = 24;
            $l['mobile']['rows']['main']['height'] = 48;

            return $l;
        });
        $this->publish($layout);
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);
        $css = Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');

        foreach (['--sf-hdr-d-main-h: 52px;', '--sf-logo-d-h: 24px;', '--sf-hdr-m-main-h: 48px;'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }

        foreach (['--sf-hdr-d-top-h', '--sf-hdr-d-bottom-h', '--sf-hdr-m-top-h', '--sf-logo-m-h'] as $default) {
            $this->assertStringNotContainsString($default, $css, "{$default} holds its default: not written");
        }

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'no style attribute anywhere in the page');
        $this->assertStringNotContainsString('52px', $html);
        $this->assertStringContainsString('/storage/appearance/tokens-'.$compiled['hash'].'.css', $html, 'ONE stylesheet, ONE link');
        $this->assertSame(1, substr_count($html, '/storage/appearance/'));
    }

    public function test_the_default_heights_write_nothing(): void
    {
        $this->publish(HeaderLayout::default());

        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'a published default needs no stylesheet');
        $this->assertSame([], Storage::disk('public')->files('appearance'));
        $this->assertStringNotContainsString('/storage/appearance/', $this->get('/')->getContent());
    }

    public function test_an_appearance_save_keeps_the_published_header_sizes_in_the_stylesheet(): void
    {
        $this->publish($this->layout(function (array $l): array {
            $l['desktop']['rows']['main']['height'] = 52;

            return $l;
        }));

        $compiled = app(AppearanceCompiler::class)->compile(['appearance.color.primary' => '#123456']);
        $css = Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');

        $this->assertStringContainsString('--sf-primary: #123456;', $css);
        $this->assertStringContainsString('--sf-hdr-d-main-h: 52px;', $css, 'the colour save did not drop the header size');
    }

    public function test_the_metrics_are_what_the_stylesheet_fallbacks_say(): void
    {
        $this->assertSame(['--sf-hdr-d-top-h' => 36, '--sf-hdr-d-main-h' => 64, '--sf-hdr-d-bottom-h' => 44, '--sf-logo-d-h' => 28, '--sf-hdr-m-top-h' => 32, '--sf-hdr-m-main-h' => 56, '--sf-logo-m-h' => 26], HeaderLayout::metricDefaults());

        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        foreach (HeaderLayout::metricDefaults() as $variable => $default) {
            $this->assertStringContainsString("var({$variable}, {$default}px)", $css, "{$variable} has its default as the fallback");
        }

        $this->expectException(\InvalidArgumentException::class);
        app(AppearanceCompiler::class)->css([], ['--sf-evil' => 5]);
    }

    // ---- publish, draft, preview ------------------------------------------------------------------------------------------------------------

    private function withTopText(string $text): array
    {
        return $this->layout(function (array $l) use ($text): array {
            $l['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'text', 'settings' => ['text' => $text, 'url' => '']]], 'center' => [], 'right' => []];

            return $l;
        });
    }

    public function test_publishing_shows_the_new_header_and_the_default_before_it(): void
    {
        $this->assertStringNotContainsString('Call us 0888', $this->get('/')->getContent());

        $this->publish($this->withTopText('Call us 0888 123 456'));
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="sf-hdr-text">Call us 0888 123 456</span>', $html);
        $this->assertStringContainsString('sf-hdr-row-top', $html);
        $this->assertSame(HeaderCompiler::compiled($this->withTopText('Call us 0888 123 456'))['hash'], json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true)['hash']);
        $this->assertSame($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY) !== null, Cache::get(StorefrontAppearance::CACHE_KEY)['header'] !== null);
    }

    public function test_publishing_an_invalid_layout_throws_and_changes_nothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            app(HeaderCompiler::class)->publish($this->layout(function (array $l): array {
                $l['desktop']['rows']['main']['height'] = 5;

                return $l;
            }), $this->settings());
        } finally {
            $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY));
            $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
        }
    }

    public function test_a_draft_is_never_shown_to_the_public(): void
    {
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->withTopText('DRAFT-ONLY-TEXT'))->layout));
        $this->fresh();

        foreach (['/', '/product-category/clothing', '/product/dress', '/cart', '/checkout'] as $url) {
            $this->assertStringNotContainsString('DRAFT-ONLY-TEXT', $this->get($url)->assertOk()->getContent(), $url);
        }
    }

    public function test_the_preview_needs_a_valid_signature_expires_and_is_noindex_and_not_cached(): void
    {
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->withTopText('DRAFT-ONLY-TEXT'))->layout));
        $this->fresh();

        $this->get('/header-preview')->assertNotFound();
        $this->get('/header-preview?signature=forged')->assertNotFound();

        $url = HeaderPreviewController::previewUrl();
        $response = $this->get($url)->assertOk();

        $this->assertStringContainsString('DRAFT-ONLY-TEXT', $response->getContent());
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $response->getContent());
        $this->assertStringNotContainsString('rel="canonical"', $response->getContent());

        // A changed query string breaks the signature.
        $this->get($url.'&x=1')->assertNotFound();

        // 30 minutes later it is gone.
        $this->travel(HeaderPreviewController::MINUTES + 1)->minutes();
        $this->get($url)->assertNotFound();
        $this->travelBack();

        // And the public page still shows the PUBLISHED header, not the draft (the next request: the scoped preview state is gone).
        $this->fresh();
        $this->assertStringNotContainsString('DRAFT-ONLY-TEXT', $this->get('/')->getContent());
    }

    public function test_the_preview_of_a_draft_with_another_height_links_its_own_small_stylesheet(): void
    {
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->layout(function (array $l): array {
            $l['desktop']['rows']['main']['height'] = 90;

            return $l;
        }))->layout));
        $this->fresh();

        $html = $this->get(HeaderPreviewController::previewUrl())->assertOk()->getContent();
        preg_match('#href="(/storage/appearance/header-preview-[0-9a-f]{12}\.css)"#', $html, $m);

        $this->assertNotEmpty($m);
        $this->assertStringContainsString('--sf-hdr-d-main-h: 90px;', Storage::disk('public')->get(substr($m[1], strlen('/storage/'))));
        $this->fresh();
        $this->assertStringNotContainsString('header-preview', $this->get('/')->getContent(), 'the public page never links it');
    }

    // ---- features that do not exist yet ---------------------------------------------------------------------------------------------------

    public function test_search_account_and_wishlist_are_stored_but_print_nothing_until_their_routes_exist(): void
    {
        $layout = $this->layout(function (array $l): array {
            $l['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'wishlist', 'settings' => ['style' => 'icon', 'show_count' => true]]], 'center' => [], 'right' => []];

            return $l;
        });
        $this->publish($layout);
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['data-sf-search-toggle', 'sf-search-row', 'sf-hdr-icon-btn', 'sf-hdr-link', 'sf-hdr-icon', 'sf-hdr-row-top'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, "{$absent}: no empty element and no empty row");
        }

        $this->assertNotNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), 'but the elements ARE stored');
        $this->assertStringContainsString('"type":"wishlist"', (string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY));
    }

    public function test_with_the_routes_registered_the_elements_and_the_search_row_appear(): void
    {
        foreach (['search', 'account', 'wishlist'] as $name) {
            Route::get('/'.$name, fn () => $name)->name('storefront.'.$name);
        }

        app('router')->getRoutes()->refreshNameLookups();
        $this->publish($this->layout(function (array $l): array {
            $l['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'wishlist', 'settings' => ['style' => 'icon', 'show_count' => false]]], 'center' => [], 'right' => []];

            return $l;
        }));
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-sf-search-toggle', $html);
        $this->assertSame(1, substr_count($html, 'id="sf-search-row"'));
        $this->assertStringContainsString('href="/account"', $html);
        $this->assertStringContainsString('href="/wishlist"', $html);
        $this->assertStringContainsString('role="search"', $html);
        $this->assertNoActiveContent(preg_replace('#<form[ >].*?</form>#s', '', $html), 'the header with every icon');
    }

    // ---- an extension's element ---------------------------------------------------------------------------------------------------------

    private function extensionView(): string
    {
        $dir = sys_get_temp_dir().'/sf-hdr-ext-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/promo.blade.php', '<span class="ext-promo">EXT {{ $settings["label"] }}</span>');
        View::addNamespace('hdrext', $dir);

        return $dir;
    }

    public function test_an_extension_adds_an_element_type_that_is_validated_and_rendered(): void
    {
        $dir = $this->extensionView();

        try {
            Hook::filter(HeaderCatalog::HOOK, static fn (array $catalog): array => $catalog + ['promo' => ['label' => 'Promo', 'schema' => ['label' => ['kind' => 'text', 'max' => 20, 'default' => 'Sale']], 'view' => 'hdrext::promo']]);

            $this->assertArrayHasKey('promo', HeaderCatalog::all());
            $good = $this->layout(function (array $l): array {
                $l['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'promo', 'settings' => ['label' => 'Big sale']]], 'center' => [], 'right' => []];

                return $l;
            });
            $this->assertTrue(HeaderValidator::validate($good)->passes());

            $bad = $good;
            $bad['desktop']['rows']['top']['left'][0]['settings']['label'] = str_repeat('a', 21);
            $this->assertArrayHasKey('desktop.rows.top.left.0.settings.label', HeaderValidator::validate($bad)->errors, 'validated by the schema the extension declared');

            $this->publish($good);
            $this->assertStringContainsString('<span class="ext-promo">EXT Big sale</span>', $this->get('/')->getContent());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_a_filter_that_replaces_or_removes_a_built_in_element_is_ignored_and_logged_once(): void
    {
        Log::spy();
        HeaderCatalog::forgetWarnings();
        Hook::filter(HeaderCatalog::HOOK, static function (array $catalog): array {
            $catalog['logo']['schema'] = ['evil' => ['kind' => 'text', 'default' => '']];
            unset($catalog['cart']);

            return $catalog;
        });

        $all = HeaderCatalog::all();
        HeaderCatalog::all();

        $this->assertSame(HeaderCatalog::builtin(), $all, 'the built-ins won');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_extension_element_with_an_invalid_descriptor_is_skipped(): void
    {
        Hook::filter(HeaderCatalog::HOOK, static fn (array $catalog): array => $catalog + [
            'Bad-Type' => ['label' => 'x', 'schema' => [], 'view' => 'a.b'],
            'noview' => ['label' => 'x', 'schema' => []],
            'weird' => ['label' => 'x', 'schema' => ['f' => ['kind' => 'php']], 'view' => 'a.b'],
            'fine' => ['label' => 'Fine', 'schema' => [], 'view' => 'a.b'],
        ]);

        $types = array_keys(HeaderCatalog::all());

        $this->assertContains('fine', $types);

        foreach (['Bad-Type', 'noview', 'weird'] as $bad) {
            $this->assertNotContains($bad, $types);
        }
    }

    // ---- the markup ----------------------------------------------------------------------------------------------------------------------

    public function test_ids_are_unique_although_both_layouts_are_in_the_page_and_the_landmarks_are_there(): void
    {
        $this->mainMenuOfTopCategories();
        Route::get('/search', fn () => 's')->name('storefront.search');
        app('router')->getRoutes()->refreshNameLookups();
        $this->publish($this->layout(function (array $l): array {
            // Search, a field search in the drawer and a second menu make the ids interesting.
            $l['mobile']['drawer'] = [['type' => 'menu', 'settings' => ['source' => 'categories', 'style' => 'links']], ['type' => 'account', 'settings' => ['style' => 'icon']]];

            return $l;
        }));
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $counts = array_count_values($m[1]);

        $this->assertSame([], array_keys(array_filter($counts, fn (int $n): bool => $n > 1)), 'no id twice');
        $this->assertSame(1, substr_count($html, 'id="sf-drawer"'));
        $this->assertSame(1, substr_count($html, 'id="sf-search-row"'));
        $this->assertSame(1, substr_count($html, '<header '));
        $this->assertMatchesRegularExpression('/<nav class="sf-hdr-menu sf-hdr-menu-links" id="sf-menu-desktop-main-left-0" aria-label="Menu" data-sf-menubar>/', $html);
        $this->assertMatchesRegularExpression('/<nav class="sf-hdr-menu sf-hdr-menu-drawer" aria-label="Categories">/', $html);
        $this->assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="sf-drawer-title"', $html);
        $this->assertMatchesRegularExpression('/<button class="sf-hdr-icon-btn" type="button" aria-controls="sf-search-row" aria-expanded="false" data-sf-search-toggle>/', $html);
        $this->assertNoActiveContent(preg_replace('#<form[ >].*?</form>#s', '', $html), 'header');
    }

    public function test_the_two_layouts_are_both_in_the_page_and_the_sticky_flags_are_classes(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<header class="sf-header sf-hdr sf-hdr-d-sticky sf-hdr-m-sticky" data-sf-header>', $html);
        $this->assertSame(1, substr_count($html, 'sf-hdr-layout sf-hdr-desktop'));
        $this->assertSame(1, substr_count($html, 'sf-hdr-layout sf-hdr-mobile'));

        $this->publish($this->layout(function (array $l): array {
            $l['desktop']['sticky'] = false;
            $l['mobile']['drawer_side'] = 'right';

            return $l;
        }));
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<header class="sf-header sf-hdr sf-hdr-m-sticky"', $html);
        $this->assertStringContainsString('sf-drawer sf-drawer-right', $html);
    }

    public function test_the_menus_are_reachable_without_the_script(): void
    {
        $this->mainMenuOfTopCategories();
        $html = $this->get('/')->getContent();
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="/product-category/clothing"'), 'the desktop menu and the drawer both link the categories as plain links');
        $this->assertMatchesRegularExpression('/@media \(scripting: none\) \{\s*\.sf-drawer \{\s*position: static;\s*visibility: visible;/', $css, 'no script: the drawer is a plain block under the header');
        $this->assertStringContainsString('html.js .sf-hdr .sf-menu-toggle {', $css, 'the toggle shows only once the script has run');
    }

    public function test_social_icons_are_a_closed_set_and_links_are_https_only(): void
    {
        $items = array_map(static fn (string $n): array => ['network' => $n, 'url' => 'https://example.com/'.$n], HeaderCatalog::SOCIAL_NETWORKS);
        $this->publish($this->layout(function (array $l) use ($items): array {
            $l['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'social', 'settings' => ['items' => $items]]], 'center' => [], 'right' => []];

            return $l;
        }));
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(8, substr_count($html, 'class="sf-social-icon"'));

        foreach (HeaderCatalog::SOCIAL_NETWORKS as $network) {
            $this->assertStringContainsString('href="https://example.com/'.$network.'" rel="noopener noreferrer"', $html);
        }

        $this->assertNoActiveContent($html, 'social icons');
    }

    public function test_a_button_and_a_text_link_print_escaped_text_and_safe_links(): void
    {
        $this->publish($this->layout(function (array $l): array {
            $l['desktop']['rows']['top'] = ['enabled' => true, 'height' => 36, 'left' => [['type' => 'text', 'settings' => ['text' => '<b>Phone</b> & more', 'url' => '/contacts']]], 'center' => [], 'right' => [['type' => 'button', 'settings' => ['label' => 'Shop <now>', 'url' => 'https://example.com/shop', 'style' => 'outline']]]];

            return $l;
        }));
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<a class="sf-hdr-text sf-hdr-text-link" href="/contacts">&lt;b&gt;Phone&lt;/b&gt; &amp; more</a>', $html);
        $this->assertStringContainsString('<a class="sf-hdr-button sf-hdr-button-outline" href="https://example.com/shop">Shop &lt;now&gt;</a>', $html);
    }

    public function test_the_header_is_lower_than_the_old_one_by_construction(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        // The old header was a bar (12px padding + a 32px logo) PLUS a second row for the categories on desktop; the new main row is 64px with the categories inside it.
        $this->assertSame(64, HeaderLayout::ROW_HEIGHTS['desktop']['main'][2]);
        $this->assertSame(56, HeaderLayout::ROW_HEIGHTS['mobile']['main'][2]);
        $this->assertStringContainsString('.sf-hdr-desktop .sf-hdr-row-main {', $css);
        $this->assertStringNotContainsString('<nav class="sf-nav"', $this->get('/')->getContent(), 'no second nav row any more');
    }

    // ---- the query numbers ------------------------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function settingsQueries(string $url): array
    {
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }

        $this->app->forgetScopedInstances();
        $found = [];
        DB::listen(function ($query) use (&$found): void {
            if (str_contains($query->sql, 'site_settings')) {
                $found[] = $query->sql.' | '.json_encode($query->bindings);
            }
        });

        $this->get($url)->assertOk();

        return $found;
    }

    public function test_a_published_header_costs_no_query_of_its_own(): void
    {
        $count = function (): array {
            Cache::forget(StorefrontAppearance::CACHE_KEY);
            $cold = $this->settingsQueries('/');

            return [count($cold), count($this->settingsQueries('/'))];
        };

        [$defaultCold, $defaultWarm] = $count();

        $this->publish($this->withTopText('Query probe'));
        [$publishedCold, $publishedWarm] = $count();

        $this->assertSame($defaultCold, $publishedCold, 'a published header reads with the same ONE appearance query (header.compiled is in its getMany)');
        $this->assertSame($defaultWarm, $publishedWarm);
        $this->assertSame($defaultCold - 1, $defaultWarm, 'cold = warm + the ONE appearance read');
        $this->assertContains(AppearanceSettings::HEADER_COMPILED_KEY, AppearanceSettings::READ_KEYS);

        $hits = array_filter($this->coldQueries(), static fn (string $sql): bool => str_contains($sql, AppearanceSettings::HEADER_COMPILED_KEY));
        $this->assertCount(1, $hits, 'exactly one query names header.compiled');
    }

    /** @return list<string> */
    private function coldQueries(): array
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);

        return $this->settingsQueries('/');
    }
}
