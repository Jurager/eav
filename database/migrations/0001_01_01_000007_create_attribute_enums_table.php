<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_enums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('code', 100);

            $table->unique(['attribute_id', 'code']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_enums');
    }
};
