<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\Settings\DeviceInfo;
use App\Integrations\BangOlufsen\Ase\Connectors\SourceControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Models\Device;

/**
 * A B&O BeoLink Converter NL/ML: the bridge between Network Link (IP) and a Master Link system
 * (here a BeoSound Ouverture). It speaks the ASE REST API on port 8080 and pushes the usual ASE
 * notifications (SOURCE, NOW_PLAYING_*, PROGRESS_INFORMATION), so the ASE listener is reused.
 *
 * Read-only on purpose: what the converter accepts for writes (source switching, digits, volume,
 * power) is relayed to the Master Link bus and mostly ignored there. Presets and sources are
 * controlled through a player that borrowed the converter, see ConverterDigitTransport and
 * docs/architecture/ase-api-map.md, "NetworkLink/MasterLink converter".
 */
class ConverterDriver implements DeviceInfoInterface, MusicPlayerDriverInterface, SourcesInterface
{
    use SourceControls;

    public HttpConnector $deviceApi;

    public function __construct(public Device $device)
    {
        $this->deviceApi = new HttpConnector($device->ip_address.':8080');
    }

    public function deviceApiClient(): HttpConnector
    {
        return $this->deviceApi;
    }

    public function getDeviceInfo(): DeviceInfo
    {
        $device = $this->deviceApi->getStrict('BeoDevice')['beoDevice'] ?? [];

        return new DeviceInfo(
            (string) ($device['productFriendlyName']['productFriendlyName'] ?? ''),
            $device['productId']['productType'] ?? null,
            $device['software']['version'] ?? null,
            $device['hardware']['mac'] ?? null,
            renamable: false,
        );
    }

    public function setDeviceName(string $name): void
    {
        throw new UnsupportedOperationException('The converter is not renamed from here.');
    }
}
