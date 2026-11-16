<?php

namespace App\Security\Csp;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * P8d: the CLOSED registry of the storefront's Content-Security-Policy (sent REPORT-ONLY by SecurityHeaders). The merchant never types CSP.
 *
 * CORE: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: <the media/storage host when it is not this site>; font-src 'self';
 * connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; report-uri /csp-report`. NO `'unsafe-inline'`, NO nonce: the
 * HTML is cached and identical for every visitor.
 *
 * AN INTEGRATION that loads something from another host registers it from a service provider: `app(CspSources::class)->allow('script-src', 'https://js.example.com')`.
 * Only the directives in `EXTENDABLE` and only an `https://host[:port]` origin are accepted (anything else throws at registration, i.e. in the developer's hands, never
 * in a visitor's request); `'unsafe-inline'`, `'unsafe-eval'`, `*` and `http:` can never be added.
 */
#[Singleton]
final class CspSources
{
    /** @var list<string> */
    public const EXTENDABLE = ['script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-src', 'media-src'];

    /** @var array<string, array<string, true>> */
    private array $extra = [];

    public function allow(string $directive, string $origin): void
    {
        if (! in_array($directive, self::EXTENDABLE, true)) {
            throw new InvalidArgumentException("CSP directive «{$directive}» cannot be extended.");
        }

        if (preg_match('~^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?$~', $origin) !== 1) {
            throw new InvalidArgumentException("CSP source «{$origin}» is not an https origin.");
        }

        $this->extra[$directive][$origin] = true;
    }

    public function policy(): string
    {
        $img = ["'self'", 'data:'];

        if (($media = $this->mediaOrigin()) !== null) {
            $img[] = $media;
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'"],
            'img-src' => $img,
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        foreach ($this->extra as $directive => $origins) {
            $directives[$directive] = array_values(array_unique([...($directives[$directive] ?? ["'self'"]), ...array_keys($origins)]));
        }

        $parts = [];

        foreach ($directives as $name => $sources) {
            $parts[] = $name.' '.implode(' ', $sources);
        }

        $parts[] = 'report-uri /csp-report';

        return implode('; ', $parts);
    }

    /** The scheme://host of the media / storage URLs when it is not this site's own host (a CDN), else null. */
    private function mediaOrigin(): ?string
    {
        try {
            $url = Storage::disk('public')->url('x');
        } catch (\Throwable) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }

        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (strcasecmp((string) $parts['host'], (string) $own) === 0) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }
}
