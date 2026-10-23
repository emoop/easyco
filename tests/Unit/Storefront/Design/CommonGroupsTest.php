<?php

namespace Tests\Unit\Storefront\Design;

use App\Storefront\Design\CommonGroups;
use InvalidArgumentException;
use Tests\TestCase;

/** DS-1: the shared property groups: closed values, only differences are stored, the classes and the variables. */
class CommonGroupsTest extends TestCase
{
    private const ALL = ['align', 'spacing', 'visibility', 'colors', 'text_size'];

    /** @param  array<string, mixed>  $common  @param  list<string>  $supports  @return array{0: array<string, mixed>, 1: list<array{0: string, 1: string}>} */
    private function check(array $common, array $supports = self::ALL): array
    {
        $fails = [];
        $clean = CommonGroups::validate($common, $supports, 'c', function (string $path, string $code, array $params = []) use (&$fails): void {
            $fails[] = [$path, $code];
        });

        return [$clean, $fails];
    }

    public function test_a_full_valid_common_is_kept_in_the_fixed_group_order(): void
    {
        [$clean, $fails] = $this->check([
            'text_size' => 'l', 'colors' => ['fg' => '#FFFFFF', 'bg' => '#112233'], 'visibility' => ['phone' => false, 'desktop' => true], 'spacing' => ['bottom' => '0', 'top' => 'm'], 'align' => 'center',
        ]);

        $this->assertSame([], $fails);
        $this->assertSame(['align' => 'center', 'spacing' => ['top' => 'm', 'bottom' => '0'], 'visibility' => ['phone' => false], 'colors' => ['bg' => '#112233', 'fg' => '#ffffff'], 'text_size' => 'l'], $clean);
    }

    public function test_a_value_equal_to_its_default_is_not_stored(): void
    {
        [$clean, $fails] = $this->check(['align' => 'start', 'text_size' => 'm', 'visibility' => ['desktop' => true, 'phone' => true], 'colors' => ['bg' => '', 'fg' => null], 'spacing' => []]);

        $this->assertSame([], $fails);
        $this->assertSame([], $clean, 'only differences are stored');

        $this->assertSame(['visibility' => ['phone' => false]], $this->check(['visibility' => ['desktop' => true, 'phone' => false]])[0], 'one difference keeps only that key');
    }

    public function test_spacing_zero_is_a_choice_not_a_default(): void
    {
        $this->assertSame(['spacing' => ['top' => '0']], $this->check(['spacing' => ['top' => '0']])[0]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> [common, the path that fails] */
    public static function broken(): array
    {
        return [
            'an unknown group' => [['margin' => 's'], 'c.margin'],
            'an unknown key' => [['spacing' => ['left' => 's']], 'c.spacing.left'],
            'a bad align' => [['align' => 'left'], 'c.align'],
            'an align that is a list' => [['align' => ['center']], 'c.align'],
            'a bad spacing' => [['spacing' => ['top' => 'xl']], 'c.spacing.top'],
            'a bad boolean' => [['visibility' => ['phone' => 'no']], 'c.visibility.phone'],
            'a bad colour' => [['colors' => ['bg' => 'red']], 'c.colors.bg'],
            'a colour with url()' => [['colors' => ['fg' => 'url(x)']], 'c.colors.fg'],
            'a bad text size' => [['text_size' => 'xl'], 'c.text_size'],
            'a group that is a scalar' => [['visibility' => 'off'], 'c.visibility'],
        ];
    }

    /** @param  array<string, mixed>  $common */
    #[\PHPUnit\Framework\Attributes\DataProvider('broken')]
    public function test_a_broken_common_fails_on_its_path(array $common, string $path): void
    {
        $this->assertContains($path, array_column($this->check($common)[1], 0));
    }

    public function test_a_group_the_component_does_not_support_is_refused(): void
    {
        [$clean, $fails] = $this->check(['align' => 'center', 'colors' => ['bg' => '#112233']], ['align']);

        $this->assertSame([['c.colors', 'unknown_setting']], $fails);
        $this->assertSame(['align' => 'center'], $clean, 'what is supported is kept');
        $this->assertSame([['c.align', 'unknown_setting']], $this->check(['align' => 'center'], [])[1]);
    }

    public function test_the_classes_come_in_a_fixed_order_and_only_for_differences(): void
    {
        $this->assertSame('', CommonGroups::classes([]));
        $this->assertSame('sf-al-c sf-mt-m sf-mb-0 sf-hide-d sf-hide-m sf-ts-l', CommonGroups::classes([
            'text_size' => 'l', 'visibility' => ['phone' => false, 'desktop' => false], 'spacing' => ['bottom' => '0', 'top' => 'm'], 'align' => 'center',
        ]));
        $this->assertSame('sf-al-s', CommonGroups::classes(['align' => 'start']));
        $this->assertSame('sf-al-e', CommonGroups::classes(['align' => 'end']));
        $this->assertSame('sf-mt-s sf-mb-l', CommonGroups::classes(['spacing' => ['top' => 's', 'bottom' => 'l']]));
        $this->assertSame('', CommonGroups::classes(['visibility' => ['desktop' => true], 'colors' => ['bg' => '#112233']]), 'colours are variables, not classes');
        $this->assertSame('', CommonGroups::classes(['align' => '"><script>', 'spacing' => ['top' => 'x'], 'text_size' => 'xl']), 'an invalid value never becomes a class');
    }

    public function test_the_colour_variables_are_keyed_by_the_node_id(): void
    {
        $this->assertSame(['--sf-n-ab12cd34-bg' => '#112233', '--sf-n-ab12cd34-fg' => '#fff'], CommonGroups::variables('ab12cd34', ['colors' => ['bg' => '#112233', 'fg' => '#fff']]));
        $this->assertSame([], CommonGroups::variables('ab12cd34', ['align' => 'center']));
        $this->assertSame(['--sf-n-x-bg' => '#112233'], CommonGroups::variables('x', ['colors' => ['bg' => '#112233', 'fg' => 'red']]), 'an invalid colour is skipped');
    }

    public function test_a_bad_node_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommonGroups::variables('A b;}', ['colors' => ['bg' => '#112233']]);
    }
}
