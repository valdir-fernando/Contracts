<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_authenticated_responses_enforce_compatible_csp(): void
    {
        $this->withoutVite();
        $policy = "base-uri 'none'; object-src 'none'; frame-ancestors 'self'; form-action 'self'";
        $this->get('/login')->assertOk()->assertHeader('Content-Security-Policy', $policy)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $user = User::create(['name' => 'Teste', 'username' => 'HEADERS', 'password' => 'SenhaSegura12345']);
        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk()->assertHeader('Content-Security-Policy', $policy);
        $this->assertTrue($dashboard->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($dashboard->headers->hasCacheControlDirective('private'));
        $this->getJson('/internal-api/v1/contracts')->assertOk()->assertHeader('Content-Security-Policy', $policy);
        $this->get('/administration')->assertForbidden()->assertHeader('Content-Security-Policy', $policy);
    }
}
