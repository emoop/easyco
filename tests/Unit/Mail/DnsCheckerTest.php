<?php

namespace Tests\Unit\Mail;

use App\Mail\Dns\DnsChecker;
use App\Mail\Dns\DnsLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DnsCheckerTest extends TestCase
{
    private function lookup(array $records = [], ?\Closure $each = null): object
    {
        return new class($records, $each) implements DnsLookup {
            /** @var list<string> */
            public array $hosts = [];

            public function __construct(private readonly array $records, private readonly ?\Closure $each)
            {
            }

            public function lookup(string $host, bool $includeCname = false): array
            {
                $this->hosts[] = $host;

                return $this->each !== null ? ($this->each)($host) : ($this->records[$host] ?? []);
            }
        };
    }

    /** @return array<string, array{0: string}> */
    public static function badDomains(): array
    {
        return [
            'space' => ['shop example.com'],
            'slash' => ['shop.example.com/path'],
            'at sign' => ['me@shop.example.com'],
            'underscore in the domain part' => ['_dmarc.shop.example.com'],
            'underscore in a label' => ['sho_p.example.com'],
            'single label' => ['localhost'],
            'leading dot' => ['.example.com'],
            'trailing dot' => ['example.com.'],
            'empty label' => ['shop..example.com'],
            'leading hyphen' => ['-shop.example.com'],
            'non ascii' => ['магазин.bg'],
            'newline' => ["shop.example.com\nevil.com"],
            'colon port' => ['shop.example.com:25'],
            'too long overall' => [str_repeat('a.', 127).'bc'],
            'label over 63' => [str_repeat('a', 64).'.example.com'],
        ];
    }

    #[DataProvider('badDomains')]
    public function test_an_invalid_domain_does_no_lookup_at_all(string $domain): void
    {
        $lookup = $this->lookup();

        $result = (new DnsChecker($lookup))->check($domain, 'spf.provider.com', 's1');

        $this->assertSame([], $lookup->hosts);
        $this->assertSame([['check' => 'domain', 'status' => DnsChecker::INVALID]], $result);
    }

    public function test_a_253_character_domain_is_accepted_and_254_is_not(): void
    {
        $ok = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 61)]);

        $this->assertSame(253, strlen($ok));
        $this->assertTrue(DnsChecker::isValidDomain($ok));
        $this->assertFalse(DnsChecker::isValidDomain($ok.'e'));
    }

    /** @return array<string, array{0: string}> */
    public static function badSelectors(): array
    {
        return [
            'space' => ['s 1'],
            'slash' => ['s1/x'],
            'at' => ['s@1'],
            'underscore' => ['s_1'],
            'path traversal' => ['../x'],
            'trailing dot' => ['s1.'],
            'too long' => [str_repeat('a', 101)],
            'newline' => ["s1\nx"],
        ];
    }

    #[DataProvider('badSelectors')]
    public function test_an_invalid_selector_does_no_dkim_lookup(string $selector): void
    {
        $lookup = $this->lookup(['shop.example.com' => ['v=spf1 ~all']]);

        $result = (new DnsChecker($lookup))->check('shop.example.com', null, $selector);

        $this->assertNotContains('_domainkey', array_map(fn ($h) => explode('.', $h)[1] ?? '', $lookup->hosts));
        $this->assertSame(['shop.example.com', '_dmarc.shop.example.com'], $lookup->hosts);
        $this->assertSame(['check' => 'dkim', 'status' => DnsChecker::INVALID], end($result));
    }

    public function test_valid_selectors_are_accepted(): void
    {
        foreach (['s1', 'mail', 'k1', 'brevo-1', 'selector1.2024', 'a'] as $selector) {
            $this->assertTrue(DnsChecker::isValidSelector($selector), $selector);
        }
    }

    public function test_spf_dmarc_and_dkim_are_found_case_insensitively(): void
    {
        $lookup = $this->lookup([
            'shop.example.com' => ['google-site-verification=abc', 'V=SPF1 include:Spf.Provider.com ~all'],
            '_dmarc.shop.example.com' => ['v=DMARC1; p=none'],
            's1._domainkey.shop.example.com' => ['v=DKIM1; k=rsa; p=MIGf'],
        ]);

        $result = (new DnsChecker($lookup))->check('Shop.Example.com', 'spf.provider.com', 'S1');

        $this->assertSame([
            ['check' => 'spf', 'status' => 'ok'],
            ['check' => 'spf_include', 'status' => 'ok'],
            ['check' => 'dmarc', 'status' => 'ok'],
            ['check' => 'dkim', 'status' => 'ok'],
        ], $result);
    }

    public function test_nothing_found_is_unseen_never_a_hard_failure(): void
    {
        $result = (new DnsChecker($this->lookup()))->check('shop.example.com', 'spf.provider.com', 's1');

        $this->assertSame(['unseen', 'unseen', 'unseen', 'unseen'], array_column($result, 'status'));
    }

    public function test_a_lookup_that_throws_is_skipped_not_raised(): void
    {
        $result = (new DnsChecker($this->lookup([], fn () => throw new RuntimeException('boom'))))->check('shop.example.com', null, 's1');

        $this->assertSame(['skipped', 'skipped', 'skipped'], array_column($result, 'status'));
    }

    public function test_once_the_time_budget_is_spent_the_remaining_lookups_are_skipped(): void
    {
        $lookup = $this->lookup([], function (string $host): array {
            usleep(30_000);

            return [];
        });

        $result = (new DnsChecker($lookup, 0.01))->check('shop.example.com', null, 's1');

        $this->assertSame(['shop.example.com'], $lookup->hosts, 'the budget is checked before each lookup');
        $this->assertSame(['unseen', 'skipped', 'skipped'], array_column($result, 'status'));
    }
}
