<?php

namespace App\Services\Dlna;

use App\Models\DlnaServer;
use App\Services\Discovery\SsdpClient;
use Illuminate\Support\Facades\Http;

class DlnaServerDiscovery
{
    public function __construct(private SsdpClient $ssdp) {}

    /** @return DlnaServer[] */
    public function discover(): array
    {
        $servers = [];
        foreach ($this->ssdp->search('urn:schemas-upnp-org:device:MediaServer:1') as $found) {
            $server = $this->resolveServer($found['location']);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    private function resolveServer(string $location): ?DlnaServer
    {
        try {
            $xml = Http::timeout(3)->get($location)->body();
        } catch (\Throwable) {
            return null;
        }
        if (!$xml) {
            return null;
        }

        $parsed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$parsed) {
            return null;
        }

        $controlUrl = null;
        foreach ($parsed->device->serviceList->service ?? [] as $service) {
            if ((string) $service->serviceType === 'urn:schemas-upnp-org:service:ContentDirectory:1') {
                $controlUrl = (string) $service->controlURL;
                break;
            }
        }

        if (!$controlUrl) {
            return null;
        }

        $parsed_url = parse_url($location);
        $urlPort = $parsed_url['port'] ?? null;
        $base = $parsed_url['scheme'].'://'.$parsed_url['host'].($urlPort !== null ? ':'.$urlPort : '');

        // controlURL may be relative or absolute
        if (!str_starts_with($controlUrl, 'http')) {
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
