<?php

namespace App\Storefront\Seo;

/**
 * The ONE source of /robots.txt (SEO-1). There is no public/robots.txt any more: Apache (Laragon), nginx and Varnish all reach the route in routes/web.php, which answers with this.
 *
 * The rules are the ones the static file had, in the same order, plus `Disallow: /search` (the results page is `noindex, follow` already; the line also saves crawl budget and server work against
 * bots) and, as the LAST line, the absolute address of the sitemap. With `easyco.noindex` (a test / staging server) the answer is exactly «everything disallowed» and has no Sitemap line.
 */
final class RobotsTxt
{
    /** @var list<string> */
    public const RULES = [
        'Disallow: /admin/',
        'Disallow: /api/',
        'Disallow: /design-preview',
        'Disallow: /cart',
        'Disallow: /checkout',
        'Disallow: /*filter[',
        'Disallow: /*filter%5B',
        'Disallow: /search',
        'Disallow: /__ec/',
    ];

    public static function body(): string
    {
        if (config('easyco.noindex')) {
            return "User-agent: *\nDisallow: /\n";
        }

        return "User-agent: *\n".implode("\n", self::RULES)."\nSitemap: ".rtrim((string) config('app.url'), '/')."/sitemap.xml\n";
    }
}
