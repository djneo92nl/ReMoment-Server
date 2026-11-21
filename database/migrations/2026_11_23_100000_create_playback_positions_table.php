<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Resume position (workstream 8.3, App\Domain\Resume\PlaybackPositions): one bookmark per long track or podcast
    // episode, household-wide (there are no accounts). `kind` + `item_id` point at a track or a podcast episode without a
    // foreign key, so a bookmark never blocks removing the item; the position is cleared when the item finishes.
    public function up(): void
    {
        Schema::create('playback_positions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16); // track | episode
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('position'); // seconds
            $table->unsignedInteger('duration')->nullable(); // seconds, when known
            $table->unsignedBigInteger('device_id')->nullable(); // the device that last reported it; not a foreign key
            $table->timestamps();

            $table->unique(['kind', 'item_id']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_positions');
    }
};
