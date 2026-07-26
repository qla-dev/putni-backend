<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RevenueCatCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_consumable_transactions_grant_credits_once(): void
    {
        config([
            'services.revenuecat.ios_api_key' => 'appl_test',
            'services.revenuecat.credit_products' => ['putni_credits_10' => 10],
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Http::fake([
            "api.revenuecat.com/v1/subscribers/putni-user-{$user->id}" => Http::response([
                'subscriber' => [
                    'non_subscriptions' => [
                        'putni_credits_10' => [
                            ['id' => 'transaction-1', 'purchase_date' => now()->toIso8601String()],
                            ['id' => 'transaction-2', 'purchase_date' => now()->toIso8601String()],
                        ],
                    ],
                ],
            ]),
        ]);

        $payload = ['product_id' => 'putni_credits_10', 'platform' => 'ios'];
        $this->postJson('/api/auth/revenuecat/sync-credits', $payload)
            ->assertOk()
            ->assertJsonPath('data.granted', 20)
            ->assertJsonPath('data.user.remainingAiOrders', 100);

        $this->postJson('/api/auth/revenuecat/sync-credits', $payload)
            ->assertOk()
            ->assertJsonPath('data.granted', 0)
            ->assertJsonPath('data.user.remainingAiOrders', 100);

        $this->assertDatabaseCount('revenuecat_purchases', 2);

        $this->postJson('/api/auth/credits/consume')
            ->assertOk()
            ->assertJsonPath('data.user.remainingAiOrders', 99);
    }

    public function test_credit_sync_requires_authentication(): void
    {
        $this->postJson('/api/auth/revenuecat/sync-credits')->assertUnauthorized();
    }
}
