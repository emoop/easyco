<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P8d: POST /csp-report. Sessionless, no CSRF, never cacheable; accepts `application/csp-report` and `application/reports+json`; a body over `csp_report.max_bytes`
 * (8 KB) is a 413; 30 per minute per IP (the route's throttle). It logs AT MOST ONE line per (directive, blocked host) per minute (a cache key), with the page path
 * WITHOUT its query, stores nothing in the database and always answers 204 (never a Set-Cookie, never an xkey: the route is outside StorefrontCacheHeaders::ROUTES).
 */
final class CspReportController extends Controller
{
    private const ACCEPTED = ['application/csp-report', 'application/reports+json'];

    public function store(Request $request): Response
    {
        $max = (int) config('security.csp_report.max_bytes', 8192);

        if ((int) $request->headers->get('Content-Length', '0') > $max) {
            return $this->answer(413);
        }

        $body = $request->getContent();

        if (strlen($body) > $max) {
            return $this->answer(413);
        }

        if (in_array(strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0])), self::ACCEPTED, true)) {
            foreach ($this->violations($body) as [$directive, $blocked, $document]) {
                $this->log($directive, $blocked, $document);
            }
        }

        return $this->answer(204);
    }

    /** @return list<array{string, string, string}> [directive, blocked, document] of every violation the body carries */
    private function violations(string $body): array
    {
        $json = json_decode($body, true);

        if (! is_array($json)) {
            return [];
        }

        // `application/csp-report`: {"csp-report": {...}}; `application/reports+json`: [{"type": "csp-violation", "body": {...}}, ...]
        $reports = isset($json['csp-report']) && is_array($json['csp-report']) ? [$json['csp-report']] : [];

        foreach ($json as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'csp-violation' && is_array($item['body'] ?? null)) {
                $reports[] = $item['body'];
            }
        }

        $found = [];

        foreach (array_slice($reports, 0, 20) as $report) {
            $directive = $report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? $report['violatedDirective'] ?? '';
            $blocked = $report['blocked-uri'] ?? $report['blockedURL'] ?? $report['blockedUri'] ?? '';
            $document = $report['document-uri'] ?? $report['documentURL'] ?? $report['documentUri'] ?? '';

            if (is_string($directive) && is_string($blocked) && is_string($document)) {
                $found[] = [$directive, $blocked, $document];
            }
        }

        return $found;
    }

    private function log(string $directive, string $blocked, string $document): void
    {
        $directive = preg_replace('/[^a-z-]/', '', strtolower(explode(' ', trim($directive))[0])) ?? '';
        $directive = mb_substr($directive, 0, 40);

        // the HOST of the blocked address, or the keyword the browser reports (inline, eval, data, ...)
        $host = parse_url($blocked, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? strtolower($host) : mb_substr(preg_replace('/[^a-z:-]/', '', strtolower($blocked)) ?? '', 0, 20);
        $host = mb_substr($host, 0, 100);

        if ($directive === '' || ! Cache::add('security.csp.'.sha1($directive.'|'.$host), 1, 60)) {
            return;
        }

        $path = parse_url($document, PHP_URL_PATH);

        Log::info('CSP report-only violation.', ['directive' => $directive, 'blocked' => $host, 'page' => mb_substr(is_string($path) ? $path : '', 0, 200)]);
    }

    private function answer(int $status): Response
    {
        return response('', $status, ['Cache-Control' => 'no-store']);
    }
}
