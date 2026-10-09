<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Arr;
use Tests\TestCase;

/** bg and en move together (house rule): the same keys, no empty strings, the same :placeholders. */
class MailLangParityTest extends TestCase
{
    public function test_mail_lang_files_have_identical_keys_and_placeholders(): void
    {
        $en = Arr::dot(require base_path('lang/en/mail.php'));
        $bg = Arr::dot(require base_path('lang/bg/mail.php'));

        $this->assertSame(array_keys($en), array_keys($bg));

        foreach ($en as $key => $english) {
            $this->assertNotSame('', trim((string) $english), $key);
            $this->assertNotSame('', trim((string) $bg[$key]), $key);

            preg_match_all('/:[a-z_]+/', (string) $english, $enPlaceholders);
            preg_match_all('/:[a-z_]+/', (string) $bg[$key], $bgPlaceholders);
            $this->assertEqualsCanonicalizing($enPlaceholders[0], $bgPlaceholders[0], $key);
        }
    }

    public function test_shipped_templates_exist_in_both_locales_with_the_same_variables(): void
    {
        $variables = static function (string $locale): array {
            $source = (string) file_get_contents(resource_path("mail/{$locale}/order.confirmation.md"));
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $source, $m);

            return array_values(array_unique($m[1]));
        };

        $this->assertEqualsCanonicalizing($variables('en'), $variables('bg'));

        foreach (['en', 'bg'] as $locale) {
            $source = (string) file_get_contents(resource_path("mail/{$locale}/order.confirmation.md"));
            $this->assertStringStartsWith('subject:', $source);
            $this->assertStringNotContainsString("\r", $source, 'LF endings');
        }
    }
}
