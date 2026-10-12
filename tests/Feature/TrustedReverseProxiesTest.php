<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustedReverseProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TrustedReverseProxiesTest extends TestCase
{
    public function test_https_is_accepted_only_from_configured_proxies_and_host_stays_unchanged(): void
    {
        config(['app.trusted_proxies' => ['203.0.113.10']]);
        try {
            foreach (['203.0.113.10' => 'https', '203.0.113.20' => 'http'] as $address => $scheme) {
                $request = Request::create('http://contracts.test/login', server: ['REMOTE_ADDR' => $address]);
                $request->headers->set('X-Forwarded-Proto', 'https');
                $request->headers->set('X-Forwarded-Host', 'other.example');
                $response = (new TrustedReverseProxies)->handle($request, fn ($request) => new Response($request->getSchemeAndHttpHost()));
                $this->assertSame($scheme.'://contracts.test', $response->getContent());
            }
            config(['app.trusted_proxies' => []]);
            $request = Request::create('http://contracts.test/login', server: ['REMOTE_ADDR' => '203.0.113.10']);
            $request->headers->set('X-Forwarded-Proto', 'https');
            $response = (new TrustedReverseProxies)->handle($request, fn ($request) => new Response($request->getSchemeAndHttpHost()));
            $this->assertSame('http://contracts.test', $response->getContent());
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);
        }
    }
}
