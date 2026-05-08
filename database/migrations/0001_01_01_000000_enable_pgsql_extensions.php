<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }
    }

    public function down(): void
    {
        // Extensions are shared database resources — not dropped on rollback.
    }
};
