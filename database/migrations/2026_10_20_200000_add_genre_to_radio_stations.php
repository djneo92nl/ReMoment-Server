<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A station's main genre (filled when added from the radio-browser.info search); null when unknown. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radio_stations', function (Blueprint $table) {
            $table->string('genre')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('radio_stations', function (Blueprint $table) {
            $table->dropColumn('genre');
        });
    }
};
