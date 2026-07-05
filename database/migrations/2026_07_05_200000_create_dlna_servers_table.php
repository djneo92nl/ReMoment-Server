<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dlna_servers', function (Blueprint $table) {
            $table->id();
            $table->string('friendly_name');
            $table->string('ip');
            $table->unsignedSmallInteger('port');
            $table->string('control_url');
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['ip', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dlna_servers');
    }
};
