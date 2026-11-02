<?php

namespace Tests\Unit\Storefront\PageDesign;

use App\Storefront\PageDesign\PageDesign;
use Tests\TestCase;

/**
 * GAP-1: `gapX` / `gapY` of the «Страници и карти» section — the space between the cards of the category listing, a closed list of px steps (0, 4, 8, 12, 16, 20, 24, 32; default 16 = what it always was). Their
 * validation on save, what the token compiler writes (a variable ONLY when the value differs from 16) and what the storefront reads back from a stored row.
 */
class GridGapDesignTest extends TestCase
{
    public function test_the_closed_list_and_the_defaults(): void
    {
        $this->assertSame(['0', '4', '8', '12', '16', '20', '24', '32'], PageDesign::GRID_GAPS);
        $this->assertSame('16', PageDesign::defaults(PageDesign::PAGE)['gapX']);
        $this->assertSame('16', PageDesign::defaults(PageDesign::PAGE)['gapY']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function good(): array
    {
        $good = [];

        foreach (['gapX', 'gapY'] as $key) {
            foreach (PageDesign::GRID_GAPS as $gap) {
                $good["{$key} {$gap}"] = [$key, $gap];
            }
        }

        return $good;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('good')]
    public function test_every_step_of_the_list_is_accepted_on_save(string $key, string $gap): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::PAGE, [$key => $gap], false);

        $this->assertSame([], $errors);
        $this->assertSame($gap, $clean[$key]);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function bad(): array
    {
        $bad = [];

        foreach (['gapX', 'gapY'] as $key) {
            foreach (['5' => '5', '17' => '17', 'px' => '16px', 'negative' => '-4', 'array' => ['16'], 'empty' => '', 'null' => null, 'int' => 16, 'float' => '16.5', 'long' => str_repeat('1', 600), 'declaration' => '16;color:red'] as $label => $value) {
                $bad["{$key} {$label}"] = [$key, $value];
            }
        }

        return $bad;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad')]
    public function test_anything_else_is_refused_on_save_and_the_default_is_kept(string $key, mixed $value): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::PAGE, [$key => $value], false);

        $this->assertArrayHasKey($key, $errors);
        $this->assertSame('16', $clean[$key]);
    }

    public function test_a_variable_is_written_only_when_the_value_differs_from_16(): void
    {
        $vars = static fn (array $values): array => PageDesign::vars(PageDesign::PAGE, PageDesign::validate(PageDesign::PAGE, $values, false)[0]);

        $this->assertSame([], $vars([]), 'a shop that never set them writes nothing');
        $this->assertSame([], $vars(['gapX' => '16', 'gapY' => '16']));
        $this->assertSame(['--sf-grid-gap-x' => '0.5rem'], $vars(['gapX' => '8']));
        $this->assertSame(['--sf-grid-gap-y' => '0'], $vars(['gapY' => '0']));
        $this->assertSame(['--sf-grid-gap-x' => '2rem', '--sf-grid-gap-y' => '0.25rem'], $vars(['gapX' => '32', 'gapY' => '4']));
    }

    public function test_the_compiler_accepts_only_a_length_for_these_variables(): void
    {
        foreach (['--sf-grid-gap-x', '--sf-grid-gap-y'] as $variable) {
            foreach (['0', '0.5rem', '2rem', '1.25rem'] as $good) {
                $this->assertTrue(PageDesign::isValidVar($variable, $good), "{$variable}: {$good}");
            }

            foreach (['8px', '1rem;color:red', 'calc(1rem)', '', '-1rem', 'auto'] as $bad) {
                $this->assertFalse(PageDesign::isValidVar($variable, $bad), "{$variable}: {$bad}");
            }
        }
    }

    // ---- what the storefront reads back ---------------------------------------------------------------------------------------------------

    public function test_the_stored_gaps_are_read_back_and_old_data_without_the_keys_is_16(): void
    {
        $this->assertSame(['gapX' => 8, 'gapY' => 0], PageDesign::gaps('{"gapX":"8","gapY":"0"}'));
        $this->assertSame(['gapX' => 16, 'gapY' => 16], PageDesign::gaps('{"card_radius":"12"}'), 'old data: the defaults');
        $this->assertSame(['gapX' => 16, 'gapY' => 16], PageDesign::gaps(null));
        $this->assertSame(['gapX' => 16, 'gapY' => 16], PageDesign::gaps('not json'));
    }

    public function test_a_bad_stored_gap_is_that_gap_at_16_and_the_other_one_is_kept(): void
    {
        $this->assertSame(['gapX' => 16, 'gapY' => 8], PageDesign::gaps('{"gapX":"17","gapY":"8"}'));
        $this->assertSame(['gapX' => 4, 'gapY' => 16], PageDesign::gaps('{"gapX":"4","gapY":["8"]}'));
    }

    public function test_another_problem_in_the_section_gives_the_defaults_as_before(): void
    {
        $this->assertSame(['gapX' => 16, 'gapY' => 16], PageDesign::gaps('{"gapX":"8","pad_min":"1000"}'));
    }
}
