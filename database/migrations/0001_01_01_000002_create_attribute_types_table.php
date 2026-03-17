<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('attribute_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->boolean('localizable')->default(false);
            $table->boolean('multiple')->default(false);
            $table->boolean('unique')->default(false);
            $table->boolean('filterable')->default(false);
            $table->boolean('searchable')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_types');
    }
};
