<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_profile_is_soft_deleted_and_restored_on_the_next_social_login(): void
    {
        config()->set('services.google.client_ids', ['client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub' => 'google-restore',
                'aud' => 'client-id',
                'email' => 'restore@example.com',
                'email_verified' => 'true',
                'name' => 'Restore Me',
                'exp' => time() + 3600,
            ]),
        ]);

        $login = $this->postJson('/api/auth/google', ['id_token' => 'valid'])->assertOk();
        $userId = $login->json('user.id');

        $this->withToken($login->json('token'))
            ->deleteJson('/api/auth/me')
            ->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $userId]);

        $restoredLogin = $this->postJson('/api/auth/google', ['id_token' => 'valid'])
            ->assertOk()
            ->assertJsonPath('user.id', $userId)
            ->assertJsonPath('is_new_user', false);

        $this->assertNull(User::query()->findOrFail($userId)->deleted_at);
        $this->withToken($restoredLogin->json('token'))->getJson('/api/auth/me')->assertOk();
    }
}
