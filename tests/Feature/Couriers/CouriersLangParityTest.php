<?php

namespace Tests\Feature\Couriers;

use EasyCo\CourierBoxNow\BoxNowDescriptor;
use Illuminate\Support\Arr;
use Tests\TestCase;

/** bg and en move together (house rule): the same keys, no empty strings, the same :placeholders. */
class CouriersLangParityTest extends TestCase
{
    /** @return list<string> */
    public static function files(): array
    {
        return ['couriers', 'needs_attention'];
    }

    public function test_the_lang_files_have_identical_keys_and_placeholders(): void
    {
        foreach (self::files() as $file) {
            $en = Arr::dot(require base_path("lang/en/{$file}.php"));
            $bg = Arr::dot(require base_path("lang/bg/{$file}.php"));

            $this->assertSame(array_keys($en), array_keys($bg), $file);

            foreach ($en as $key => $english) {
                $this->assertNotSame('', trim((string) $english), "{$file}.{$key}");
                $this->assertNotSame('', trim((string) $bg[$key]), "{$file}.{$key}");

                preg_match_all('/:[a-z_]+/', (string) $english, $enPlaceholders);
                preg_match_all('/:[a-z_]+/', (string) $bg[$key], $bgPlaceholders);
                $this->assertEqualsCanonicalizing($enPlaceholders[0], $bgPlaceholders[0], "{$file}.{$key}");
            }
        }
    }

    public function test_every_field_label_and_help_of_the_installed_courier_has_a_string(): void
    {
        $en = Arr::dot(require base_path('lang/en/couriers.php'));

        foreach ((new BoxNowDescriptor)->fields() as $field) {
            $this->assertArrayHasKey($field->label, $en);

            if ($field->kind === 'host') {
                $this->assertArrayHasKey($field->label.'_help', $en);
            }
        }
    }
}
