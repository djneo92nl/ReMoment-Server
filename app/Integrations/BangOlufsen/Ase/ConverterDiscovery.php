<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\HostHintedDiscovery;
use App\Models\Device;

/**
 * Finds a BeoLink Converter NL/ML by asking `BeoDevice` on port 8080 of the hosts it is given (and of
 * converters already stored). The converter is not a UPnP MediaRenderer, so there is no multicast
 * search for it: run `php artisan device:discover --brand=converter --host=192.168.1.10`.
 */
class ConverterDiscovery implements HostHintedDiscovery
{
    public const PRODUCT_TYPE = 'BEOLINK_CONVERTER_NL_ML';

    public const MODEL = 'BeoLink Converter NL/ML';

    /** @var string[] */
    private array $hosts = [];

    /** @var string[] */
    private array $diagnostics = [];

    public function withHosts(array $hosts): static
    {
        $this->hosts = array_values(array_unique($hosts));

        return $this;
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function discover(): array
    {
        $this->diagnostics = [];

        $hosts = array_unique([
            ...$this->hosts,
            ...Device::where('device_product_type', self::MODEL)->pluck('ip_address')->all(),
        ]);

        if ($hosts === []) {
            $this->diagnostics[] = 'The converter has no multicast announcement: pass its IP with --host to find it.';

            return [];
        }

        $found = [];

        foreach ($hosts as $ip) {
            if ($device = $this->probe($ip)) {
                $found[] = $device;
            }
        }

        return $found;
    }

    private function probe(string $ip): ?DiscoveredDevice
    {
        try {
            $info = app(HttpConnector::class, ['baseUrl' => $ip.':8080'])->getStrict('BeoDevice')['beoDevice'] ?? [];
        } catch (\Throwable $e) {
            $this->diagnostics[] = "{$ip} did not answer on port 8080.";

            return null;
        }

        $id = $info['productId'] ?? [];

        if (($id['productType'] ?? null) !== self::PRODUCT_TYPE) {
            $this->diagnostics[] = "{$ip} is not a BeoLink Converter NL/ML (".($id['productType'] ?? 'unknown product').').';

            return null;
        }

        return new DiscoveredDevice(
            ip_address: $ip,
            device_name: $info['productFriendlyName']['productFriendlyName'] ?? self::MODEL,
            device_brand_name: 'Bang & Olufsen',
            device_product_type: self::MODEL,
            device_driver: ConverterDriver::class,
            device_driver_name: 'ASE',
            meta: array_filter([
                // Not `ase_jid`: that key feeds multiroom peer matching, and the converter has no zone to join.
                'converter_jid' => isset($id['typeNumber'], $id['itemNumber'], $id['serialNumber'])
                    ? "{$id['typeNumber']}.{$id['itemNumber']}.{$id['serialNumber']}@products.bang-olufsen.com"
                    : null,
            ]),
        );
    }
}
