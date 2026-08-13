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

        $this->assertDatabaseCount('ai_calls', 1);
    }

    public function test_invalid_ai_output_is_retried_twice_and_every_response_is_saved(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fakeSequence('openrouter.test/*')
            ->push(['choices' => [['message' => ['content' => 'not-json']]]])
            ->push(['choices' => [['message' => ['content' => '{still-invalid']]]])
            ->push(['choices' => [['message' => ['content' => json_encode($this->scanResult())]]]]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])
            ->assertOk()
            ->assertJsonPath('data.vendor', 'Test trgovina');

        Http::assertSentCount(3);
        $this->assertDatabaseCount('ai_calls', 3);
    }

    public function test_non_receipt_image_is_returned_for_attachment_but_marked_invalid(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode(array_merge($this->scanResult(), [
                        'isReceipt' => false,
                        'vendor' => '',
                        'total' => 0,
                        'items' => [],
                    ]))],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'not-a-receipt', 'mimeType' => 'image/jpeg']],
        ])
            ->assertOk()
            ->assertJsonPath('data.isReceipt', false)
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(1, 'data.warnings');

        Http::assertSent(fn ($request) => data_get(
            $request->data(),
            'response_format.json_schema.schema.properties.isReceipt.type',
        ) === 'boolean');
    }

    public function test_three_invalid_outputs_return_a_controlled_validation_error_instead_of_server_error(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'not-json']]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.images.0',
                'AI nije uspio ispravno ocitati dokument ni nakon automatskih pokusaja. Pokusajte ponovo.',
            );

        Http::assertSentCount(3);
        $this->assertDatabaseCount('ai_calls', 3);
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
                        'departureDateTime' => '2026-08-20T08:15',
                        'arrivalDateTime' => '2026-08-20T10:05',
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
            ->assertJsonPath('data.departureDateTime', '2026-08-20T08:15')
            ->assertJsonPath('data.arrivalDateTime', '2026-08-20T10:05')
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

    public function test_air_ticket_prompt_requires_any_iata_code_to_be_returned_as_a_city_name(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode(array_merge($this->scanResult(), [
                        'category' => 'Avionska karta',
                        'departureLocation' => 'Sarajevo',
                        'destinationLocation' => 'Berlin',
                    ]))],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
            'documentType' => 'air-ticket',
        ])
            ->assertOk()
            ->assertJsonPath('data.departureLocation', 'Sarajevo')
            ->assertJsonPath('data.destinationLocation', 'Berlin');

        Http::assertSent(fn ($request) => str_contains(
            (string) data_get($request->data(), 'messages.0.content'),
            'bilo koji IATA kod',
        ));
    }

    public function test_bam_receipt_uses_deterministic_conversion_instead_of_ai_value(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode(array_merge($this->scanResult(), [
                        'currency' => 'BAM',
                        'total' => 1440.30,
                        'totalInEur' => 736.50,
                    ]))],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])->assertOk();

        $this->assertEqualsWithDelta(
            1440.30 / 1.95583,
            (float) $response->json('data.totalInEur'),
            0.000001,
        );
    }

    public function test_rent_a_car_receipt_category_is_detected_and_preserved(): void
    {
        config([
            'services.openrouter.api_key' => 'server-secret',
            'services.openrouter.model' => 'test-model',
            'services.openrouter.url' => 'https://openrouter.test/chat/completions',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode(array_merge($this->scanResult(), [
                        'vendor' => 'Sixt',
                        'category' => 'Rent-a-car',
                        'description' => 'Najam vozila',
                    ]))],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/receipt-scans', [
            'images' => [['base64' => 'abc', 'mimeType' => 'image/jpeg']],
        ])
            ->assertOk()
            ->assertJsonPath('data.vendor', 'Sixt')
            ->assertJsonPath('data.category', 'Rent-a-car');

        Http::assertSent(fn ($request) => str_contains(
            (string) data_get($request->data(), 'messages.0.content'),
            'car rental/car hire',
        ) && in_array(
            'Rent-a-car',
            (array) data_get($request->data(), 'response_format.json_schema.schema.properties.category.enum'),
            true,
        ));
    }

    private function scanResult(): array
    {
        return [
            'isReceipt' => true,
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
            'departureDateTime' => '',
            'arrivalDateTime' => '',
        ];
    }
}
