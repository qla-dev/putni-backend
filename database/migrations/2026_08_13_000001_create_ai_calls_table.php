<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table): void {
            $table->id();
            $table->longText('prompt');
            $table->longText('response');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
