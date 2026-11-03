<?php

namespace Tests\Unit\Storefront\Home;

use App\Storefront\Design\ZoneStore\HomeAdapter;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeCompiler;
use App\Storefront\Home\HomeLayout;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Home\HomeView;
use Tests\TestCase;

/**
 * HP-1: the home layout — what the validator accepts and normalises, what it refuses (on save, and again on read), what a published layout turns into for the page, and the tree the Design screen edits.
 */
class HomeLayoutTest extends TestCase
{
    private function heading(array $settings = []): array
    {
        return ['type' => 'heading', 'settings' => $settings + ['text' => 'New in']];
    }

    private function block(mixed $id = 7, array $settings = []): array
    {
        return ['type' => 'html_block', 'settings' => $settings + ['html_block' => $id]];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(array $sections, mixed $spacing = '32'): array
    {
        return HomeValidator::validate(['spacing' => $spacing, 'sections' => $sections]);
    }

    // ---- what is accepted -------------------------------------------------------------------------------------------------------------

    public function test_the_default_is_no_sections_and_the_spacing_32(): void
    {
        $this->assertSame(['spacing' => '32', 'sections' => []], HomeLayout::default());
        $this->assertSame([HomeLayout::default(), []], HomeValidator::validate(HomeLayout::default()));
    }

    public function test_a_valid_layout_is_normalised_with_every_setting_present(): void
    {
        [$clean, $errors] = $this->validate([$this->heading(), $this->block(7)]);

        $this->assertSame([], $errors);
        $this->assertSame(['show' => true, 'spacing' => 'default', 'text' => 'New in', 'level' => 'h2', 'lines' => 'lines', 'thickness' => '1'], $clean['sections'][0]['settings'], 'the common settings first, then the heading\'s own, every one present');
        $this->assertSame(7, $clean['sections'][1]['settings']['html_block']);
        $this->assertTrue($clean['sections'][1]['settings']['show']);
    }

    public function test_the_closed_lists(): void
    {
        $this->assertSame(['0', '8', '16', '24', '32', '48', '64', '96'], HomeCatalog::SPACINGS);
        $this->assertSame(12, HomeCatalog::MAX_SECTIONS);

        foreach (HomeCatalog::SPACINGS as $step) {
            $this->assertSame([], $this->validate([$this->heading(['spacing' => $step])], $step)[1], $step);
        }

        foreach (['h2', 'h3', 'h4'] as $level) {
            $this->assertSame([], $this->validate([$this->heading(['level' => $level])])[1], $level);
        }
    }

    // ---- what is refused ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> a layout and the path that must carry the message */
    public static function refused(): array
    {
        $h = static fn (array $settings = []): array => ['type' => 'heading', 'settings' => $settings + ['text' => 'Hi']];
        $b = static fn (array $settings = []): array => ['type' => 'html_block', 'settings' => $settings];

        return [
            'an unknown component type' => [['spacing' => '32', 'sections' => [['type' => 'slider', 'settings' => []]]], 'sections.0'],
            'a thirteenth section' => [['spacing' => '32', 'sections' => array_fill(0, 13, $h())], 'sections'],
            'level h1' => [['spacing' => '32', 'sections' => [$h(['level' => 'h1'])]], 'sections.0.settings.level'],
            'level h5' => [['spacing' => '32', 'sections' => [$h(['level' => 'h5'])]], 'sections.0.settings.level'],
            'level p' => [['spacing' => '32', 'sections' => [$h(['level' => 'p'])]], 'sections.0.settings.level'],
            'a spacing of 10 (the whole page)' => [['spacing' => '10', 'sections' => []], 'spacing'],
            'a spacing in px text (the whole page)' => [['spacing' => '12px', 'sections' => []], 'spacing'],
            'a spacing that is an array (the whole page)' => [['spacing' => ['32'], 'sections' => []], 'spacing'],
            'a section spacing of 10' => [['spacing' => '32', 'sections' => [$h(['spacing' => '10'])]], 'sections.0.settings.spacing'],
            'a section spacing in px text' => [['spacing' => '32', 'sections' => [$h(['spacing' => '12px'])]], 'sections.0.settings.spacing'],
            'a section spacing that is an array' => [['spacing' => '32', 'sections' => [$h(['spacing' => ['8']])]], 'sections.0.settings.spacing'],
            'a heading over 120 characters' => [['spacing' => '32', 'sections' => [$h(['text' => str_repeat('a', 121)])]], 'sections.0.settings.text'],
            'a heading with a tag' => [['spacing' => '32', 'sections' => [$h(['text' => 'Hi <b>there</b>'])]], 'sections.0.settings.text'],
            'a heading with a script' => [['spacing' => '32', 'sections' => [$h(['text' => '<script>alert(1)</script>'])]], 'sections.0.settings.text'],
            'an empty heading' => [['spacing' => '32', 'sections' => [$h(['text' => ''])]], 'sections.0.settings.text'],
            'an HTML block that is not chosen' => [['spacing' => '32', 'sections' => [$b()]], 'sections.0.settings.html_block'],
            'an HTML block with a text id' => [['spacing' => '32', 'sections' => [$b(['html_block' => 'abc'])]], 'sections.0.settings.html_block'],
            'an unknown setting' => [['spacing' => '32', 'sections' => [$h(['colour' => 'red'])]], 'sections.0.settings.colour'],
            'a show that is not a switch' => [['spacing' => '32', 'sections' => [$h(['show' => 'yes'])]], 'sections.0.settings.show'],
            'an unknown key of the layout' => [['spacing' => '32', 'sections' => [], 'columns' => 2], 'sections'],
            'a list that is not a list' => [['spacing' => '32', 'sections' => ['a' => 1]], 'sections'],
        ];
    }

    /** @param  array<string, mixed>  $layout */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_it_is_refused_under_its_path(array $layout, string $path): void
    {
        [, $errors] = HomeValidator::validate($layout);

        $this->assertArrayHasKey($path, $errors, json_encode($errors));
    }

    public function test_a_hand_edited_compiled_copy_that_does_not_validate_is_not_a_layout(): void
    {
        $document = static fn (array $layout): string => (string) json_encode(['hash' => 'x', 'zones' => ['home' => ['global' => ['layout' => $layout, 'blocks' => []]]]]);

        $this->assertNull(HomeCompiler::published(null));
        $this->assertNull(HomeCompiler::published('not json'));
        $this->assertNull(HomeCompiler::published($document(['spacing' => '10', 'sections' => [$this->heading()]])), 'a bad spacing: the old home');
        $this->assertNull(HomeCompiler::published($document(['spacing' => '32', 'sections' => [$this->heading(['level' => 'h1'])]])), 'a bad level: the old home');
        $this->assertNull(HomeCompiler::published($document(['spacing' => '32', 'sections' => []])), 'no section = no layout');
        $this->assertNotNull(HomeCompiler::published($document(['spacing' => '32', 'sections' => [$this->heading()]])));
    }

    // ---- what the page prints --------------------------------------------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $sections  @param  array<string, string>  $blocks */
    private function prepare(array $sections, string $spacing = '32', array $blocks = []): ?array
    {
        [$clean] = $this->validate($sections, $spacing);

        return HomeView::prepare(['layout' => $clean, 'blocks' => $blocks]);
    }

    public function test_nothing_published_is_the_old_page(): void
    {
        $this->assertNull(HomeView::prepare(null));
    }

    public function test_the_sections_keep_their_order_a_hidden_one_is_not_printed_and_the_first_printed_has_no_top_spacing(): void
    {
        $view = $this->prepare([
            $this->heading(['text' => 'Hidden first', 'show' => false, 'spacing' => '8']),
            $this->heading(['text' => 'One', 'spacing' => '64']),
            $this->heading(['text' => 'Two', 'spacing' => '8']),
            $this->heading(['text' => 'Three']),
        ], '48');

        $this->assertSame('48', $view['gap']);
        $this->assertSame(['One', 'Two', 'Three'], array_column(array_column($view['items'], 'settings'), 'text'), 'the hidden one is gone');
        $this->assertSame(['sf-hp-section sf-hp-heading', 'sf-hp-section sf-hp-heading sf-hp-mt-8', 'sf-hp-section sf-hp-heading'], array_column($view['items'], 'class'), 'the first printed one has no top spacing, one with an override has its class, «as the general one» has none');
    }

    public function test_an_html_block_prints_its_html_and_a_gone_or_empty_block_prints_nothing(): void
    {
        $view = $this->prepare([$this->block(7), $this->block(8), $this->block(9)], '32', ['7' => '<p>Sale</p>', '9' => '']);

        $this->assertCount(1, $view['items']);
        $this->assertStringContainsString('<p>Sale</p>', $view['items'][0]['html']);
        $this->assertSame('storefront.partials.home.html_block', $view['items'][0]['view']);
    }

    public function test_the_block_html_is_cleaned_again_when_the_layout_is_read(): void
    {
        $view = $this->prepare([$this->block(7)], '32', ['7' => '<p onclick="x()">Sale</p><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script', $view['items'][0]['html']);
        $this->assertStringNotContainsString('onclick', $view['items'][0]['html']);
        $this->assertStringContainsString('Sale', $view['items'][0]['html']);
    }

    public function test_every_section_hidden_is_still_a_published_layout(): void
    {
        $view = $this->prepare([$this->heading(['show' => false])]);

        $this->assertSame([], $view['items'], 'the layout is published: the page is not the old one, it simply has nothing to show');
    }

    // ---- the tree the screen edits ---------------------------------------------------------------------------------------------------------

    public function test_the_adapter_round_trips_and_finds_the_node_of_an_error(): void
    {
        [$clean] = $this->validate([$this->heading(['spacing' => '8']), $this->block(7)], '64');
        $tree = HomeAdapter::toTree($clean);

        $this->assertSame($clean, HomeAdapter::fromTree($tree));
        $this->assertSame('64', $tree['main']['props']['spacing']);

        $this->assertSame(['kind' => 'layout', 'id' => 'main', 'field' => 'spacing'], HomeAdapter::locate($tree, 'spacing'));
        $this->assertSame(['kind' => 'cell', 'id' => 'h-r0-c0', 'field' => null], HomeAdapter::locate($tree, 'sections'));
        $this->assertSame(['kind' => 'node', 'id' => 'h-r0-c0-1', 'field' => 'html_block'], HomeAdapter::locate($tree, 'sections.1.settings.html_block'));
        $this->assertNull(HomeAdapter::locate($tree, 'nothing'));
    }
}
