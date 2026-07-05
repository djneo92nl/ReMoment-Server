<?php

namespace App\Services\Dlna;

use App\Models\DlnaServer;

class DlnaServerDiscovery
{
    private string $multicastAddr = '239.255.255.250';

    private int $multicastPort = 1900;

    private int $timeout = 4;

    /** @return DlnaServer[] */
    public function discover(): array
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, '0.0.0.0', 0);

        $request = "M-SEARCH * HTTP/1.1\r\n".
            "HOST: {$this->multicastAddr}:{$this->multicastPort}\r\n".
            'MAN: "ssdp:discover"'."\r\n".
            "MX: 3\r\n".
            "ST: urn:schemas-upnp-org:device:MediaServer:1\r\n".
            "USER-AGENT: PHP/SSDP-Discovery\r\n".
            "\r\n";

        socket_sendto($socket, $request, strlen($request), 0, $this->multicastAddr, $this->multicastPort);

        $locations = [];
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

            foreach (explode("\r\n", $buf) as $line) {
                if (stripos($line, 'location:') === 0) {
                    $locations[] = trim(substr($line, 9));
                    break;
                }
            }
        }

        socket_close($socket);

        $servers = [];
        foreach (array_unique($locations) as $location) {
            $server = $this->resolveServer($location);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    private function resolveServer(string $location): ?DlnaServer
    {
        $xml = @file_get_contents($location);
        if (! $xml) {
            return null;
        }

        $parsed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (! $parsed) {
            return null;
        }

        $controlUrl = null;
        foreach ($parsed->device->serviceList->service ?? [] as $service) {
            if ((string) $service->serviceType === 'urn:schemas-upnp-org:service:ContentDirectory:1') {
                $controlUrl = (string) $service->controlURL;
                break;
            }
        }

        if (! $controlUrl) {
            return null;
        }

        $parsed_url = parse_url($location);
        $base = $parsed_url['scheme'].'://'.$parsed_url['host'].':'.$parsed_url['port'];

        // controlURL may be relative or absolute
        if (! str_starts_with($controlUrl, 'http')) {
            $controlUrl = $base.'/'.ltrim($controlUrl, '/');
        }

        $friendlyName = (string) ($parsed->device->friendlyName ?? $parsed_url['host']);
        $ip = $parsed_url['host'];
        $port = (int) ($parsed_url['port'] ?? 80);

        return DlnaServer::updateOrCreate(
            ['ip' => $ip, 'port' => $port],
            ['friendly_name' => $friendlyName, 'control_url' => $controlUrl],
        );
    }
}
