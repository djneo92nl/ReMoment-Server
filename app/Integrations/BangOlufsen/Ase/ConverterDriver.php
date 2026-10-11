<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\Settings\DeviceInfo;
use App\Integrations\BangOlufsen\Ase\Connectors\SourceControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\SessionHostInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Models\Device;

/**
 * A B&O BeoLink Converter NL/ML: the bridge between Network Link (IP) and a Master Link system
 * (here a BeoSound Ouverture). It speaks the ASE REST API on port 8080 and pushes the usual ASE
 * notifications (SOURCE, NOW_PLAYING_*, PROGRESS_INFORMATION), so the ASE listener is reused.
 *
 * Mostly read-only: what the converter accepts for writes (source switching, digits, volume,
 * power) is relayed to the Master Link bus and mostly ignored there. The exception is hosting a
 * session: while a Master Link source plays, the converter takes listeners like any ASE host, so a
 * player can join it (and the player's own transport keys then step the tuner, see
 * ConverterDigitTransport). See docs/architecture/ase-api-map.md, "NetworkLink/MasterLink converter".
 */
class ConverterDriver implements DeviceInfoInterface, MusicPlayerDriverInterface, SessionHostInterface, SourcesInterface
{
    use SourceControls;

    public HttpConnector $deviceApi;

    public function __construct(public Device $device)
    {
        $this->deviceApi = app(HttpConnector::class, ['baseUrl' => $device->ip_address.':8080']);
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

    /**
     * Expands the converter's playing source to another device (by its multiroom jid). The converter has
     * a source to share only while its Master Link master plays one; verified: the listener list gets the
     * player and the player reports the converter's source as its primary.
     */
    public function addListener(string $peerId): void
    {
        $this->deviceApi->postStrict('BeoZone/Zone/ActiveSources/primaryExperience', [
            'listener' => ['jid' => $peerId],
        ]);
    }

    public function setDeviceName(string $name): void
    {
        throw new UnsupportedOperationException('The converter is not renamed from here.');
    }
}
