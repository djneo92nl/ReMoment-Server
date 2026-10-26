<?php

namespace App\Integrations\Philips;

use App\Domain\Device\AvailableSource;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VideoDeviceInterface;
use App\Integrations\Upnp\MusicPlayerDriver as UpnpDriver;
use Illuminate\Support\Facades\Cache;

/**
 * A paired Philips Android TV, a video device like the ASE VideoPlayerDriver: everything the UPnP driver does
 * (playback, queue, volume, seek) plus what Jointspace adds: standby, and the apps as sources (Netflix, YouTube,
 * the TV's own media player). The TV cannot be woken over the network and has no API to switch HDMI inputs
 * on current firmware, so those are not offered. See docs/architecture/philips-tv.md.
 */
class VideoPlayerDriver extends UpnpDriver implements PowerInterface, SourceActivationInterface, SourcesInterface, VideoDeviceInterface
{
    /** The app list barely changes: asked once in a while, not on every page. */
    private const APPS_TTL_SECONDS = 600;

    public function client(): JointspaceClient
    {
        return JointspaceClient::forDevice($this->device);
    }

    public function powerOn(): void
    {
        throw new UnsupportedOperationException('This TV cannot be switched on remotely.');
    }

    public function standby(): void
    {
        $this->client()->key('Standby');
    }

    public function getSources(): array
    {
        $current = $this->client()->currentPackage();

        return array_map(fn (array $app) => new AvailableSource(
            sourceId: $app['id'],
            friendlyName: $app['label'],
            sourceType: 'APP',
            category: 'video',
            inUse: $app['id'] === $current,
            borrowed: false,
            providerJid: null,
            providerName: null,
            active: $current === null ? null : $app['id'] === $current,
        ), $this->apps());
    }

    public function activateSource(string $sourceId): void
    {
        $app = collect($this->apps())->firstWhere('id', $sourceId);

        if ($app === null) {
            throw new \InvalidArgumentException("This TV has no app {$sourceId}.");
        }

        $this->client()->launch($app['intent']);
    }

    /** @return array<int, array{id: string, label: string, intent: array}> */
    public function apps(): array
    {
        return Cache::remember("philips:{$this->device->id}:apps", self::APPS_TTL_SECONDS, fn () => $this->client()->applications());
    }
}
