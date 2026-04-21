<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\DiscoveryInterface;

class AseDiscovery implements DiscoveryInterface
{
    private string $multicastAddr = '239.255.255.250';

    private int $multicastPort = 1900;

    private int $timeout = 4;

    public function discover(): array
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, '0.0.0.0', 0);

        $request = "M-SEARCH * HTTP/1.1\r\n".
            "HOST: {$this->multicastAddr}:{$this->multicastPort}\r\n".
            "MAN: \"ssdp:discover\"\r\n".
            "MX: 3\r\n".
            "ST: urn:schemas-upnp-org:device:MediaRenderer:1\r\n".
            "USER-AGENT: PHP/SSDP-Discovery\r\n".
            "\r\n";

        socket_sendto($socket, $request, strlen($request), 0, $this->multicastAddr, $this->multicastPort);

        $raw = [];
        $start = time();
        while (time() - $start < $this->timeout) {
            $read = [$socket];
            $write = $except = [];
            $changed = socket_select($read, $write, $except, $this->timeout);
            if ($changed === false || $changed === 0) {
                break;
            }
            $buf = '';
            $from = '';
            $port = 0;
            socket_recvfrom($socket, $buf, 2048, 0, $from, $port);

            $headers = [];
            foreach (explode("\r\n", $buf) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $val] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($val);
                }
            }

            if (isset($headers['location'])) {
                $raw[$from] = [
                    'ip' => $from,
                    'location' => $headers['location'],
                ];
            }
        }

        socket_close($socket);

        $discovered = [];

        foreach ($raw as $info) {
            $descriptor = $this->fetchDescriptor($info['location']);
            if (!$descriptor) {
                continue;
            }

            $deviceInfo = $descriptor['device'] ?? [];
            $manufacturer = $deviceInfo['manufacturer'] ?? null;
            $model = $deviceInfo['modelName'] ?? null;

            if (!$manufacturer || !$model) {
                continue;
            }

            $driverConfig = config("devices.{$manufacturer}.{$model}");
            if (!$driverConfig) {
                continue;
            }

            $udn = $deviceInfo['UDN'] ?? null;

            $discovered[] = new DiscoveredDevice(
                ip_address: $info['ip'],
                device_name: $deviceInfo['friendlyName'] ?? $info['ip'],
                device_brand_name: $manufacturer,
                device_product_type: $model,
                device_driver: $driverConfig['driver'],
                device_driver_name: $driverConfig['driver_name'],
                meta: $udn ? ['upnp_uuid' => $udn] : [],
            );
        }

        return $discovered;
    }

    private function fetchDescriptor(string $url): ?array
    {
        $xml = @file_get_contents($url);
        if (!$xml) {
            return null;
        }

        $parsed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$parsed) {
            return null;
        }

        return json_decode(json_encode($parsed), true);
    }
}
