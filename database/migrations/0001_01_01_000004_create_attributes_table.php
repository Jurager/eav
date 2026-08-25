<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Enums\HeldBy;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->foreignId('attribute_type_id')->constrained('attribute_types')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('attribute_group_id')->nullable()->constrained('attribute_groups')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('code', 100);
            $table->integer('sort')->default(0);

            $table->boolean('required')->default(false);
            $table->json('validations')->nullable();
            $table->jsonb('meta')->nullable();

            $table->boolean('system')->default(false);
            $table->boolean('localizable')->default(false);
            $table->boolean('multiple')->default(false);
            $table->boolean('unique')->default(false);
            $table->boolean('filterable')->default(false);
            $table->boolean('searchable')->default(false);

            $table->string('held_by')->default(HeldBy::Parent->value);
            $table->boolean('inherit_from_parent')->default(true);

            $table->unique(['entity_type', 'code']);
            $table->index(['entity_type', 'searchable']);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};
