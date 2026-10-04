<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Household-wide favorites: when a radio station was favorited, null when it isn't. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radio_stations', function (Blueprint $table) {
            $table->timestamp('favorited_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('radio_stations', function (Blueprint $table) {
            $table->dropIndex(['favorited_at']);
            $table->dropColumn('favorited_at');
        });
    }
};
