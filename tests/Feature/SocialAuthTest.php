<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_creates_a_user_and_sanctum_token(): void
    {
        config()->set('services.google.client_ids', ['client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub' => 'google-123',
                'aud' => 'client-id',
                'email' => 'employee@example.com',
                'email_verified' => 'true',
                'name' => 'Test Employee',
                'exp' => time() + 3600,
            ]),
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'valid']);

        $response->assertOk()
            ->assertJsonPath('user.email', 'employee@example.com')
            ->assertJsonPath('is_new_user', true)
            ->assertJsonStructure(['token']);

        $this->withToken($response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.name', 'Test Employee');
    }

    public function test_private_routes_require_authentication(): void
    {
        $this->getJson('/api/travel-orders')->assertUnauthorized();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
