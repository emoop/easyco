<?php

namespace Tests\Feature\Logo;

use App\Mail\MailTemplates;
use App\Mail\TemplateRenderer;
use App\Storefront\Appearance\StorefrontAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesLogoFixtures;
use Tests\TestCase;

/**
 * LOGO-1 (site-logo.md): the logo as the header of every customer mail (all nine template keys through `wrap()`): an absolute public URL, the raster PNG, `width` / `height`, an escaped `alt`; none set =
 * byte for byte today's mail. And the guard: nothing prints `config('app.name')` directly any more, except the places the owner said to leave.
 */
class SiteLogoMailAndGuardTest extends TestCase
{
    use MakesLogoFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['app.name' => 'Raf Shop', 'app.url' => 'https://shop.example']);
    }

    /** @return list<string> every template key */
    private function keys(): array
    {
        $keys = [];

        foreach ((new \ReflectionClass(MailTemplates::class))->getConstants() as $value) {
            is_string($value) && preg_match('/^[a-z]+\.[a-z_]+$/', $value) === 1 && $keys[] = $value;
        }

        sort($keys);

        return $keys;
    }

    private function render(string $key): \App\Mail\RenderedMail
    {
        $definition = app(MailTemplates::class)->get($key);
        $scalars = array_fill_keys($definition->scalars, 'x');
        $blocks = array_map(static fn (string $name): string => '<p>block '.$name.'</p>', array_combine($definition->blocks, $definition->blocks));

        return app(TemplateRenderer::class)->render($definition, 'bg', $scalars, $blocks);
    }

    private function setLogo(?string $name = null): void
    {
        $this->saveLogoTab(['logo_main' => $this->logoAsset($this->pngBytes(800, 200))->id, 'site_name' => $name]);
    }

    /** ★ every one of the nine keys carries the logo header with an absolute URL, width / height and an alt */
    public function test_every_template_key_renders_the_logo_header(): void
    {
        $this->assertCount(9, $this->keys(), 'nine template keys');
        $this->setLogo('Acme Shop');
        $png = app(StorefrontAppearance::class)->siteLogo()['main']['png'];

        foreach ($this->keys() as $key) {
            $html = $this->render($key)->html;

            $this->assertSame(1, preg_match('#<img src="(https://shop\.example/storage/appearance/logo-[0-9a-f]{12}\.png)" alt="Acme Shop" width="(\d+)" height="(\d+)"#', $html, $m), "{$key}: no logo");
            $this->assertSame('https://shop.example'.$png['url'], $m[1], "{$key}: an ABSOLUTE url");
            $this->assertSame([200, 50], [(int) $m[2], (int) $m[3]], "{$key}: displayed at most 200 px wide (the file is {$png['w']}x{$png['h']})");
            $this->assertStringContainsString('<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222222;"><div style="max-width:640px;margin:0 auto 16px;"><img ', $html, $key);
            $this->assertSame(1, substr_count($html, '<img '), "{$key}: one image");
        }
    }

    /** ★ no logo: the mail is byte for byte what it was (no <img>, the same shell) and the plain text never changes */
    public function test_without_a_logo_the_mail_is_byte_identical(): void
    {
        $plain = [];

        foreach ($this->keys() as $key) {
            $mail = $this->render($key);
            $this->assertStringNotContainsString('<img', $mail->html, $key);
            $this->assertStringContainsString('<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222222;"><div style="max-width:640px;margin:0 auto;">', $mail->html, $key);
            $plain[$key] = [$mail->html, $mail->text];
        }

        $this->setLogo();

        foreach ($this->keys() as $key) {
            $this->assertSame($plain[$key][1], $this->render($key)->text, "{$key}: the plain-text part has no logo");
        }

        // taken away again: the very same bytes as before
        $this->saveLogoTab(['logo_main' => null]);

        foreach ($this->keys() as $key) {
            $this->assertSame($plain[$key][0], $this->render($key)->html, "{$key}: back to byte for byte");
        }
    }

    /** the alt is the shop name, escaped */
    public function test_the_alt_is_the_escaped_shop_name(): void
    {
        $this->setLogo('A <b>&</b> "B"');
        $html = $this->render('account.verify')->html;

        $this->assertStringContainsString('alt="A &lt;b&gt;&amp;&lt;/b&gt; &quot;B&quot;"', $html);
        $this->assertStringNotContainsString('<b>&</b>', substr($html, 0, 600));
    }

    /** ★ no absolute APP_URL, no image (a relative address is never put into a mail) */
    public function test_a_relative_app_url_gives_no_image(): void
    {
        $this->setLogo();
        config(['app.url' => '/']);

        $this->assertStringNotContainsString('<img', $this->render('account.verify')->html);

        config(['app.url' => 'shop.example']);
        $this->assertStringNotContainsString('<img', $this->render('account.verify')->html);

        config(['app.url' => 'http://shop.example/']);
        $this->assertStringContainsString('<img src="http://shop.example/storage/appearance/logo-', $this->render('account.verify')->html, 'a trailing slash is not doubled');
    }

    /** a logo is never wider than the mail asks: a small one keeps its size */
    public function test_a_small_logo_keeps_its_size(): void
    {
        $this->saveLogoTab(['logo_main' => $this->logoAsset($this->pngBytes(240, 60))->id]);
        $html = $this->render('account.verify')->html;

        $this->assertSame(1, preg_match('#width="(\d+)" height="(\d+)"#', $html, $m));
        $this->assertSame([200, 50], [(int) $m[1], (int) $m[2]]);

        $this->saveLogoTab(['logo_main' => $this->logoAsset($this->pngBytes(210, 60))->id]);
        $this->assertSame(1, preg_match('#width="(\d+)" height="(\d+)"#', $this->render('account.verify')->html, $m));
        $this->assertSame(200, (int) $m[1]);
    }

    // ---- the guard -------------------------------------------------------------------------------------------------------------

    /** ★ no raw `config('app.name')`: everything that prints the shop name asks ShopName */
    public function test_nothing_prints_app_name_directly_except_what_the_owner_left(): void
    {
        $allowed = [
            // the resolver and its pure fallback
            'app/Settings/ShopName.php', 'app/Settings/SiteLogo.php', 'app/Storefront/Appearance/StorefrontAppearance.php',
            // left on purpose (owner, LOGO-1): the sender's default name and the admin-login alarm's subject
            'app/Mail/MailConfigurator.php', 'app/Security/Lockout/AdminLoginAlarm.php',
            // a one-off migrator, the Laravel welcome page and the Design screen's own sample text
            'app/Content/Menus/Menu2Migrator.php', 'resources/views/welcome.blade.php', 'resources/views/filament/pages/design/partials/schema-checkout.blade.php',
        ];

        $found = [];
        $roots = [base_path('app'), base_path('resources/views')];

        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = str_replace('\\', '/', substr((string) $file, strlen(base_path()) + 1));

                if (! preg_match('/\.php$/', $path) || str_starts_with($path, 'app/Sandbox/') || in_array($path, $allowed, true)) {
                    continue;
                }

                if (preg_match('/config\(\s*[\'"]app\.name[\'"]|env\(\s*[\'"]APP_NAME[\'"]/', (string) file_get_contents((string) $file)) === 1) {
                    $found[] = $path;
                }
            }
        }

        $this->assertSame([], $found, 'these still print APP_NAME directly: use \App\Settings\ShopName::current()');
    }
}
