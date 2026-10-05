<?php

namespace Tests\Feature;

use App\Rules\PlainText;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * App\Rules\PlainText in isolation (no database — the rule reads its value and
 * nothing else): the two classes of character it refuses, everything it must
 * still accept, and the translated message it fails with.
 *
 * THE `Б` CASE IS THE POINT of the code-point matching: U+0411 is stored as
 * 0xD0 0x91 in UTF-8, and 0x91 is a C1 control code as a BYTE. A byte-wise
 * implementation would refuse ordinary Bulgarian text — the Cyrillic cases
 * below are what keeps that from ever creeping back in.
 */
class PlainTextRuleTest extends TestCase
{
    private function fails(string $value): bool
    {
        return Validator::make(['field' => $value], ['field' => [new PlainText()]])->fails();
    }

    /** @return array<string, array{string}> */
    public static function refusedValues(): array
    {
        return [
            'a newline' => ["line one\nline two"],
            'a carriage return' => ["line one\rline two"],
            'a tab' => ["tab\tseparated"],
            'a NUL byte' => ["nul\0byte"],
            'a C0 escape' => ["bell\x07"],
            'a C1 control character' => ["control\x85character"],
            'a vertical tab' => ["vertical\x0Btab"],
            'a right-to-left override' => ['invoice'."\u{202E}".'txt'],
            'a left-to-right override' => ["\u{202D}text"],
            'a right-to-left isolate pair' => ["\u{2067}text\u{2069}"],
            'a first-strong isolate' => ["\u{2066}text"],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_a_control_or_bidirectional_character_is_refused(string $value): void
    {
        $validator = Validator::make(['field' => $value], ['field' => [new PlainText()]]);

        $this->assertTrue($validator->fails(), 'Expected the value to be refused.');

        // The message is this project's own lang key, with :attribute filled in
        // by the validator — so this asserts that the key resolves too.
        $this->assertSame(
            __('validation.plain_text', ['attribute' => 'field']),
            $validator->errors()->first('field')
        );
    }

    /** @return array<string, array{string}> */
    public static function allowedValues(): array
    {
        return [
            'an empty string' => [''],
            'plain ASCII' => ['Sofia'],
            'Cyrillic' => ['Иван Иванов'],
            'the Cyrillic letter whose second UTF-8 byte is a C1 byte (U+0411)' => ['Боряна'],
            'accented Latin' => ['Émile Çöé'],
            'an apostrophe and an ampersand' => ["O'Brien & Sons"],
            'double quotes' => ['"Ltd"'],
            'angle brackets' => ['<b>not bold</b>'],
            'a script tag' => ['<script>alert(1)</script>'],
            'an emoji' => ['Sofia 🎁'],
            'a long Cyrillic value' => [str_repeat('я', 255)],
            'a plain integer-looking string' => ['1000'],
        ];
    }

    #[DataProvider('allowedValues')]
    public function test_everything_else_is_accepted_and_left_untouched(string $value): void
    {
        $validator = Validator::make(['field' => $value], ['field' => [new PlainText()]]);

        $this->assertFalse($validator->fails(), 'Expected the value to be accepted: '.$value);
    }

    /**
     * The rule refuses, it never rewrites: the cases above only prove a pass or
     * a failure, this proves the accepted value arrives unchanged (nothing is
     * trimmed, stripped or entity-encoded on the way through).
     */
    public function test_an_accepted_value_is_passed_through_byte_for_byte(): void
    {
        $value = "  O'Brien & Sons <b>\"Ltd\"</b> — София  ";

        $validated = Validator::make(['field' => $value], ['field' => [new PlainText()]])->validated();

        $this->assertSame($value, $validated['field']);
    }

    /**
     * A value that is not a string at all belongs to the `string` rule, not to
     * this one: "is it text?" and "is this text plain?" are different questions.
     */
    public function test_a_non_string_is_left_alone(): void
    {
        foreach ([null, 42, 4.2, true, ['a']] as $value) {
            $this->assertFalse(
                Validator::make(['field' => $value], ['field' => [new PlainText()]])->fails(),
                'PlainText must not judge a non-string value.'
            );
        }
    }

    /**
     * Bytes that are not valid UTF-8 cannot be examined as text at all, and
     * nothing in this schema could store them: refusing them here keeps a
     * malformed body a 422 field error instead of a 500 out of MySQL.
     */
    public function test_invalid_utf8_is_refused(): void
    {
        $this->assertTrue($this->fails("\xC3\x28"));
        $this->assertTrue($this->fails("broken\xFFtail"));
    }

    /**
     * The key must be translated in BOTH languages (fallback off, so `lang/en`
     * cannot answer for `lang/bg`), and adding the app's own validation.php for it must
     * not have shadowed the framework's own keys: the loader merges the app's
     * file on top of the framework's copy, key by key.
     */
    public function test_the_message_is_translated_in_both_languages_and_the_framework_keys_survive(): void
    {
        foreach (['en', 'bg'] as $locale) {
            foreach (['validation.plain_text', 'validation.money_format'] as $key) {
                $this->assertTrue(Lang::has($key, $locale, false), "lang/{$locale}/validation.php must carry {$key}.");

                $message = Lang::get($key, [], $locale, false);

                $this->assertIsString($message, "lang/{$locale}/validation.php's {$key} must be a plain string.");
                $this->assertNotSame('', trim($message), "lang/{$locale}/validation.php's {$key} must not be blank.");
            }

            $this->assertStringContainsString(
                ':attribute',
                Lang::get('validation.plain_text', [], $locale, false),
                "lang/{$locale}/validation.php's plain_text must name :attribute."
            );
        }

        $this->assertTrue(
            Lang::has('validation.required', 'en', false),
            'The framework\'s own validation.required must survive the merge with the app file.'
        );
        $this->assertSame(
            'The :attribute field is required.',
            Lang::get('validation.required', [], 'en', false)
        );
    }
}


