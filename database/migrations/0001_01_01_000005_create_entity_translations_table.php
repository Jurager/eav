<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('entity_translations', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('locale_id')->constrained('locales')->cascadeOnDelete()->cascadeOnUpdate();

            if ($isPgsql) {
                $table->citext('label');
            } else {
                $table->string('label')->collation('utf8mb4_unicode_ci');
            }

            $table->json('params')->nullable();

            $table->unique(['entity_type', 'entity_id', 'locale_id']);

            $table->timestamps();
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX idx_et_label_trgm ON entity_translations USING gin (label gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_translations');
    }
};
