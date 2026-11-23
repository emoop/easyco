<?php

namespace Tests\Feature\Logo;

use App\Settings\SiteLogo;
use PHPUnit\Framework\TestCase;

/**
 * LOGO-1 (site-logo.md): the PURE parsing of the shop name and of `logo.compiled`. Everything the storefront, the admin and the mail read passes through it again: a damaged or tampered value gives
 * null (every output falls back to today's static files), never a half-trusted one.
 */
class SiteLogoPayloadTest extends TestCase
{
    /** @return array<string, mixed> a valid compiled value */
    private function valid(): array
    {
        return [
            'icon' => [
                'hash' => 'a1b2c3d4e5f6',
                'png32' => ['url' => '/storage/appearance/icon-a1b2c3d4e5f6-32.png', 'w' => 32, 'h' => 32],
                'png180' => ['url' => '/storage/appearance/icon-a1b2c3d4e5f6-180.png', 'w' => 180, 'h' => 180],
                'ico' => ['url' => '/storage/appearance/icon-a1b2c3d4e5f6.ico'],
            ],
            'main' => ['hash' => '0123456789ab', 'png' => ['url' => '/storage/appearance/logo-0123456789ab.png', 'w' => 400, 'h' => 100]],
            'dark' => ['hash' => 'ffffffffffff', 'png' => ['url' => '/storage/appearance/logo-ffffffffffff-dark.png', 'w' => 300, 'h' => 80]],
        ];
    }

    private function parse(array $compiled): ?array
    {
        return SiteLogo::parseCompiled(json_encode($compiled));
    }

    public function test_a_valid_value_is_read_back_as_it_is(): void
    {
        $this->assertSame($this->valid(), $this->parse($this->valid()));
        $only = ['main' => $this->valid()['main']];
        $this->assertSame(['icon' => null, 'main' => $only['main'], 'dark' => null], $this->parse($only));
    }

    /** ★ each kind of damage makes the WHOLE value null */
    public function test_a_tampered_or_damaged_value_is_ignored(): void
    {
        $this->assertNull(SiteLogo::parseCompiled(null));
        $this->assertNull(SiteLogo::parseCompiled(''));
        $this->assertNull(SiteLogo::parseCompiled('{not json'));
        $this->assertNull(SiteLogo::parseCompiled('[]'));
        $this->assertNull(SiteLogo::parseCompiled('"text"'));

        $mutations = [
            'an address outside the folder' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['url' => 'https://evil.example/x.png']]]),
            'a path that climbs' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['url' => '/storage/appearance/../logo-0123456789ab.png']]]),
            'a name that is not ours' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['url' => '/storage/appearance/tokens-0123456789ab.css']]]),
            'a script address' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['url' => 'javascript:alert(1)']]]),
            'a bad hash' => fn (array $c): array => array_replace_recursive($c, ['main' => ['hash' => 'XYZ']]),
            'an upper-case hash' => fn (array $c): array => array_replace_recursive($c, ['icon' => ['hash' => 'A1B2C3D4E5F6']]),
            'a width that is not an int' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['w' => '400']]]),
            'a logo wider than the maximum' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['w' => 401]]]),
            'a zero height' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['h' => 0]]]),
            'an icon that is not 32 px' => fn (array $c): array => array_replace_recursive($c, ['icon' => ['png32' => ['w' => 33]]]),
            'an ico that is a png' => fn (array $c): array => array_replace_recursive($c, ['icon' => ['ico' => ['url' => '/storage/appearance/icon-a1b2c3d4e5f6-32.png']]]),
            'a png that is an ico' => fn (array $c): array => array_replace_recursive($c, ['main' => ['png' => ['url' => '/storage/appearance/icon-a1b2c3d4e5f6.ico']]]),
            'an unknown part' => fn (array $c): array => $c + ['evil' => ['x' => 1]],
            'a part that is not an object' => fn (array $c): array => ['main' => 'x'] + $c,
        ];

        foreach ($mutations as $name => $mutate) {
            $this->assertNull($this->parse($mutate($this->valid())), $name);
        }

        $missingIcon = $this->valid();
        unset($missingIcon['icon']['ico']);
        $this->assertNull($this->parse($missingIcon), 'an icon without its .ico');
    }

    public function test_the_name_is_plain_text_of_one_to_eighty_characters(): void
    {
        $this->assertSame('Моят магазин', SiteLogo::cleanName('  Моят магазин  '));
        $this->assertSame(str_repeat('я', 80), SiteLogo::cleanName(str_repeat('я', 80)));

        foreach ([null, '', '   ', str_repeat('я', 81), "a\nb", "a\tb", "a\u{202E}b", "\u{0000}", 12, ['x']] as $bad) {
            $this->assertNull(SiteLogo::cleanName($bad), var_export($bad, true));
        }

        // markup is plain text here: it is escaped where it is printed, never stripped on the way in
        $this->assertSame('<b>Shop</b> & Co', SiteLogo::cleanName('<b>Shop</b> & Co'));
    }

    public function test_the_payload_carries_a_clean_name_and_a_valid_logo_only(): void
    {
        $payload = SiteLogo::payload([SiteLogo::NAME => ' Shop ', SiteLogo::COMPILED => json_encode($this->valid())]);
        $this->assertSame('Shop', $payload['name']);
        $this->assertSame($this->valid(), $payload['logo']);

        $payload = SiteLogo::payload([SiteLogo::NAME => str_repeat('x', 200), SiteLogo::COMPILED => '{"main": 1}']);
        $this->assertSame(['name' => null, 'logo' => null], $payload);
        $this->assertSame(['name' => null, 'logo' => null], SiteLogo::payload([]));
    }
}
