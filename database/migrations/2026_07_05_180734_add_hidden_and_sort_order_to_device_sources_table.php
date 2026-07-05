<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_sources', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('borrowed');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('hidden');
        });
    }

    public function down(): void
    {
        Schema::table('device_sources', function (Blueprint $table) {
            $table->dropColumn(['hidden', 'sort_order']);
        });
    }
};
