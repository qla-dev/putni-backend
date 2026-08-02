<?php

namespace Tests\Feature;

use App\Models\LampyrisUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LampyrisSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_creates_a_separate_lampyris_user_and_token(): void
    {
        config()->set('services.lampyris.google_client_ids', ['lampyris-client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub' => 'lampyris-google-123',
                'aud' => 'lampyris-client-id',
                'email' => 'firefly@example.com',
                'email_verified' => 'true',
                'name' => 'Fire Fly',
                'exp' => time() + 3600,
            ]),
        ]);

        $response = $this->postJson('/api/lampyris/auth/google', ['id_token' => 'valid']);

        $response->assertOk()
            ->assertJsonPath('user.email', 'firefly@example.com')
            ->assertJsonPath('is_new_user', true)
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('lampyris_users', ['email' => 'firefly@example.com']);
        $this->assertDatabaseCount('users', 0);

        $this->withToken($response->json('token'))
            ->getJson('/api/lampyris/auth/me')
            ->assertOk()
            ->assertJsonPath('user.name', 'Fire Fly');
    }

    public function test_lampyris_logout_revokes_the_current_token(): void
    {
        $user = LampyrisUser::query()->create([
            'name' => 'Fire Fly',
            'email' => 'firefly@example.com',
            'google_id' => 'lampyris-google-123',
        ]);
        $token = $user->createToken('lampyris-mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/lampyris/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_lampyris_tokens_cannot_access_putni_routes(): void
    {
        $user = LampyrisUser::query()->create([
            'name' => 'Fire Fly',
            'email' => 'firefly@example.com',
            'google_id' => 'lampyris-google-123',
        ]);

        $this->withToken($user->createToken('lampyris-mobile')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertForbidden();
    }
}
