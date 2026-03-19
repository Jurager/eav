<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_translations', function (Blueprint $table) {
            $table->id();
            $table->morphs('entity');
            $table->foreignId('locale_id')->constrained('locales')->cascadeOnDelete()->cascadeOnUpdate();
            $table->text('label');
            $table->json('params')->nullable();

            $table->unique(['entity_type', 'entity_id', 'locale_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_translations');
    }
};
