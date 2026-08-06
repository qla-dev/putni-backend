<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\VehicleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserVehicleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_own_vehicles(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $vehicle = $this->postJson('/api/vehicles', [
            'brand' => 'Škoda',
            'model' => 'Octavia',
            'registrationPlate' => 'sa-123-aa',
            'ownershipType' => 'poslovno',
        ])->assertCreated()->assertJsonPath('data.registrationPlate', 'SA-123-AA')->json('data');

        $this->getJson('/api/vehicles')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson('/api/vehicles/'.$vehicle['id'], [
            'brand' => 'Škoda',
            'model' => 'Superb',
            'registrationPlate' => 'SA-123-AA',
            'ownershipType' => 'privatno',
        ])->assertOk()->assertJsonPath('data.model', 'Superb');

        $this->deleteJson('/api/vehicles/'.$vehicle['id'])->assertNoContent();
        $this->getJson('/api/vehicles')->assertJsonCount(0, 'data');
    }

    public function test_user_cannot_change_another_users_vehicle(): void
    {
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create([
            'brand' => 'Volkswagen',
            'model' => 'Golf',
            'registration_plate' => 'A11-B-222',
            'ownership_type' => 'privatno',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/vehicles/'.$vehicle->id)->assertNotFound();
    }

    public function test_catalog_contains_seeded_brands_and_models(): void
    {
        $this->seed(VehicleCatalogSeeder::class);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/vehicles/catalog')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Volkswagen'])
            ->assertJsonFragment(['name' => 'Škoda']);
    }
}
