<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('entity_attribute', function (Blueprint $table) {
            $table->index('value_date', 'idx_value_date');
            $table->index('value_datetime', 'idx_value_datetime');
        });
    }

    public function down(): void
    {
        Schema::table('entity_attribute', function (Blueprint $table) {
            $table->dropIndex('idx_value_date');
            $table->dropIndex('idx_value_datetime');
        });
    }
};
