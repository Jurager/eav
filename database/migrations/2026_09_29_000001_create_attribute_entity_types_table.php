<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_entity_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('entity_type');
            $table->timestamps();

            $table->unique(['entity_type', 'attribute_id']);
        });

        // Backfill: every existing attribute keeps exactly the one entity_type it already had.
        DB::table('attributes')
            ->select('id', 'entity_type', 'created_at', 'updated_at')
            ->orderBy('id')
            ->each(function (object $attribute): void {
                DB::table('attribute_entity_types')->insert([
                    'attribute_id' => $attribute->id,
                    'entity_type' => $attribute->entity_type,
                    'created_at' => $attribute->created_at,
                    'updated_at' => $attribute->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_entity_types');
    }
};
