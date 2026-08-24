<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Enums\HeldBy;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->string('held_by')->default(HeldBy::Parent->value)->after('searchable');
            $table->boolean('inherit_from_parent')->default(true)->after('held_by');
        });
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn(['held_by', 'inherit_from_parent']);
        });
    }
};
