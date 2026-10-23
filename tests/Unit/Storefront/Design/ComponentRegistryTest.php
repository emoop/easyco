<?php

namespace Tests\Unit\Storefront\Design;

use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Header\HeaderCatalog;
use EasyCo\Extensibility\Hook;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

/** DS-1: the registry's two sets are the two catalogs in the one descriptor shape; the catalogs keep their hooks and their rules. */
class ComponentRegistryTest extends TestCase
{
    private const SHAPE = ['builtin', 'fields', 'icon', 'label', 'limits', 'requires_route', 'supports', 'view', 'zones'];

    public function test_the_header_set_is_the_header_catalog(): void
    {
        $set = ComponentRegistry::for('header');
        $catalog = HeaderCatalog::all();

        $this->assertSame(array_keys($catalog), array_keys($set));

        foreach ($catalog as $type => $descriptor) {
            $keys = array_keys($set[$type]);
            sort($keys);
            $this->assertSame(self::SHAPE, $keys, $type);
            $this->assertSame([$descriptor['label'], $descriptor['schema'], $descriptor['view'], $descriptor['builtin']], [$set[$type]['label'], $set[$type]['fields'], $set[$type]['view'], $set[$type]['builtin']], $type);
        }

        $this->assertSame(['max_per_layout' => 1], $set['logo']['limits']);
        $this->assertSame(['max_per_layout' => 1], $set['menu_toggle']['limits']);
        $this->assertSame([], $set['cart']['limits']);
        $this->assertSame('storefront.search', $set['search']['requires_route']);
        $this->assertSame('storefront.account', $set['account']['requires_route']);
        $this->assertSame('storefront.wishlist', $set['wishlist']['requires_route']);
        $this->assertNull($set['logo']['requires_route']);
        $this->assertContains('header.mobile.drawer', $set['menu']['zones']);
        $this->assertNotContains('header.mobile.drawer', $set['cart']['zones'], 'a cart is not offered in the drawer');
    }

    public function test_the_footer_set_is_the_footer_catalog(): void
    {
        $set = ComponentRegistry::for('footer');
        $catalog = FooterCatalog::all();

        $this->assertSame(array_keys($catalog), array_keys($set));

        foreach ($catalog as $type => $descriptor) {
            $keys = array_keys($set[$type]);
            sort($keys);
            $this->assertSame(self::SHAPE, $keys, $type);
            $this->assertSame([$descriptor['label'], $descriptor['schema'], $descriptor['view'], $descriptor['builtin']], [$set[$type]['label'], $set[$type]['fields'], $set[$type]['view'], $set[$type]['builtin']], $type);
            $this->assertSame(['footer.upper'], $set[$type]['zones']);
        }

        $this->assertSame(FooterCatalog::NEWSLETTER_ROUTE, $set['newsletter']['requires_route']);
        $this->assertNull($set['menu']['requires_route']);
    }

    public function test_an_extension_component_comes_through_the_hooks_of_the_catalogs(): void
    {
        Hook::filter(HeaderCatalog::HOOK, static fn (array $catalog): array => $catalog + ['promo' => ['label' => 'Promo', 'schema' => ['label' => ['kind' => 'text', 'max' => 20, 'default' => '']], 'view' => 'a.b']]);
        Hook::filter(FooterCatalog::HOOK, static fn (array $catalog): array => $catalog + ['promo' => ['label' => 'Promo', 'schema' => ['label' => ['kind' => 'text', 'max' => 20, 'default' => '']], 'view' => 'a.b']]);

        foreach (['header', 'footer'] as $zone) {
            $set = ComponentRegistry::for($zone);

            $this->assertArrayHasKey('promo', $set, $zone);
            $this->assertFalse($set['promo']['builtin']);
            $this->assertSame('puzzle', $set['promo']['icon']);
        }
    }

    public function test_a_hook_that_replaces_or_removes_a_built_in_is_ignored_and_logged_once_for_both_sets(): void
    {
        Log::spy();
        HeaderCatalog::forgetWarnings();
        FooterCatalog::forgetWarnings();
        Hook::filter(HeaderCatalog::HOOK, static function (array $catalog): array {
            $catalog['logo']['schema'] = ['evil' => ['kind' => 'text', 'default' => '']];
            unset($catalog['cart']);

            return $catalog;
        });
        Hook::filter(FooterCatalog::HOOK, static function (array $catalog): array {
            $catalog['menu']['schema'] = ['evil' => ['kind' => 'text', 'default' => '']];
            unset($catalog['text']);

            return $catalog;
        });

        $header = ComponentRegistry::for('header');
        ComponentRegistry::for('header');
        $footer = ComponentRegistry::for('footer');
        ComponentRegistry::for('footer');

        $this->assertSame(HeaderCatalog::builtin()['logo']['schema'], $header['logo']['fields']);
        $this->assertArrayHasKey('cart', $header);
        $this->assertSame(FooterCatalog::builtin()['menu']['schema'], $footer['menu']['fields']);
        $this->assertArrayHasKey('text', $footer);
        Log::shouldHaveReceived('warning')->times(2);
    }

    public function test_an_unknown_zone_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentRegistry::for('sidebar');
    }
}
