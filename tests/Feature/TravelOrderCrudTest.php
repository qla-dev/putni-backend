<?php

namespace Tests\Feature;

use App\Models\TravelOrder;
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
            ->assertJsonPath('data.order.isRoundTrip', true)
            ->assertJsonPath('data.remainingAiOrders', 79);

        $this->assertSame(79, $user->refresh()->ai_order_credits);

        $this->withToken($token)
            ->getJson('/api/travel-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.orderNumber', 'PN-2026-001');

        $this->withToken($token)
            ->patchJson('/api/travel-orders/order-client-1', ['status' => 'poslano', 'isRoundTrip' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'poslano')
            ->assertJsonPath('data.isRoundTrip', false);

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

    public function test_user_can_filter_and_paginate_orders_by_status(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/travel-orders', $this->payload())->assertCreated();
        $template = TravelOrder::query()->firstOrFail();

        foreach (range(2, 7) as $index) {
            $order = $template->replicate();
            $order->client_id = "order-client-{$index}";
            $order->order_number = "PN-2026-00{$index}";
            $order->save();
        }

        foreach (range(8, 9) as $index) {
            $order = $template->replicate();
            $order->client_id = "order-client-{$index}";
            $order->order_number = "PN-2026-00{$index}";
            $order->status = 'poslano';
            $order->save();
        }

        $response = $this->withToken($token)
            ->getJson('/api/travel-orders?status=nacrt&limit=3&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3);

        $this->assertSame(['nacrt'], array_values(array_unique($response->json('data.*.status'))));

        $this->withToken($token)
            ->getJson('/api/travel-orders?status=unknown')
            ->assertUnprocessable();
    }

    public function test_optional_profile_and_inclusion_fields_may_be_omitted_or_null(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $payload = $this->payload();

        unset(
            $payload['employeeTitle'],
            $payload['employeeOib'],
            $payload['employeeIban'],
            $payload['lunchIncluded'],
            $payload['dinnerIncluded'],
            $payload['hotelIncluded'],
            $payload['residenceDistanceKm'],
        );
        $payload['breakfastIncluded'] = null;

        $this->withToken($token)
            ->postJson('/api/travel-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.order.employeeTitle', null)
            ->assertJsonPath('data.order.breakfastIncluded', null);

        $this->assertDatabaseHas('travel_orders', [
            'client_id' => 'order-client-1',
            'employee_title' => null,
            'employee_oib' => null,
            'employee_iban' => null,
            'breakfast_included' => null,
        ]);
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
        $this->withToken($otherToken)
            ->getJson('/api/travel-orders/order-client-1')
            ->assertNotFound();
    }

    public function test_expense_without_scanned_items_is_returned_with_an_editable_item(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $payload = $this->payload();
        $payload['expenses'] = [[
            'id' => 'ticket-1',
            'category' => 'Avionska karta',
            'description' => 'Avionska karta',
            'vendor' => null,
            'receiptNumber' => null,
            'date' => '2026-07-20',
            'amountInEur' => 0,
            'originalAmount' => 0,
            'paymentMethod' => 'Nije prepoznato',
            'currency' => 'EUR',
            'imageData' => 'base64-receipt-image',
        ]];

        $this->withToken($token)
            ->postJson('/api/travel-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.order.expenses.0.items.0.name', 'Avionska karta')
            ->assertJsonPath('data.order.expenses.0.items.0.quantity', 1)
            ->assertJsonPath('data.order.expenses.0.items.0.unitPrice', 0)
            ->assertJsonPath('data.order.expenses.0.items.0.total', 0);

        $storedTicket = TravelOrder::query()->where('client_id', 'order-client-1')->firstOrFail()->expenses[0];
        $this->assertSame('Avionska karta', $storedTicket['items'][0]['name']);
        $this->assertSame(0, $storedTicket['items'][0]['unitPrice']);

        $this->withToken($token)
            ->getJson('/api/travel-orders')
            ->assertOk()
            ->assertJsonPath('data.0.receiptCount', 1)
            ->assertJsonMissingPath('data.0.expenses')
            ->assertJsonMissingPath('data.0.imageData');

        $this->withToken($token)
            ->getJson('/api/travel-orders/order-client-1')
            ->assertOk()
            ->assertJsonPath('data.expenses.0.items.0.name', 'Avionska karta')
            ->assertJsonPath('data.expenses.0.imageData', 'base64-receipt-image');

        $payload['expenses'][0]['amountInEur'] = 149.90;
        $payload['expenses'][0]['originalAmount'] = 149.90;
        $payload['expenses'][0]['items'] = [[
            'name' => 'Avionska karta',
            'quantity' => 1,
            'unitPrice' => 149.90,
            'total' => 149.90,
        ]];

        $this->withToken($token)
            ->patchJson('/api/travel-orders/order-client-1', ['expenses' => $payload['expenses']])
            ->assertOk()
            ->assertJsonPath('data.expenses.0.amountInEur', 149.90)
            ->assertJsonPath('data.expenses.0.items.0.unitPrice', 149.90);
    }

    public function test_multiple_images_can_be_attached_to_one_receipt(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $payload = $this->payload();
        $payload['receiptImages'] = [
            ['id' => 'image-1', 'expenseId' => 'receipt-1', 'imageData' => 'first', 'imageMimeType' => 'image/jpeg'],
            ['id' => 'image-2', 'expenseId' => 'receipt-1', 'imageData' => 'second', 'imageMimeType' => 'image/png'],
        ];

        $this->withToken($token)
            ->postJson('/api/travel-orders', $payload)
            ->assertCreated()
            ->assertJsonCount(2, 'data.order.receiptImages')
            ->assertJsonPath('data.order.receiptImages.0.expenseId', 'receipt-1')
            ->assertJsonPath('data.order.receiptImages.1.id', 'image-2');

        $this->withToken($token)
            ->getJson('/api/travel-orders')
            ->assertOk()
            ->assertJsonPath('data.0.receiptCount', 2);

        $this->assertCount(2, TravelOrder::query()->firstOrFail()->receipt_images);
    }

    public function test_additional_receipt_image_survives_patch_and_later_fetch(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $payload = $this->payload();
        $payload['expenses'] = [[
            'id' => 'receipt-1',
            'category' => 'Smještaj',
            'description' => 'Hotel',
            'vendor' => 'Hotel',
            'receiptNumber' => 'H-1',
            'date' => '2026-07-20',
            'amountInEur' => 100,
            'paymentMethod' => 'Kartica',
            'imageData' => 'main-image',
            'imageMimeType' => 'image/jpeg',
        ]];
        $this->withToken($token)->postJson('/api/travel-orders', $payload)->assertCreated();

        $this->withToken($token)
            ->patchJson('/api/travel-orders/order-client-1', [
                'receiptImages' => [
                    [
                        'id' => 'image-additional',
                        'expenseId' => 'receipt-1',
                        'imageData' => 'additional-image',
                        'imageMimeType' => 'image/png',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.receiptImages')
            ->assertJsonPath('data.receiptImages.0.id', 'legacy-receipt-1')
            ->assertJsonPath('data.receiptImages.1.id', 'image-additional');

        $this->withToken($token)
            ->getJson('/api/travel-orders/order-client-1')
            ->assertOk()
            ->assertJsonCount(2, 'data.receiptImages')
            ->assertJsonPath('data.receiptImages.0.expenseId', 'receipt-1')
            ->assertJsonPath('data.receiptImages.1.imageData', 'additional-image');

        $this->assertCount(1, TravelOrder::query()->firstOrFail()->receipt_images);
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
            'breakfastIncluded' => false,
            'lunchIncluded' => false,
            'dinnerIncluded' => false,
            'hotelIncluded' => false,
            'residenceDistanceKm' => 0,
            'expenses' => [],
            'totalExpensesCost' => 0,
            'advancementPaid' => 0,
            'grandTotal' => 242.5,
            'balanceToPay' => 242.5,
        ];
    }
}
