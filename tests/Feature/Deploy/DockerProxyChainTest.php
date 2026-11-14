<?php

namespace Tests\Feature\Deploy;

use App\Http\TrustedProxyList;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * DOCKER-1 A6: behind Coolify's proxy -> varnish -> nginx -> php-fpm, TRUSTED_PROXIES (the Docker network ranges) makes Laravel see the visitor's
 * IP and the https scheme; a peer outside the list gets neither. The env parsing is TrustedProxyList (config/trustedproxy.php).
 */
class DockerProxyChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR);
        Route::get('/_test/whoami', fn (\Illuminate\Http\Request $r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure()]));
    }

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    public function test_the_docker_ranges_from_env_are_parsed_and_a_wildcard_is_never_accepted(): void
    {
        $this->assertSame(['10.0.0.0/8', '172.16.0.0/12'], TrustedProxyList::parse('10.0.0.0/8, 172.16.0.0/12'));
        $this->assertSame([], TrustedProxyList::parse('*'));
        $this->assertSame([], TrustedProxyList::parse(''));
    }

    public function test_a_request_through_the_trusted_chain_gets_the_forwarded_ip_and_scheme(): void
    {
        config(['trustedproxy.proxies' => TrustedProxyList::parse('10.0.0.0/8,172.16.0.0/12')]);

        // Coolify's proxy added the visitor; varnish appended the proxy's address; nginx is the direct peer's neighbour.
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.9'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7, 172.18.0.5', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_test/whoami')
            ->assertJson(['ip' => '198.51.100.7', 'secure' => true]);
    }

    public function test_an_untrusted_peer_cannot_forge_the_ip_or_the_scheme(): void
    {
        config(['trustedproxy.proxies' => TrustedProxyList::parse('10.0.0.0/8,172.16.0.0/12')]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_test/whoami')
            ->assertJson(['ip' => '203.0.113.5', 'secure' => false]);
    }
}
