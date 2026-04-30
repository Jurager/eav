<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attribute_enums', function (Blueprint $table) {
            $table->integer('sort')->default(0)->after('code');
            $table->index('sort');
        });
    }

    public function down(): void
    {
        Schema::table('attribute_enums', function (Blueprint $table) {
            $table->dropIndex(['sort']);
            $table->dropColumn('sort');
        });
    }
};
