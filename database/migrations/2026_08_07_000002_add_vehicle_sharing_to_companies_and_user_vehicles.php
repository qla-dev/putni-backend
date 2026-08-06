<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('share_vehicles')->default(false)->after('share_ai_tokens');
        });

        Schema::table('user_vehicles', function (Blueprint $table) {
            $table->boolean('share_with_team')->default(false)->after('ownership_type');
        });
    }

    public function down(): void
    {
        Schema::table('user_vehicles', function (Blueprint $table) {
            $table->dropColumn('share_with_team');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('share_vehicles');
        });
    }
};
