<?php

namespace Tests\Feature;

use App\Filament\Pages\Help;
use App\Filament\Resources\OrderBlockResource\Pages\ListOrderBlocks;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\OrderBlockModel;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Help for the cash-on-delivery protection (HELP-1): the section is in BOTH help files with the three anchors the admin links to (and the list's
 * own), and each of the three places renders its «Помощ: как работи →» link, which opens in a new tab.
 */
class CodProtectionHelpTest extends TestCase
{
    use BuildsShippingZones;
    use PlacesRegionOrders;
    use RefreshDatabase;

    /** The anchors the admin links to, plus the section's own (cod-protection-list is the "Blocks" page's part of the text). */
    private const ANCHORS = ['cod-protection', 'action-order-matches', 'action-block-identifier', 'cod-protection-list'];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return ['bg' => ['bg'], 'en' => ['en']];
    }

    private function url(string $anchor): string
    {
        return Help::getUrl(['topic' => 'orders']).'#'.$anchor;
    }

    private function assertLinksTo(string $html, string $anchor): void
    {
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(e($this->url($anchor)), '/').'" target="_blank" rel="noopener noreferrer"[^>]*>'.preg_quote(e(__('help.link')), '/').'<\/a>/', $html, "a new-tab link to #{$anchor}");
    }

    /**
     * What the mounted action's dialog is made of: its form schema and its extra content, rendered. (The test harness's html() holds the page, not
     * the dialog, so the dialog is read from the action itself; the schema getter is protected, hence the reflection: test-only.)
     */
    private function dialogHtml(\Livewire\Features\SupportTesting\Testable $page): string
    {
        $instance = $page->instance();
        $action = $instance->getMountedAction();
        $this->assertNotNull($action, 'a dialog is mounted');

        $method = new \ReflectionMethod($instance, 'getMountedActionSchema');
        $method->setAccessible(true);
        $schema = $method->invoke($instance);
        $content = $action->getModalContent();

        return ($schema === null ? '' : $schema->toHtml()).($content === null ? '' : $content->toHtml());
    }

    private function order(): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);

        return (string) $this->checkout($this->streetFields('BG'), ['phone' => '0888 123 456'])->assertStatus(201)->json('order.id');
    }

    // ---- the anchors ------------------------------------------------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function test_every_anchor_is_in_the_help_file_of_both_languages_once(string $locale): void
    {
        $markdown = (string) file_get_contents(resource_path("help/{$locale}/orders.md"));

        foreach (self::ANCHORS as $anchor) {
            $this->assertSame(1, substr_count($markdown, '{#'.$anchor.'}'), "{$locale}: #{$anchor}");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function test_the_section_sits_right_after_cash_on_delivery_and_before_how_to_cancel(string $locale): void
    {
        $markdown = (string) file_get_contents(resource_path("help/{$locale}/orders.md"));

        $cash = strpos($markdown, '{#cash-on-delivery}');
        $cod = strpos($markdown, '{#cod-protection}');
        $list = strpos($markdown, '{#cod-protection-list}');
        $cancel = strpos($markdown, '{#cancel-order}');

        $this->assertTrue($cash < $cod && $cod < strpos($markdown, '{#action-order-matches}') && strpos($markdown, '{#action-order-matches}') < strpos($markdown, '{#action-block-identifier}') && strpos($markdown, '{#action-block-identifier}') < $list && $list < $cancel);
        $this->assertSame(1, preg_match('/^## .+ \{#cod-protection\}$/m', $markdown), 'a level-2 heading');
        $this->assertSame(1, preg_match('/^### .+ \{#action-block-identifier\}$/m', $markdown), 'a level-3 heading');
    }

    public function test_the_two_languages_carry_the_same_facts(): void
    {
        $bg = (string) file_get_contents(resource_path('help/bg/orders.md'));
        $en = (string) file_get_contents(resource_path('help/en/orders.md'));

        foreach (['`0888 123 456`', '`+359 888 123 456`', '`*** *** 456`', '**180 дни**', '**30 дни**', '**„управление на поръчки“**'] as $bgOnly) {
            $this->assertStringContainsString($bgOnly, $bg);
        }
        foreach (['`0888 123 456`', '`+359 888 123 456`', '`*** *** 456`', '**180 days**', '**30 days**', '**"manage orders"**'] as $enOnly) {
            $this->assertStringContainsString($enOnly, $en);
        }
        $this->assertStringContainsString('Свържете се посредством методите на контакт за възможността да поръчате от сайта', $bg, 'the owner\'s sentence');
    }

    // ---- the three places -----------------------------------------------------------------------------------------------------------------

    public function test_the_block_and_unblock_dialogs_link_to_their_section(): void
    {
        $order = $this->order();
        $this->actingAsStaff('Administrator');
        app(\App\Services\OrderBlockService::class)->block(\App\Enums\OrderBlockType::EMAIL, 'guest@example.com', 30);
        $entry = fn (string $name, string $component): TestAction => TestAction::make($name)->schemaComponent($component, schema: 'infolist');

        $block = Livewire::test(ViewOrder::class, ['record' => $order])->mountAction($entry('block_phone', 'phone'));
        $this->assertLinksTo($this->dialogHtml($block), 'action-block-identifier');

        $unblock = Livewire::test(ViewOrder::class, ['record' => $order])->mountAction($entry('unblock_email', 'email'));
        $this->assertLinksTo($this->dialogHtml($unblock), 'action-block-identifier');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function test_the_matches_card_links_to_its_section_with_and_without_matches(string $locale): void
    {
        app()->setLocale($locale);
        $first = $this->order();
        $this->actingAsStaff('Administrator');

        $this->assertLinksTo($this->get(OrderResource::getUrl('view', ['record' => $first]))->assertOk()->getContent(), 'action-order-matches');

        $second = $this->order();
        $this->app->forgetScopedInstances();
        $this->assertLinksTo($this->get(OrderResource::getUrl('view', ['record' => $second]))->assertOk()->getContent(), 'action-order-matches');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function test_the_blocks_page_subheading_links_to_the_protection_section(string $locale): void
    {
        app()->setLocale($locale);
        $this->actingAsStaff('Administrator');

        $this->assertLinksTo(Livewire::test(ListOrderBlocks::class)->html(), 'cod-protection');
    }

    public function test_the_blocks_page_dialogs_link_too_and_the_page_has_one_help_link_at_the_top(): void
    {
        $block = OrderBlockModel::query()->create(['type' => 'email', 'value_key' => 'a@b.bg', 'value_display' => 'a@b.bg']);
        $this->actingAsStaff('Administrator');

        $list = Livewire::test(ListOrderBlocks::class);

        $this->assertLinksTo($this->dialogHtml($list->mountAction('add_block')), 'action-block-identifier');
        $this->assertLinksTo($this->dialogHtml(Livewire::test(ListOrderBlocks::class)->mountTableAction('extend', $block)), 'action-block-identifier');
        $this->assertLinksTo($this->dialogHtml(Livewire::test(ListOrderBlocks::class)->mountTableAction('revoke', $block)), 'action-block-identifier');
    }

    public function test_the_help_page_serves_the_new_section_in_the_language_of_the_visitor(): void
    {
        $this->actingAsStaff('Administrator');

        foreach (['en' => 'Protection against fake cash-on-delivery orders', 'bg' => 'Защита от фалшиви поръчки с наложен платеж'] as $locale => $heading) {
            app()->setLocale($locale);
            $html = $this->get(Help::getUrl(['topic' => 'orders']))->assertOk()->getContent();

            $this->assertStringContainsString($heading, $html);
            $this->assertStringContainsString('id="cod-protection"', $html);
            $this->assertStringContainsString('id="action-block-identifier"', $html);
            $this->assertStringContainsString('id="action-order-matches"', $html);
        }
    }
}
