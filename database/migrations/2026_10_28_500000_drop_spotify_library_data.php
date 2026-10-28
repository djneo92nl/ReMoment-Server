<?php

use App\Domain\Library\SpotifyLibraryPurge;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // The library is the local tracks; Spotify extends it live (docs/architecture/availability-layer.md). Removes what the old
    // Spotify import left behind, once. Tracks another source also has stay. `php artisan library:drop-spotify` does the same by hand.
    public function up(): void
    {
        SpotifyLibraryPurge::run();
    }

    public function down(): void
    {
        // The removed rows came from Spotify and are not restored: nothing imports them any more.
    }
};
