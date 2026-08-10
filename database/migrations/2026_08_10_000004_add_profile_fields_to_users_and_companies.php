<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('oib', 50)->nullable()->after('phone');
            $table->string('iban', 50)->nullable()->after('oib');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->string('oib', 50)->nullable()->after('name');
            $table->string('address')->nullable()->after('oib');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('country', 100)->nullable()->after('city');
            $table->string('email')->nullable()->after('country');
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('iban', 50)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['oib', 'address', 'city', 'country', 'email', 'phone', 'iban']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['phone', 'oib', 'iban']));
    }
};
