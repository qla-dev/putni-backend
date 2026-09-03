<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ExportFormatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class TravelOrderExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_formats_are_discovered_and_every_seeded_format_downloads(): void
    {
        $this->seed(ExportFormatSeeder::class);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $order = $user->travelOrders()->create($this->orderData());

        $this->withToken($token)
            ->getJson('/api/exports')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.name', 'pdf')
            ->assertJsonPath('data.6.name', 'dynamics');

        $expectedTypes = [
            'pdf' => 'application/pdf',
            'pantheon' => 'text/xml',
            'spica' => 'text/csv',
            'option' => 'text/csv',
            'skula' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'infonet' => 'application/json',
            'dynamics' => 'application/json',
        ];

        foreach ($expectedTypes as $name => $mimeType) {
            $response = $this->withToken($token)
                ->get("/api/travel-orders/{$order->client_id}/exports/{$name}")
                ->assertOk();
            $this->assertStringStartsWith($mimeType, $response->headers->get('content-type'));
            $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
        }
    }

    public function test_user_cannot_export_another_users_order(): void
    {
        $this->seed(ExportFormatSeeder::class);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = $owner->travelOrders()->create($this->orderData());

        $this->withToken($other->createToken('other')->plainTextToken)
            ->get("/api/travel-orders/{$order->client_id}/exports/pdf")
            ->assertNotFound();
    }

    public function test_skula_export_is_valid_and_uses_vendor_names_and_numeric_styles(): void
    {
        $this->seed(ExportFormatSeeder::class);
        $user = User::factory()->create();
        $order = $user->travelOrders()->create($this->orderData());
        $expenses = $order->expenses;
        $expenses[] = [
            'id' => 'expense-2',
            'category' => 'Smještaj',
            'description' => 'Račun',
            'vendor' => 'Test Hotel',
            'receiptNumber' => 'H-1',
            'date' => '2026-07-20',
            'amountInEur' => 10,
            'paymentMethod' => 'Kartica',
        ];
        $order->update(['expenses' => $expenses]);

        $content = $this->withToken($user->createToken('test')->plainTextToken)
            ->get("/api/travel-orders/{$order->client_id}/exports/skula")
            ->assertOk()
            ->getContent();

        $temporary = tempnam(sys_get_temp_dir(), 'skula_test_');
        $this->assertNotFalse($temporary);
        file_put_contents($temporary, $content);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $contentTypes = $zip->getFromName('[Content_Types].xml');

        $this->assertIsString($sheet);
        $this->assertStringContainsString('view="normal"', $sheet);
        $this->assertStringNotContainsString('pageBreakPreview', $sheet);
        $this->assertMatchesRegularExpression('/<c r="A1" s="\d+" t="inlineStr">.*TEST D\.O\.O\..*<\/c>/s', $sheet);
        $this->assertMatchesRegularExpression('/<c r="A2" s="\d+" t="inlineStr">.*OIB: 98765432109.*<\/c>/s', $sheet);
        $this->assertStringContainsString('<mergeCell ref="A1:B1"/><mergeCell ref="A2:B2"/><mergeCell ref="A3:B3"/>', $sheet);
        $this->assertStringContainsString('<mergeCell ref="A4:E4"/>', $sheet);
        $this->assertStringContainsString('<col min="6" max="16384" width="0" hidden="1" customWidth="1"/>', $sheet);
        $this->assertStringNotContainsString('r="F26"', $sheet);
        $this->assertMatchesRegularExpression('/<c r="B17"[^>]*>.*<sz val="9"\/>.*TEST OIL.*<\/c>/s', $sheet);
        $this->assertMatchesRegularExpression('/<c r="B20"[^>]*>.*TEST HOTEL.*<\/c>/s', $sheet);
        $this->assertMatchesRegularExpression('/<c r="C17" s="91"><v>1<\/v><\/c>/', $sheet);
        $this->assertMatchesRegularExpression('/<c r="D17" s="91" t="inlineStr">.*97,79.*<\/c>/s', $sheet);
        $this->assertMatchesRegularExpression('/<c r="E17" s="90" t="inlineStr">.*97,79 KM.*<\/c>/s', $sheet);
        $this->assertFalse($zip->locateName('xl/calcChain.xml'));
        $this->assertIsString($relationships);
        $this->assertStringNotContainsString('calcChain', $relationships);
        $this->assertIsString($contentTypes);
        $this->assertStringNotContainsString('calcChain', $contentTypes);

        $zip->close();
        @unlink($temporary);
    }

    private function orderData(): array
    {
        return [
            'client_id' => 'export-order-1',
            'order_number' => 'PN-2026-001',
            'status' => 'nacrt',
            'employee_name' => 'Test Employee',
            'employee_title' => 'Komercijalista',
            'employee_oib' => '12345678901',
            'employee_iban' => 'BA391290079401028494',
            'company_name' => 'Test d.o.o.',
            'company_oib' => '98765432109',
            'route' => 'Sarajevo – Zagreb',
            'start_location' => 'Sarajevo',
            'destination_country' => 'Hrvatska',
            'purpose' => 'Sastanak',
            'departure_time' => '2026-07-20 08:00:00',
            'arrival_time' => '2026-07-21 18:00:00',
            'route_stop_times' => [],
            'total_hours' => 34,
            'transport_type' => 'Automobil',
            'total_km' => 800,
            'total_km_cost' => 200,
            'daily_allowance_rate_eur' => 30,
            'total_allowance_cost' => 42.5,
            'expenses' => [[
                'id' => 'expense-1',
                'category' => 'Gorivo',
                'description' => 'Gorivo',
                'vendor' => 'Test Oil',
                'receiptNumber' => 'R-1',
                'date' => '2026-07-20',
                'amountInEur' => 50,
                'paymentMethod' => 'Kartica',
            ]],
            'total_expenses_cost' => 50,
            'advancement_paid' => 0,
            'grand_total' => 292.5,
            'balance_to_pay' => 292.5,
        ];
    }
}
