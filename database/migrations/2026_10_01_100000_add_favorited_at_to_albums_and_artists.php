<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Household-wide favorites: when an album or artist was favorited, null when it isn't. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['albums', 'artists'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('favorited_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['albums', 'artists'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['favorited_at']);
                $table->dropColumn('favorited_at');
            });
        }
    }
};
