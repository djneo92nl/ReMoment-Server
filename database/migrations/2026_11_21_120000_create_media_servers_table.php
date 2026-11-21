<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Media servers (workstream 15, docs/architecture/media-servers.md): one row per Jellyfin (later Plex, Emby, Kodi) server.
        Schema::create('media_servers', function (Blueprint $table) {
            $table->id();
            // The registry type in config/media-servers.php ('jellyfin'); which driver class talks to the server.
            $table->string('driver', 32);
            $table->string('name', 120);
            // The server's address. Null = the base URL of the credentials the row points at (Settings › Services).
            $table->string('base_url', 255)->nullable();
            // The ServiceCredentials service id the key is read from ('jellyfin'); null = the driver's own id.
            $table->string('credential', 64)->nullable();
            $table->boolean('enabled')->default(true);
            // Driver specifics (nothing for Jellyfin yet).
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('driver');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_servers');
    }
};
