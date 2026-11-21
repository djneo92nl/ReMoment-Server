<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Server-side timers of a device (workstream 8.1, App\Domain\Scenes\DeviceTimers): the sleep timer (fade the
    // volume, stop or standby, restore the volume) and the alarm's volume ramp. A timer is a list of timed events in
    // `state`, advanced by delayed AdvanceDeviceTimer jobs; only the row says whether it is still wanted, so a
    // cancel needs no queue access. Deleting the device ends its timers.
    public function up(): void
    {
        Schema::create('device_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('kind', 16); // sleep | ramp
            $table->string('status', 16)->default('active'); // active | done | cancelled | taken_over | stopped
            $table->unsignedBigInteger('scene_id')->nullable(); // the alarm scene that started a ramp; not a foreign key
            $table->json('state'); // events, index, original volume, action, log
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_timers');
    }
};
