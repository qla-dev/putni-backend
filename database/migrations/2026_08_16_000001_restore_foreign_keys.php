<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $keys = $this->keys();
        $invalid = [];

        foreach ($keys as [$table, $column, $parent]) {
            $count = DB::table("{$table} as c")
                ->leftJoin("{$parent} as p", "c.{$column}", '=', 'p.id')
                ->whereNull('p.id')
                ->count();

            if ($count) {
                $invalid["{$table}.{$column} -> {$parent}.id"] = $count;
            }
        }

        if ($invalid) {
            throw new RuntimeException('Foreign-key repair stopped; no schema changed. Invalid references: '.collect($invalid)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));
        }

        foreach (collect($keys)->flatMap(fn ($key) => [$key[0], $key[2]])->unique()->sort() as $table) {
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
        }

        foreach ($keys as [$table, $column, $parent]) {
            $name = "fk_{$table}_{$column}";

            if (DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->where('REFERENCED_TABLE_NAME', $parent)
                ->exists()) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)->references('id')->on($parent)->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->keys()) as [$table, $column]) {
            $name = "fk_{$table}_{$column}";
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }

    private function keys(): array
    {
        return [
            ['travel_orders', 'user_id', 'users'],
            ['revenuecat_purchases', 'user_id', 'users'],
            ['companies', 'owner_id', 'users'],
            ['company_user', 'company_id', 'companies'],
            ['company_user', 'user_id', 'users'],
            ['vehicle_models', 'vehicle_brand_id', 'vehicle_brands'],
            ['user_vehicles', 'user_id', 'users'],
        ];
    }
};
