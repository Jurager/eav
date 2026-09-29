<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropUnique(['entity_type', 'code']);
            $table->dropIndex(['entity_type', 'searchable']);
            $table->dropColumn('entity_type');
        });

        Schema::table('attributes', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    /**
     * Best-effort rollback: restores the column and backfills it from the first entity_type
     * recorded for each attribute in the pivot. An attribute that became applicable to more
     * than one entity_type since the pivot was introduced loses the extra ones on rollback.
     */
    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->string('entity_type')->nullable()->after('id');
        });

        DB::table('attribute_entity_types')
            ->select('attribute_id', DB::raw('MIN(id) as pivot_id'), DB::raw('MIN(entity_type) as entity_type'))
            ->groupBy('attribute_id')
            ->orderBy('attribute_id')
            ->each(function (object $row): void {
                DB::table('attributes')->where('id', $row->attribute_id)->update(['entity_type' => $row->entity_type]);
            });

        Schema::table('attributes', function (Blueprint $table) {
            $table->string('entity_type')->nullable(false)->change();
            $table->unique(['entity_type', 'code']);
            $table->index(['entity_type', 'searchable']);
        });
    }
};
