<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where audio files live: a Flysystem disk (local/mounted path, S3-compatible), as library home or import inbox. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver'); // local | s3
            $table->text('config'); // encrypted JSON: local root; s3 bucket, region, key, secret, endpoint, …
            $table->string('role')->default('library'); // library | inbox
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
    }
};
