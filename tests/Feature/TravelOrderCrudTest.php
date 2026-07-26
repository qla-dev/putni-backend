<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelOrderCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_list_update_and_delete_an_order(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $created = $this->withToken($token)
            ->postJson('/api/travel-orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.order.id', 'order-client-1')
            ->assertJsonPath('data.remainingAiOrders', 79);

        $this->assertSame(79, $user->refresh()->ai_order_credits);

        $this->withToken($token)
            ->getJson('/api/travel-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.orderNumber', 'PN-2026-001');

        $this->withToken($token)
            ->patchJson('/api/travel-orders/order-client-1', ['status' => 'poslano'])
            ->assertOk()
            ->assertJsonPath('data.status', 'poslano');

        $this->withToken($token)
            ->deleteJson('/api/travel-orders/order-client-1')
            ->assertNoContent();

        $this->assertDatabaseCount('travel_orders', 0);
    }

    public function test_user_without_credits_cannot_create_an_order(): void
    {
        $user = User::factory()->create(['ai_order_credits' => 0]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/travel-orders', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No AI order credits remain.');

        $this->assertDatabaseCount('travel_orders', 0);
        $this->assertSame(0, $user->refresh()->ai_order_credits);
    }

    public function test_user_cannot_change_another_users_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerToken = $owner->createToken('owner')->plainTextToken;
        $otherToken = $other->createToken('other')->plainTextToken;

        $this->withToken($ownerToken)->postJson('/api/travel-orders', $this->payload())->assertCreated();
        app('auth')->forgetGuards();
        $this->flushHeaders()->withToken($otherToken)
            ->getJson('/api/auth/me')
            ->assertJsonPath('user.id', $other->id);
        $this->withToken($otherToken)
            ->patchJson('/api/travel-orders/order-client-1', ['status' => 'odobreno'])
            ->assertNotFound();
    }

    private function payload(): array
    {
        return [
            'id' => 'order-client-1',
            'orderNumber' => 'PN-2026-001',
            'status' => 'nacrt',
            'employeeName' => 'Test Employee',
            'employeeTitle' => 'Komercijalista',
            'employeeOib' => '12345678901',
            'employeeIban' => 'HR1223600001101234565',
            'companyName' => 'Test d.o.o.',
            'companyOib' => '98765432109',
            'route' => 'Sarajevo – Zagreb',
            'startLocation' => 'Sarajevo',
            'destinationCountry' => 'Hrvatska',
            'purpose' => 'Sastanak',
            'departureTime' => '2026-07-20T08:00:00+02:00',
            'arrivalTime' => '2026-07-21T18:00:00+02:00',
            'routeStopTimes' => [],
            'totalHours' => 34,
            'transportType' => 'Automobil',
            'totalKm' => 800,
            'totalKmCost' => 200,
            'dailyAllowanceRateEur' => 30,
            'totalAllowanceCost' => 42.5,
            'expenses' => [],
            'totalExpensesCost' => 0,
            'advancementPaid' => 0,
            'grandTotal' => 242.5,
            'balanceToPay' => 242.5,
        ];
    }
}
