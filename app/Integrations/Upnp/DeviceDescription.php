<?php

namespace App\Integrations\Upnp;

use Illuminate\Support\Facades\Http;

/**
 * What a UPnP device descriptor says: who it is and where each service is controlled. Service URLs are
 * absolute (resolved against the descriptor's LOCATION). The SCPDs, which list the actions and the
 * volume range, are read on demand with {@see actions()} / {@see volumeMax()}.
 */
final class DeviceDescription
{
    public const AV_TRANSPORT = 'urn:schemas-upnp-org:service:AVTransport:1';

    public const RENDERING_CONTROL = 'urn:schemas-upnp-org:service:RenderingControl:1';

    public const CONNECTION_MANAGER = 'urn:schemas-upnp-org:service:ConnectionManager:1';

    /** @param  array<string, array{control: string, scpd: ?string}>  $services  by service type */
    public function __construct(
        public readonly string $location,
        public readonly string $friendlyName,
        public readonly ?string $manufacturer,
        public readonly ?string $modelName,
        public readonly ?string $udn,
        public readonly array $services,
    ) {}

    public static function fetch(string $location): ?self
    {
        try {
            $xml = Http::timeout(3)->get($location)->body();
        } catch (\Throwable) {
            return null;
        }

        return self::fromXml($xml, $location);
    }

    public static function fromXml(string $xml, string $location): ?self
    {
        $dom = new \DOMDocument;

        if ($xml === '' || !@$dom->loadXML($xml)) {
            return null;
        }

        $text = fn (string $tag) => ($value = trim($dom->getElementsByTagNameNS('*', $tag)->item(0)?->textContent ?? '')) === '' ? null : $value;

        $services = [];
        foreach ($dom->getElementsByTagNameNS('*', 'service') as $service) {
            $child = fn (string $tag) => trim($service->getElementsByTagNameNS('*', $tag)->item(0)?->textContent ?? '');
            $type = $child('serviceType');
            $control = $child('controlURL');

            if ($type !== '' && $control !== '') {
                $services[$type] = [
                    'control' => self::resolve($location, $control),
                    'scpd' => ($scpd = $child('SCPDURL')) !== '' ? self::resolve($location, $scpd) : null,
                ];
            }
        }

        return new self(
            location: $location,
            friendlyName: $text('friendlyName') ?? parse_url($location, PHP_URL_HOST) ?? $location,
            manufacturer: $text('manufacturer'),
            modelName: $text('modelName'),
            udn: $text('UDN'),
            services: $services,
        );
    }

    public function controlUrl(string $serviceType): ?string
    {
        return $this->services[$serviceType]['control'] ?? null;
    }

    /** @return string[] action names of a service (empty when the SCPD can't be read) */
    public function actions(string $serviceType): array
    {
        $dom = $this->scpd($serviceType);
        $names = [];

        foreach ($dom?->getElementsByTagNameNS('*', 'action') ?? [] as $action) {
            if ($name = trim($action->getElementsByTagNameNS('*', 'name')->item(0)?->textContent ?? '')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** The highest value of the RenderingControl `Volume` variable (usually 100), null when the SCPD doesn't say. */
    public function volumeMax(): ?int
    {
        foreach ($this->scpd(self::RENDERING_CONTROL)?->getElementsByTagNameNS('*', 'stateVariable') ?? [] as $variable) {
            if (trim($variable->getElementsByTagNameNS('*', 'name')->item(0)?->textContent ?? '') === 'Volume') {
                $max = trim($variable->getElementsByTagNameNS('*', 'maximum')->item(0)?->textContent ?? '');

                return is_numeric($max) ? (int) $max : null;
            }
        }

        return null;
    }

    private function scpd(string $serviceType): ?\DOMDocument
    {
        $url = $this->services[$serviceType]['scpd'] ?? null;

        if ($url === null) {
            return null;
        }

        try {
            $xml = Http::timeout(3)->get($url)->body();
        } catch (\Throwable) {
            return null;
        }

        $dom = new \DOMDocument;

        return $xml !== '' && @$dom->loadXML($xml) ? $dom : null;
    }

    private static function resolve(string $location, string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $parts = parse_url($location);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($path, '/')) {
            return $origin.$path;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$path;
    }
}
