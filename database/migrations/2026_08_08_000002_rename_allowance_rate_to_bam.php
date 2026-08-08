<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('allowance_rates', 'rate_eur')) {
            Schema::table('allowance_rates', function (Blueprint $table) {
                $table->renameColumn('rate_eur', 'rate_bam');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('allowance_rates', 'rate_bam')) {
            Schema::table('allowance_rates', function (Blueprint $table) {
                $table->renameColumn('rate_bam', 'rate_eur');
            });
        }
    }
};
