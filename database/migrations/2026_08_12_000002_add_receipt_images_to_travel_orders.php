<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->json('receipt_images')->nullable()->after('expenses');
        });

        DB::table('travel_orders')->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                $expenses = is_string($order->expenses) ? json_decode($order->expenses, true) : $order->expenses;
                $images = [];
                foreach (is_array($expenses) ? $expenses : [] as $expense) {
                    if (empty($expense['imageUri']) && empty($expense['imageData'])) continue;
                    $images[] = [
                        'id' => 'legacy-'.($expense['id'] ?? uniqid()),
                        'expenseId' => $expense['id'] ?? '',
                        'imageUri' => $expense['imageUri'] ?? null,
                        'imageData' => $expense['imageData'] ?? null,
                        'imageMimeType' => $expense['imageMimeType'] ?? null,
                    ];
                }
                DB::table('travel_orders')->where('id', $order->id)->update([
                    'receipt_images' => json_encode($images, JSON_UNESCAPED_SLASHES),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->dropColumn('receipt_images');
        });
    }
};
