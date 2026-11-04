<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_ea_filter_text');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ea_filter_text ON entity_attribute USING hash (value_text)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_ea_filter_text');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ea_filter_text ON entity_attribute (entity_type, attribute_id, value_text) WHERE octet_length(value_text) <= 1000');
    }
};
