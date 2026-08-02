<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lampyris_users', function (Blueprint $table) {
            $table->timestamp('free_access_expires_at')->nullable()->after('apple_id');
        });
    }

    public function down(): void
    {
        Schema::table('lampyris_users', function (Blueprint $table) {
            $table->dropColumn('free_access_expires_at');
        });
    }
};
