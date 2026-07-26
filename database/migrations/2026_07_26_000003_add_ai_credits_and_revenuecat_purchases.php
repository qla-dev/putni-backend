<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('ai_order_credits')->default(80);
        });

        Schema::create('revenuecat_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_id')->unique();
            $table->string('product_id');
            $table->unsignedInteger('credits');
            $table->timestamp('purchased_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenuecat_purchases');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_order_credits');
        });
    }
};
