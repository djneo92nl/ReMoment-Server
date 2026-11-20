<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sub device connected to a host (workstream 2a, docs/architecture/sub-devices.md): a BeoLink Converter whose
        // source a player borrows, a Chromecast on a TV's HDMI input. Both stay full devices; the link is the relation.
        Schema::create('device_links', function (Blueprint $table) {
            $table->id();
            // The sub device, and the host that decides whether it is heard or seen. Deleting either device ends the link.
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('host_device_id')->constrained('devices')->cascadeOnDelete();
            // borrow (a source the host borrowed from the sub device), input (an HDMI input), source (any other host source).
            $table->string('kind', 16);
            // The host's source id or input label that selects the sub device; '' = every source the sub device provides (borrow).
            // Not null on purpose: a unique key over a nullable column would allow the same link twice.
            $table->string('via', 191)->default('');
            // on_activate / on_deactivate actions, follow rule and its state (see App\Domain\SubDevices\LinkMeta).
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'host_device_id', 'kind', 'via']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_links');
    }
};
