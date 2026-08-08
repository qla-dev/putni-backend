<?php

namespace Tests\Feature;

use App\Models\AllowanceRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllowanceRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_bam_allowance_rates(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        AllowanceRate::query()->create([
            'country' => 'Bosnia and Herzegovina',
            'rate_bam' => 45,
            'is_default' => false,
        ]);

        $this->withToken($token)
            ->getJson('/api/allowance-rates')
            ->assertOk()
            ->assertJsonPath('data.0.country', 'Bosnia and Herzegovina')
            ->assertJsonPath('data.0.rateBam', 45)
            ->assertJsonPath('data.0.isDefault', false);
    }
}
