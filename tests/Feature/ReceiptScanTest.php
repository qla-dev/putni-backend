<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_scan_requires_authentication(): void
    {
        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])->assertUnauthorized();
    }

    public function test_authenticated_scan_uses_server_side_openrouter_key(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode($this->scanResult())],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])
            ->assertOk()
            ->assertJsonPath('data.vendor', 'Test trgovina')
            ->assertJsonPath('data.total', 12.5);

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.test/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer server-secret')
            && $request['model'] === 'test-model'
        );
    }

    public function test_scan_reports_missing_server_configuration(): void
    {
        config(['services.openrouter.api_key' => null]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'AI skeniranje računa nije konfigurirano na serveru.');
    }

    public function test_incomplete_receipt_is_normalized_instead_of_rejected(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([])],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])
            ->assertOk()
            ->assertJsonPath('data.vendor', 'Nepoznat trgovac')
            ->assertJsonPath('data.category', 'Ostalo')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.totalInEur', 0)
            ->assertJsonPath('data.paymentMethod', 'Nije navedeno')
            ->assertJsonPath('data.items', []);
    }

    public function test_incomplete_airplane_ticket_is_normalized_without_receipt_field_errors(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'category' => 'Avionska karta',
                        'vendor' => '',
                        'departureLocation' => 'Sarajevo',
                        'destinationLocation' => 'Berlin',
                    ])],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
            'documentType' => 'air-ticket',
        ])
            ->assertOk()
            ->assertJsonPath('data.vendor', 'Nepoznata aviokompanija')
            ->assertJsonPath('data.category', 'Avionska karta')
            ->assertJsonPath('data.description', 'Avionska karta')
            ->assertJsonPath('data.departureLocation', 'Sarajevo')
            ->assertJsonPath('data.destinationLocation', 'Berlin')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.items.0.name', 'Avionska karta')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.0.unitPrice', 0)
            ->assertJsonPath('data.items.0.total', 0);

        Http::assertSent(fn ($request) => data_get($request->data(), 'response_format.json_schema.name') === 'putni_nalozi_air_ticket'
            && str_contains((string) data_get($request->data(), 'messages.0.content'), 'departureLocation')
            && str_contains((string) data_get($request->data(), 'messages.1.content.0.text'), 'Najvažnija polja')
        );
    }

    private function scanResult(): array
    {
        return [
            'vendor' => 'Test trgovina',
            'date' => '2026-07-27',
            'receiptNumber' => 'R-1',
            'category' => 'Prehrana',
            'currency' => 'EUR',
            'subtotal' => 10,
            'vat' => 2.5,
            'total' => 12.5,
            'totalInEur' => 12.5,
            'paymentMethod' => 'Kartica',
            'description' => 'Poslovni ručak',
            'items' => [[
                'name' => 'Ručak',
                'quantity' => 1,
                'unitPrice' => 12.5,
                'total' => 12.5,
                'vatRate' => 25,
            ]],
            'confidence' => 0.98,
            'warnings' => [],
            'departureLocation' => '',
            'destinationLocation' => '',
        ];
    }
}
