<?php

namespace App\Mail\Dns;

use Throwable;

/**
 * The advisory DNS check (mail-design.md §2): SPF, DMARC and (given a selector) DKIM records for a sender domain.
 * On demand only, never gates sending. A negative answer is ALWAYS `unseen` ("not seen yet"): DNS caches and
 * split-horizon setups make "absent" unprovable from here.
 *
 * The domain, the provider include and the selector are validated strictly (hostname characters only) BEFORE any
 * lookup; an invalid value does no lookup at all. dns_get_record() has no timeout of its own, so the 3-second
 * budget is enforced between lookups: once spent, the remaining checks are reported as `skipped`.
 */
final class DnsChecker
{
    public const OK = 'ok';

    public const UNSEEN = 'unseen';

    public const SKIPPED = 'skipped';

    public const INVALID = 'invalid';

    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

    public function __construct(
        private readonly DnsLookup $lookup,
        private readonly float $budgetSeconds = 3.0,
    ) {
    }

    /** A valid ASCII hostname of at least two labels, ≤ 253 characters: no underscore, space, slash or "@". */
    public static function isValidDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^(?:'.self::LABEL.'\.)+'.self::LABEL.'$/D', $domain) === 1;
    }

    /** A DKIM selector: one or more hostname labels (letters, digits, hyphen; dots between labels), ≤ 100 characters. */
    public static function isValidSelector(string $selector): bool
    {
        return strlen($selector) <= 100
            && preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*$/D', $selector) === 1;
    }

    /**
     * @return list<array{check: string, status: string}> check = domain|spf|spf_include|dmarc|dkim
     */
    public function check(string $domain, ?string $include = null, ?string $selector = null): array
    {
        $domain = strtolower(trim($domain));
        $include = $include === null || trim($include) === '' ? null : strtolower(trim($include));
        $selector = $selector === null || trim($selector) === '' ? null : strtolower(trim($selector));

        if (! self::isValidDomain($domain)) {
            return [['check' => 'domain', 'status' => self::INVALID]];
        }

        $results = [];
        $started = microtime(true);
        $spent = fn (): bool => (microtime(true) - $started) >= $this->budgetSeconds;

        $spf = $spent() ? null : $this->safe($domain);
        $spfRecord = $spf === null ? null : self::first($spf, 'v=spf1');
        $results[] = ['check' => 'spf', 'status' => $spf === null ? self::SKIPPED : ($spfRecord !== null ? self::OK : self::UNSEEN)];

        if ($include !== null) {
            if (! self::isValidDomain($include)) {
                $results[] = ['check' => 'spf_include', 'status' => self::INVALID];
            } elseif ($spf === null) {
                $results[] = ['check' => 'spf_include', 'status' => self::SKIPPED];
            } else {
                $results[] = ['check' => 'spf_include', 'status' => $spfRecord !== null && stripos($spfRecord, 'include:'.$include) !== false ? self::OK : self::UNSEEN];
            }
        }

        $dmarc = $spent() ? null : $this->safe('_dmarc.'.$domain);
        $results[] = ['check' => 'dmarc', 'status' => $dmarc === null ? self::SKIPPED : (self::first($dmarc, 'v=dmarc1') !== null ? self::OK : self::UNSEEN)];

        if ($selector !== null) {
            if (! self::isValidSelector($selector)) {
                $results[] = ['check' => 'dkim', 'status' => self::INVALID];
            } else {
                $dkim = $spent() ? null : $this->safe($selector.'._domainkey.'.$domain, true);
                $results[] = ['check' => 'dkim', 'status' => $dkim === null ? self::SKIPPED : ($dkim !== [] ? self::OK : self::UNSEEN)];
            }
        }

        return $results;
    }

    /** @return list<string>|null null = the lookup could not run (treated as "could not check", never an exception) */
    private function safe(string $host, bool $includeCname = false): ?array
    {
        try {
            return $this->lookup->lookup($host, $includeCname);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $records */
    private static function first(array $records, string $prefix): ?string
    {
        foreach ($records as $record) {
            if (stripos(ltrim($record), $prefix) === 0) {
                return $record;
            }
        }

        return null;
    }
}
