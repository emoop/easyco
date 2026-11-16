<?php

namespace Tests\Feature\Storefront\Seo;

use App\Storefront\Seo\RobotsTxt;
use Tests\TestCase;

/** SEO-1 §1: /robots.txt has one source (RobotsTxt), always answered by the app. */
class RobotsTxtTest extends TestCase
{
    public function test_there_is_no_static_file_so_every_web_server_reaches_the_route(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'));
    }

    public function test_the_rules_are_the_old_ones_plus_search_the_trap_path_and_the_sitemap_line_last(): void
    {
        config(['easyco.noindex' => false, 'app.url' => 'https://shop.test/']);

        $response = $this->get('/robots.txt');
        $response->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));

        $this->assertSame(
            "User-agent: *\nDisallow: /admin/\nDisallow: /api/\nDisallow: /design-preview\nDisallow: /cart\nDisallow: /checkout\nDisallow: /*filter[\nDisallow: /*filter%5B\nDisallow: /search\nDisallow: /__ec/\nSitemap: https://shop.test/sitemap.xml\n",
            $response->getContent(),
        );
        $this->assertSame(RobotsTxt::body(), $response->getContent());
    }

    public function test_the_noindex_switch_keeps_the_exact_disallow_all_answer_without_a_sitemap_line(): void
    {
        config(['easyco.noindex' => true]);

        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt')->getContent());
        $this->assertStringNotContainsString('Sitemap', RobotsTxt::body());
    }
}
