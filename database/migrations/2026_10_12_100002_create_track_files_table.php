<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The files of a track: the original and any proxies (transcodes). A file can exist without a track. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('original'); // original | proxy
            $table->string('profile')->nullable(); // e.g. mp3-320 for a proxy
            $table->foreignId('derived_from_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['track_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_files');
    }
};
