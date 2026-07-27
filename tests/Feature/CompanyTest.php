<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_company_and_another_user_can_join_with_code(): void
    {
        $owner = User::factory()->create(['name' => 'Owner User']);
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/company', ['name' => 'QLA Team'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'QLA Team')
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.isOwner', true);

        $code = $created->json('data.inviteCode');
        $this->assertMatchesRegularExpression('/^[A-Z]{3}\d{3}$/', $code);

        $member = User::factory()->create(['name' => 'Member User']);
        Sanctum::actingAs($member);

        $this->postJson('/api/company/join', ['code' => strtolower($code)])
            ->assertOk()
            ->assertJsonPath('data.isOwner', false)
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.members.0.id', $member->id);
    }

    public function test_only_owner_can_update_company_team_settings(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $company = $this->postJson('/api/company', ['name' => 'QLA Team'])->json('data');

        $this->patchJson('/api/company', [
            'teamEnabled' => true,
            'shareAiTokens' => true,
        ])->assertOk()->assertJsonPath('data.shareAiTokens', true);

        $member = User::factory()->create();
        Sanctum::actingAs($member);
        $this->postJson('/api/company/join', ['code' => $company['inviteCode']])->assertOk();
        $this->patchJson('/api/company', ['shareAiTokens' => false])->assertForbidden();
    }

    public function test_member_consumes_owner_credits_when_token_sharing_is_enabled(): void
    {
        $owner = User::factory()->create(['ai_order_credits' => 5]);
        Sanctum::actingAs($owner);
        $company = $this->postJson('/api/company', ['name' => 'Shared Team'])->json('data');
        $this->patchJson('/api/company', ['shareAiTokens' => true])->assertOk();

        $member = User::factory()->create(['ai_order_credits' => 80]);
        Sanctum::actingAs($member);
        $this->postJson('/api/company/join', ['code' => $company['inviteCode']])->assertOk();

        $this->postJson('/api/auth/credits/consume')
            ->assertOk()
            ->assertJsonPath('data.user.remainingAiOrders', 4);

        $this->assertSame(4, $owner->refresh()->ai_order_credits);
        $this->assertSame(80, $member->refresh()->ai_order_credits);
    }
}
