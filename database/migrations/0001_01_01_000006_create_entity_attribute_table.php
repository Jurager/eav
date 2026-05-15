<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('entity_attribute', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete()->cascadeOnUpdate();

            if ($isPgsql) {
                $table->citext('value_text')->nullable();
            } else {
                $table->string('value_text')->nullable()->collation('utf8mb4_unicode_ci');
            }

            $table->bigInteger('value_integer')->nullable();
            $table->double('value_float')->nullable();
            $table->tinyInteger('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();

            $table->index(['entity_type', 'entity_id'], 'idx_entity_lookup');
            $table->index(['entity_type', 'entity_id', 'attribute_id', 'id'], 'idx_entity_attribute_sort');

            $table->index(['entity_type', 'attribute_id', 'value_integer'], 'idx_ea_filter_integer');
            $table->index(['entity_type', 'attribute_id', 'value_float'], 'idx_ea_filter_float');
            $table->index(['entity_type', 'attribute_id', 'value_boolean'], 'idx_ea_filter_boolean');
            $table->index(['entity_type', 'attribute_id', 'value_date'], 'idx_ea_filter_date');
            $table->index(['entity_type', 'attribute_id', 'value_datetime'], 'idx_ea_filter_datetime');
            $table->index(['entity_type', 'attribute_id', 'value_text'], 'idx_ea_filter_text');

            $table->timestamps();
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX idx_ea_value_text_trgm ON entity_attribute USING gin (value_text gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_attribute');
    }
};
