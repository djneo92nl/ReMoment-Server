<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceMeta;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeviceDiscovery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:discovery';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts device discovery to populate our known local devices (run out of Docker)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // For now, we only check for ASE Bang&Olufsen Devices (Maybe works for Mozart? )

        $iface = 'en0'; // or whatever your main network interface is
        $multicastAddr = '239.255.255.250';
        $port = 1900;

        $localIp = '0.0.0.0'; // your Mac’s LAN IP
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, $localIp, 0);

        $timeout = 4; // seconds to wait for replies

        $request = "M-SEARCH * HTTP/1.1\r\n".
            "HOST: 239.255.255.250:1900\r\n".
            "MAN: \"ssdp:discover\"\r\n".
            "MX: 3\r\n".
            "ST:  urn:schemas-upnp-org:device:MediaRenderer:1\r\n".   // <--- tells devices to respond regardless of type
            "USER-AGENT: PHP/SSDP-Discovery\r\n".
            "\r\n";

        socket_sendto($socket, $request, strlen($request), 0, $multicastAddr, $port);

        // Collect responses for a few seconds
        $devices = [];
        $start = time();
        while (time() - $start < $timeout) {
            $read = [$socket];
            $write = $except = [];
            $changed = socket_select($read, $write, $except, $timeout);
            if ($changed === false) {
                break;
            }
            if ($changed > 0) {
                $buf = '';
                $from = '';
                $port = 0;
                socket_recvfrom($socket, $buf, 2048, 0, $from, $port);

                // Parse headers
                $headers = [];
                foreach (explode("\r\n", $buf) as $line) {
                    if (strpos($line, ':') !== false) {
                        [$key, $val] = explode(':', $line, 2);
                        $headers[strtolower(trim($key))] = trim($val);
                    }
                }

                if (isset($headers['location'])) {
                    $devices[$from] = [
                        'ip' => $from,
                        'location' => $headers['location'] ?? null,
                        'server' => $headers['server'] ?? null,
                        'usn' => $headers['usn'] ?? null,
                    ];
                }
            }
        }

        socket_close($socket);

        // Print found devices
        $this->line('Found Media Devices : '.count($devices));

        // each device has a XML describing the device
        foreach ($devices as $from => $info) {
            $deviceInfo = $this->xmlUrlToArray($info['location'])['device'];

            if (isset($deviceInfo['manufacturer'])) {
                $this->line('Found Device made by : '.$deviceInfo['manufacturer'].' : '.$deviceInfo['modelName']);
                if (array_key_exists($deviceInfo['manufacturer'], config('devices'))) {

                    $this->info('found driver');
                    $deviceUuid = $deviceInfo['UDN'] ?? null;
                    $device = null;

                    if ($deviceUuid) {
                        $device = Device::whereHas('meta', function ($query) use ($deviceUuid) {
                            $query->where('key', 'upnp_uuid')
                                ->where('value', $deviceUuid);
                        })->first();
                    }

                    $deviceData = [
                        'ip_address' => $info['ip'],
                        'device_brand_name' => $deviceInfo['manufacturer'],
                        'device_product_type' => $deviceInfo['modelName'],
                        'device_name' => $deviceInfo['friendlyName'],
                        'device_driver' => config('devices.'.$deviceInfo['manufacturer'].'.'.$deviceInfo['modelName'])['driver'],
                        'device_driver_name' => config('devices.'.$deviceInfo['manufacturer'].'.'.$deviceInfo['modelName'])['driver_name'],
                        'last_seen' => Carbon::now(),
                    ];

                    if ($device) {
                        $device->fill($deviceData);
                        $device->save();
                    } else {
                        $device = Device::create($deviceData);
                    }

                    if ($deviceUuid) {
                        DeviceMeta::updateOrCreate(
                            [
                                'device_id' => $device->id,
                                'key' => 'upnp_uuid',
                            ],
                            [
                                'value' => $deviceUuid,
                            ]
                        );
                    }

                }
            }

        }

    }

    public function xmlUrlToArray($url)
    {
        // Fetch the XML from the URL
        $xmlString = file_get_contents($url);
        if (!$xmlString) {
            return false;
        }

        // Load and convert to array
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) {
            return false;
        }

        $json = json_encode($xml);
        $array = json_decode($json, true);

        return $array;
    }
}
