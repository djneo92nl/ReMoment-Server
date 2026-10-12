<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A file on a storage location, independent of any track (see track_files). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_location_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024); // relative to the location's root
            $table->string('path_hash', 64); // sha256 of path: unique index without a long key
            $table->string('hash', 64)->index(); // sha256 of the content
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('mtime');
            $table->string('mime_type')->nullable();
            $table->string('extension', 16)->nullable();
            $table->string('codec')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedSmallInteger('bit_depth')->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('status')->default('present'); // present | missing
            $table->timestamps();

            $table->unique(['storage_location_id', 'path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
