<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MENU-2: the header's `menu` element has no `source` any more; it shows the menu of its LOCATION (the rendering is tested in MenusTest). A layout stored before the change
 * still validates, and the old setting is dropped on the way.
 */
class Menu2HeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_menu_element_has_only_its_style(): void
    {
        // DS-2a.1: the element now also names a menu (`menu`, null = the menu of its place)
        $this->assertSame(['menu', 'style'], array_keys(HeaderCatalog::all()['menu']['schema']));
        $this->assertSame(['menu'], [HeaderLayout::default()['desktop']['rows']['main']['left'][0]['type']]);
        $this->assertSame(['menu' => null, 'style' => 'links'], HeaderLayout::default()['desktop']['rows']['main']['left'][0]['settings']);
    }

    public function test_a_layout_stored_with_a_source_still_validates_and_the_source_is_dropped(): void
    {
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings'] = ['source' => 'footer', 'style' => 'dropdown'];
        $layout['mobile']['drawer'][0]['settings'] = ['source' => 'categories', 'style' => 'links'];

        $validation = HeaderValidator::validate($layout);

        $this->assertTrue($validation->passes(), json_encode($validation->errors));
        $this->assertSame(['menu' => null, 'style' => 'dropdown'], $validation->layout['desktop']['rows']['main']['left'][0]['settings']);
        $this->assertSame(['menu' => null, 'style' => 'links'], $validation->layout['mobile']['drawer'][0]['settings']);

        app(HeaderCompiler::class)->publish($validation->layout, app(SiteSettingsRepository::class));
        $this->assertStringNotContainsString('"source"', (string) app(SiteSettingsRepository::class)->get(AppearanceSettings::HEADER_COMPILED_KEY));
    }

    public function test_another_unknown_setting_is_still_refused(): void
    {
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings'] = ['style' => 'links', 'colour' => 'red'];

        $this->assertFalse(HeaderValidator::validate($layout)->passes());
    }
}
