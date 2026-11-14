<?php

use App\Domain\Device\DeviceModels;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The device edit form once posted the driver class with its backslashes eaten by a JavaScript string
 * ("AppIntegrationsPhilips…"), so an edited device had no driver and no listener. Restore the real class name.
 */
return new class extends Migration
{
    public function up(): void
    {
        $known = [];

        foreach (DeviceModels::all() as $models) {
            foreach ($models as $definition) {
                $known[] = $definition['driver'] ?? null;
            }
        }

        $known = array_filter([...$known, ...array_keys(config('devices.listeners', []))]);

        foreach (DB::table('devices')->get(['id', 'device_driver']) as $device) {
            if ($device->device_driver === null || class_exists($device->device_driver)) {
                continue;
            }

            foreach ($known as $class) {
                if (str_replace('\\', '', $class) === $device->device_driver) {
                    DB::table('devices')->where('id', $device->id)->update(['device_driver' => $class]);

                    break;
                }
            }
        }
    }

    public function down(): void {}
};
