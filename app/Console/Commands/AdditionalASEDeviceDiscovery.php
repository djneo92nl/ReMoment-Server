<?php

namespace App\Console\Commands;

use App\Integrations\BangOlufsen\Common\MozartDiscoveryService;
use App\Models\Device;
use App\Models\DeviceMeta;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AdditionalASEDeviceDiscovery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:additional-bo-device-discovery';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public function __construct(private MozartDiscoveryService $discovery)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     * _bangolufsen._tcp.local.
     */
    public function handle()
    {

        $devices = $this->discovery->discover();
        foreach ($devices as $deviceInfo) {
            $info = $deviceInfo['info'] ?? [];
            $jid = $info['jid'] ?? $info['JID'] ?? null;

            if (empty($deviceInfo['ip'])) {
                continue;
            }

            $manufacturer = $info['manufacturer'] ?? 'Bang & Olufsen';
            $modelName = $info['modelName'] ?? $info['model'] ?? $info['md'] ?? null;
            $deviceName = $deviceInfo['instance'] ?? $deviceInfo['hostname'] ?? $deviceInfo['ip'];

            $device = null;
            if ($jid) {
                $device = Device::whereHas('meta', function ($query) use ($jid) {
                    $query->where('key', 'jid')
                        ->where('value', $jid);
                })->first();
            }

            if (!$device && isset($deviceInfo['ip'])) {
                $device = Device::where('ip_address', $deviceInfo['ip'])->first();
            }

            $deviceData = [
                'ip_address' => $deviceInfo['ip'],
                'device_brand_name' => $manufacturer,
                'device_product_type' => $modelName,
                'device_name' => $deviceName,
                'last_seen' => Carbon::now(),
            ];

            if ($modelName
                && array_key_exists($manufacturer, config('devices'))
                && array_key_exists($modelName, config('devices.'.$manufacturer))) {
                $deviceData['device_driver'] = config('devices.'.$manufacturer.'.'.$modelName)['driver'];
                $deviceData['device_driver_name'] = config('devices.'.$manufacturer.'.'.$modelName)['driver_name'];
            }

            if ($device) {
                $device->fill($deviceData);
                $device->save();
            } else {
                $device = Device::create($deviceData);
            }

            if ($jid) {
                DeviceMeta::updateOrCreate(
                    [
                        'device_id' => $device->id,
                        'key' => 'jid',
                    ],
                    [
                        'value' => $jid,
                    ]
                );
            }
        }
    }
}
