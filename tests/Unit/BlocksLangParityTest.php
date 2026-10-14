<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The Bulgarian and the English `blocks.php` (cash-on-delivery protection, stage C2-B) carry exactly the same keys, and none is empty. */
class BlocksLangParityTest extends TestCase
{
    /** @return list<string> */
    private function keys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            is_array($value) ? array_push($keys, ...$this->keys($value, $prefix.$key.'.')) : $keys[] = $prefix.$key;
        }

        sort($keys);

        return $keys;
    }

    public function test_bulgarian_and_english_have_the_same_keys_and_no_empty_text(): void
    {
        $bg = require dirname(__DIR__, 2).'/lang/bg/blocks.php';
        $en = require dirname(__DIR__, 2).'/lang/en/blocks.php';

        $this->assertSame($this->keys($bg), $this->keys($en));

        foreach ([$bg, $en] as $lines) {
            array_walk_recursive($lines, fn ($text, $key) => $this->assertNotSame('', trim((string) $text), (string) $key));
        }
    }

    public function test_the_owners_sentences_are_word_for_word(): void
    {
        $bg = require dirname(__DIR__, 2).'/lang/bg/blocks.php';

        $this->assertSame('Блокирането спира само наложения платеж за този клиент. Плащане по банков път остава възможно.', $bg['form']['note']);
        $this->assertSame('Блокирания', $bg['navigation_label']);
        $this->assertSame('Съвпадения', $bg['matches']['section']);
        $this->assertSame('Блокирай', $bg['actions']['block']);
        $this->assertSame('Отблокирай', $bg['actions']['unblock']);
        $this->assertSame('Удължи', $bg['actions']['extend']);
        $this->assertSame('Блокиран завинаги', $bg['blocked_forever']);
        $this->assertSame('Завинаги', $bg['form']['forever']);
        $this->assertSame('180 дни', $bg['form']['days_180']);
    }
}
