<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('entity_attribute', function (Blueprint $table) {
            $table->id();
            $table->morphs('entity');
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete()->cascadeOnUpdate();

            $table->citext('value_text')->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->double('value_float')->nullable();
            $table->tinyInteger('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();

            $table->index(['entity_type', 'entity_id'], 'idx_entity_lookup');
            $table->index(['entity_type', 'entity_id', 'attribute_id'], 'idx_entity_attribute');
            $table->index('value_integer', 'idx_value_integer');
            $table->index('value_float', 'idx_value_float');
            $table->index('value_boolean', 'idx_value_boolean');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_attribute');
    }
};
