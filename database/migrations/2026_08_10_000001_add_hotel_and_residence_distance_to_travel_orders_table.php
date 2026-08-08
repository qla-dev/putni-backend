<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->boolean('hotel_included')->default(false)->after('dinner_included');
            $table->decimal('residence_distance_km', 12, 2)->default(0)->after('hotel_included');
        });
    }

    public function down(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->dropColumn(['hotel_included', 'residence_distance_km']);
        });
    }
};
