<?php

namespace Tests\Feature\Admin\Design;

use App\Content\Menus\DesignMenuUsage;
use App\Content\Menus\MenuException;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuWriter;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Header\HeaderLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-3: a menu a «Дизайн» zone uses — a `menu` field of a header element or of a footer block, in the PUBLISHED or the DRAFT layout — is never deleted. The refusal names the zone («Дизайн — Хедър»,
 * «Дизайн — Футър», a draft says «(чернова)»); an unused menu deletes as before; the layouts are read in a FIXED number of queries however many menus are asked about.
 */
class DesignMenuUsageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    private function menu(string $key): MenuModel
    {
        return MenuModel::query()->where('key', $key)->first() ?? MenuModel::create(['key' => $key, 'name' => ucfirst($key).' menu']);
    }

    private function store(string $key, array $layout): void
    {
        app(SiteSettingsRepository::class)->set($key, (string) json_encode($layout));
    }

    private function headerWith(int $menu): array
    {
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings']['menu'] = $menu;

        return $layout;
    }

    private function footerWith(int $menu): array
    {
        $layout = FooterLayout::default();
        $layout['upper']['rows'][0]['columns'][0]['blocks'][0]['settings']['menu'] = $menu;

        return $layout;
    }

    private function refusal(MenuModel $menu): ?string
    {
        try {
            app(MenuWriter::class)->delete($menu);
        } catch (MenuException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_a_menu_in_the_published_header_is_refused_with_the_zone_named(): void
    {
        $menu = $this->menu('promo');
        $this->store(AppearanceSettings::HEADER_PUBLISHED_KEY, $this->headerWith($menu->id));

        $this->assertSame('The menu "Promo menu" is used in Design — Header and cannot be deleted.', $this->refusal($menu));
        $this->assertNotNull(MenuModel::query()->find($menu->id));
    }

    public function test_a_menu_in_a_footer_draft_is_refused_and_the_label_says_draft(): void
    {
        $menu = $this->menu('promo');
        $this->store(AppearanceSettings::FOOTER_DRAFT_KEY, $this->footerWith($menu->id));

        $this->assertSame('The menu "Promo menu" is used in Design — Footer (draft) and cannot be deleted.', $this->refusal($menu));
        $this->assertNotNull(MenuModel::query()->find($menu->id));
    }

    public function test_a_menu_in_a_published_footer_block_is_refused(): void
    {
        $menu = $this->menu('promo');
        $this->store(AppearanceSettings::FOOTER_PUBLISHED_KEY, $this->footerWith($menu->id));

        $this->assertStringContainsString('Design — Footer', (string) $this->refusal($menu));
        $this->assertNotNull(MenuModel::query()->find($menu->id));
    }

    public function test_the_drawers_menu_element_counts_too(): void
    {
        $menu = $this->menu('promo');
        $layout = HeaderLayout::default();
        $layout['mobile']['drawer'][0]['settings']['menu'] = $menu->id;
        $this->store(AppearanceSettings::HEADER_DRAFT_KEY, $layout);

        $this->assertStringContainsString('Design — Header (draft)', (string) $this->refusal($menu));
    }

    public function test_every_place_is_named_once_and_a_menu_in_both_zones_names_both(): void
    {
        $menu = $this->menu('promo');
        $this->store(AppearanceSettings::HEADER_PUBLISHED_KEY, $this->headerWith($menu->id));
        $this->store(AppearanceSettings::HEADER_DRAFT_KEY, $this->headerWith($menu->id));
        $this->store(AppearanceSettings::FOOTER_PUBLISHED_KEY, $this->footerWith($menu->id));

        $this->assertSame(['Design — Header', 'Design — Header (draft)', 'Design — Footer'], app(DesignMenuUsage::class)->of($menu->id));
    }

    public function test_the_bulgarian_reason_is_the_one_the_owner_asked_for(): void
    {
        app()->setLocale('bg');
        $menu = $this->menu('promo');
        $this->store(AppearanceSettings::FOOTER_PUBLISHED_KEY, $this->footerWith($menu->id));

        $this->assertSame('Менюто „Promo menu“ се използва в Дизайн — Футър и не може да се изтрие.', $this->refusal($menu));
    }

    public function test_a_menu_the_design_does_not_use_is_deleted_as_before(): void
    {
        $used = $this->menu('promo');
        $free = $this->menu('spare');
        $this->store(AppearanceSettings::FOOTER_PUBLISHED_KEY, $this->footerWith($used->id));
        $this->store(AppearanceSettings::HEADER_DRAFT_KEY, $this->headerWith($used->id));

        $this->assertNull($this->refusal($free));
        $this->assertNull(MenuModel::query()->find($free->id));
        $this->assertNotNull(MenuModel::query()->find($used->id));
    }

    public function test_the_menu_is_free_again_when_the_zone_stops_using_it(): void
    {
        $menu = $this->menu('promo');
        $this->store(AppearanceSettings::FOOTER_PUBLISHED_KEY, $this->footerWith($menu->id));
        $this->assertNotNull($this->refusal($menu));

        $this->store(AppearanceSettings::FOOTER_PUBLISHED_KEY, FooterLayout::default());

        $this->assertNull($this->refusal($menu));
        $this->assertNull(MenuModel::query()->find($menu->id));
    }

    public function test_the_place_menus_keep_their_own_rule(): void
    {
        $this->assertStringContainsString('cannot be deleted', (string) $this->refusal($this->menu('main')));
        $this->assertStringContainsString('part of the shop', (string) $this->refusal(MenuModel::query()->where('key', 'main')->first()));
    }

    public function test_a_hand_damaged_stored_layout_names_nothing(): void
    {
        $menu = $this->menu('promo');
        app(SiteSettingsRepository::class)->set(AppearanceSettings::FOOTER_PUBLISHED_KEY, '{not json');
        $this->store(AppearanceSettings::HEADER_PUBLISHED_KEY, ['desktop' => 'x', 'mobile' => [['type' => 'menu', 'settings' => 'x']]]);

        $this->assertSame([], app(DesignMenuUsage::class)->of($menu->id));
    }

    public function test_the_read_is_a_fixed_number_of_queries_however_many_menus_are_asked_about(): void
    {
        $ids = [];

        foreach (range(1, 12) as $n) {
            $ids[] = (int) $this->menu('m'.$n)->id;
        }

        $this->store(AppearanceSettings::HEADER_PUBLISHED_KEY, $this->headerWith($ids[0]));
        $this->store(AppearanceSettings::FOOTER_DRAFT_KEY, $this->footerWith($ids[1]));

        $count = function (array $asked): int {
            $this->app->forgetScopedInstances(); // the settings memo of the request: a cold read
            $usage = new DesignMenuUsage;
            DB::flushQueryLog();
            DB::enableQueryLog();
            array_map($usage->of(...), $asked);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count([$ids[0]]); // warm-up
        $one = $count([$ids[0]]);

        $this->assertSame(1, $one, 'the six stored layouts come in ONE query');
        $this->assertSame($one, $count($ids), 'twelve menus cost the same as one');
    }

    public function test_the_menus_page_refuses_the_delete_with_the_reason(): void
    {
        $this->actingAsStaff('Administrator');
        $menu = $this->menu('promo');
        $this->store(AppearanceSettings::FOOTER_PUBLISHED_KEY, $this->footerWith($menu->id));

        $result = Livewire::test(\App\Filament\Pages\Design\Menus::class)->instance()->deleteMenu($menu->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('is used in Design — Footer', $result['message']);
        $this->assertNotNull(MenuModel::query()->find($menu->id));
    }
}
