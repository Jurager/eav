<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['locales', 'attribute_types', 'attribute_groups'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['locales', 'attribute_types', 'attribute_groups'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropTimestamps();
            });
        }
    }
};
