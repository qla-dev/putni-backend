<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_id')->unique();
            $table->string('order_number');
            $table->string('status', 20);
            $table->string('employee_name');
            $table->string('employee_title');
            $table->string('employee_oib', 50);
            $table->string('employee_iban', 50);
            $table->string('company_name');
            $table->string('company_oib', 50);
            $table->text('route');
            $table->string('start_location')->nullable();
            $table->string('destination_country');
            $table->text('purpose');
            $table->dateTimeTz('departure_time');
            $table->dateTimeTz('arrival_time');
            $table->json('route_stop_times')->nullable();
            $table->decimal('total_hours', 10, 2)->default(0);
            $table->string('transport_type', 100);
            $table->string('vehicle_name')->nullable();
            $table->string('vehicle_plate', 100)->nullable();
            $table->decimal('total_km', 12, 2)->default(0);
            $table->decimal('total_km_cost', 12, 2)->default(0);
            $table->decimal('daily_allowance_rate_eur', 12, 2)->default(0);
            $table->decimal('total_allowance_cost', 12, 2)->default(0);
            $table->json('expenses');
            $table->decimal('total_expenses_cost', 12, 2)->default(0);
            $table->decimal('advancement_paid', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('balance_to_pay', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_orders');
    }
};
