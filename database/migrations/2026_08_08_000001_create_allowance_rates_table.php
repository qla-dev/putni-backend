<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_rates', function (Blueprint $table) {
            $table->id();
            $table->string('country')->unique();
            $table->decimal('rate_km', 12, 2)->default(0);
            $table->decimal('rate_eur', 12, 2)->default(0);
            $table->string('region')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_rates');
    }
};
