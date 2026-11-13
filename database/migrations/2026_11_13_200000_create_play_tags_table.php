<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tap to play (docs/api/tags.md): an NFC tag uid or QR code that plays an album, artist, playlist, track,
    // radio station or physical item. The target is a type and an id, not a foreign key: a tag outlives a
    // removed target and then answers "not playable".
    public function up(): void
    {
        Schema::create('play_tags', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 64)->unique(); // PlayTags::normalise: upper case, letters and digits only
            $table->string('label')->nullable();
            $table->string('target_type', 16); // album | artist | playlist | track | radio | physical
            $table->unsignedBigInteger('target_id');
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete(); // a fixed device; null: the reader's
            $table->boolean('shuffle')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_tags');
    }
};
