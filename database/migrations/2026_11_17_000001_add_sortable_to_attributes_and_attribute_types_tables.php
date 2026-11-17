<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['attribute_types', 'attributes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('sortable')->default(false)->after('searchable');
            });
        }

        // A type whose values can be filtered on can be sorted by as well.
        DB::table('attribute_types')->where('filterable', true)->update(['sortable' => true]);
    }

    public function down(): void
    {
        foreach (['attribute_types', 'attributes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('sortable');
            });
        }
    }
};
