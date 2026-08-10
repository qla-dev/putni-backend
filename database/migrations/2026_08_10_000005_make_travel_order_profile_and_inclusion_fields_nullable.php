<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->string('employee_title')->nullable()->change();
            $table->string('employee_oib', 50)->nullable()->change();
            $table->string('employee_iban', 50)->nullable()->change();
            $table->boolean('breakfast_included')->nullable()->default(false)->change();
            $table->boolean('lunch_included')->nullable()->default(false)->change();
            $table->boolean('dinner_included')->nullable()->default(false)->change();
            $table->boolean('hotel_included')->nullable()->default(false)->change();
            $table->decimal('residence_distance_km', 12, 2)->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->string('employee_title')->nullable(false)->change();
            $table->string('employee_oib', 50)->nullable(false)->change();
            $table->string('employee_iban', 50)->nullable(false)->change();
            $table->boolean('breakfast_included')->nullable(false)->default(false)->change();
            $table->boolean('lunch_included')->nullable(false)->default(false)->change();
            $table->boolean('dinner_included')->nullable(false)->default(false)->change();
            $table->boolean('hotel_included')->nullable(false)->default(false)->change();
            $table->decimal('residence_distance_km', 12, 2)->nullable(false)->default(0)->change();
        });
    }
};
