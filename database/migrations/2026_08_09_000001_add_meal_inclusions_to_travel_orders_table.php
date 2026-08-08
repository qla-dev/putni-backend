<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->boolean('breakfast_included')->default(false)->after('total_allowance_cost');
            $table->boolean('lunch_included')->default(false)->after('breakfast_included');
            $table->boolean('dinner_included')->default(false)->after('lunch_included');
        });
    }

    public function down(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->dropColumn(['breakfast_included', 'lunch_included', 'dinner_included']);
        });
    }
};
