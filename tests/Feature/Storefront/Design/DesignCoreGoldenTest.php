<?php

namespace Tests\Feature\Storefront\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderValidator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * DS-1, the safety net of the extraction (design-screen-design.md section 9): SNAPSHOTS made by the code as it was BEFORE the shared core existed (tests/Feature/Storefront/Design/fixtures)
 * for the header and the footer: the validator's normalised layout, the compiled payload with its hash, the exact messages for ~20 layouts with ONE fault each, and the rendered
 * header / footer HTML of the home page with the layout published. The test compares the current code with them and is NOT changed by the refactoring. Regenerate only on purpose:
 * `GOLDEN_WRITE=1 php artisan test --filter=DesignCoreGoldenTest` (and read the diff of the JSON files like a code change).
 */
class DesignCoreGoldenTest extends TestCase
{
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
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1, 2], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'children' => [$this->menuItem('category', ['target_id' => 2])]]), $this->menuItem('link', ['label' => 'Contacts', 'url' => '/contacts', 'badge_text' => 'NEW', 'badge_color' => 'red'])]);
        $this->saveMenu('footer', [$this->menuItem('link', ['label' => 'Terms', 'url' => '/info/terms']), $this->menuItem('link', ['label' => 'Returns', 'url' => '/info/returns', 'new_tab' => true])]);
        HtmlBlockModel::query()->forceCreate(['id' => DesignFixtures::BLOCK_ID, 'title' => 'Banner', 'body' => '<p>Banner <a href="/sale">text</a></p>']);

        foreach (['search', 'account', 'wishlist'] as $name) {
            Route::get('/'.$name, fn () => $name)->name('storefront.'.$name);
        }

        Route::post('/newsletter', fn () => 'ok')->name('storefront.newsletter.subscribe');
        app('router')->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    /** Compares with `fixtures/{$name}.json` (or writes it when GOLDEN_WRITE=1). @param  mixed  $actual */
    private function golden(string $name, mixed $actual): void
    {
        $path = __DIR__.'/fixtures/'.$name.'.json';
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (getenv('GOLDEN_WRITE') === '1') {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $json);
        }

        $this->assertFileExists($path, "the snapshot {$name} is missing (GOLDEN_WRITE=1 makes it from the current code)");
        $this->assertSame((string) file_get_contents($path), $json, "the output for {$name} changed");
    }

    /** @return array<string, array{0: string}> */
    public static function validHeaders(): array
    {
        return ['default' => ['default'], 'large' => ['large']];
    }

    /** @return array<string, array{0: string}> */
    public static function validFooters(): array
    {
        return ['default' => ['default'], 'large' => ['large']];
    }

    private function header(string $which): array
    {
        return $which === 'default' ? DesignFixtures::headerDefault() : DesignFixtures::headerLarge();
    }

    private function footer(string $which): array
    {
        return $which === 'default' ? DesignFixtures::footerDefault() : DesignFixtures::footerLarge();
    }

    #[DataProvider('validHeaders')]
    public function test_the_header_validator_and_the_compiled_payload(string $which): void
    {
        $validation = HeaderValidator::validate($this->header($which));

        $this->assertSame([], $validation->errors);
        $this->golden("header-{$which}-normalised", $validation->layout);
        $this->golden("header-{$which}-compiled", HeaderCompiler::compiled($this->header($which)));
    }

    #[DataProvider('validFooters')]
    public function test_the_footer_validator_and_the_compiled_payload(string $which): void
    {
        $validation = FooterValidator::validate($this->footer($which));

        $this->assertSame([], $validation->errors);
        $this->golden("footer-{$which}-normalised", $validation->layout);
        $this->golden("footer-{$which}-compiled", FooterCompiler::compiled($validation->layout));
    }

    public function test_every_invalid_header_gives_the_same_messages_and_the_same_normalised_layout(): void
    {
        $out = [];

        foreach (DesignFixtures::headerInvalid() as $name => $break) {
            $validation = HeaderValidator::validate($break(DesignFixtures::headerLarge()));
            $out[$name] = ['errors' => $validation->errors, 'layout' => $validation->layout];
        }

        $this->golden('header-invalid', $out);
        $this->assertGreaterThanOrEqual(15, count($out));
    }

    public function test_every_invalid_footer_gives_the_same_messages_and_the_same_normalised_layout(): void
    {
        $out = [];

        foreach (DesignFixtures::footerInvalid() as $name => $break) {
            $validation = FooterValidator::validate($break(DesignFixtures::footerLarge()));
            $out[$name] = ['errors' => $validation->errors, 'layout' => $validation->layout];
        }

        $this->golden('footer-invalid', $out);
        $this->assertGreaterThanOrEqual(15, count($out));
    }

    #[DataProvider('validHeaders')]
    public function test_the_rendered_header_html(string $which): void
    {
        app(HeaderCompiler::class)->publish($this->header($which), $this->settings());
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();

        $this->golden("header-{$which}-html", $this->between($html, '<header', '</header>'));
    }

    #[DataProvider('validFooters')]
    public function test_the_rendered_footer_html(string $which): void
    {
        app(FooterCompiler::class)->publish($this->footer($which), $this->settings());
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();

        $this->golden("footer-{$which}-html", $this->between($html, '<footer', '</footer>'));
    }

    private function between(string $html, string $open, string $close): string
    {
        $start = strpos($html, $open);
        $end = strpos($html, $close, (int) $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end + strlen($close) - $start);
    }
}
