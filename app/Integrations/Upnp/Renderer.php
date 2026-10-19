<?php

namespace App\Integrations\Upnp;

use App\Integrations\Common\UpnpSoap;
use App\Models\Device;

/** The AVTransport / RenderingControl calls of one renderer; the driver and the listener both speak through it. */
class Renderer
{
    public function __construct(
        private readonly UpnpSoap $transport,
        private readonly ?UpnpSoap $rendering = null,
        private readonly int $volumeMax = 100,
    ) {}

    public static function forDevice(Device $device): self
    {
        $meta = fn (string $key) => $device->meta()->where('key', $key)->value('value');

        $transport = $meta('upnp_avtransport_url');

        // Added by hand: find the renderer at its IP now (it is stored, so this happens once).
        if (!$transport && RendererSetup::ensure($device)) {
            $transport = $meta('upnp_avtransport_url');
        }

        if (!$transport) {
            throw new \RuntimeException("Nothing at {$device->ip_address} answers as a UPnP MediaRenderer (no reply to a search on port 1900).");
        }

        $rendering = $meta('upnp_renderingcontrol_url');

        return new self(
            new UpnpSoap($transport, DeviceDescription::AV_TRANSPORT),
            $rendering ? new UpnpSoap($rendering, DeviceDescription::RENDERING_CONTROL) : null,
            max(1, (int) ($meta('upnp_volume_max') ?: 100)),
        );
    }

    /** PLAYING, PAUSED_PLAYBACK, STOPPED, TRANSITIONING, NO_MEDIA_PRESENT, ... */
    public function transportState(): string
    {
        return $this->transport->call('GetTransportInfo', ['InstanceID' => 0])['CurrentTransportState'] ?? 'STOPPED';
    }

    /** @return array{uri: string, metadata: string, duration: ?int, position: ?int} */
    public function positionInfo(): array
    {
        $info = $this->transport->call('GetPositionInfo', ['InstanceID' => 0]);

        return [
            'uri' => $info['TrackURI'] ?? '',
            'metadata' => $info['TrackMetaData'] ?? '',
            'duration' => DidlLite::seconds($info['TrackDuration'] ?? null),
            'position' => DidlLite::seconds($info['RelTime'] ?? null),
        ];
    }

    public function setUri(string $url, string $didl): void
    {
        $this->transport->call('SetAVTransportURI', ['InstanceID' => 0, 'CurrentURI' => $url, 'CurrentURIMetaData' => $didl]);
    }

    public function setNextUri(string $url, string $didl): void
    {
        $this->transport->call('SetNextAVTransportURI', ['InstanceID' => 0, 'NextURI' => $url, 'NextURIMetaData' => $didl]);
    }

    public function play(): void
    {
        $this->transport->call('Play', ['InstanceID' => 0, 'Speed' => 1]);
    }

    public function pause(): void
    {
        $this->transport->call('Pause', ['InstanceID' => 0]);
    }

    public function stop(): void
    {
        $this->transport->call('Stop', ['InstanceID' => 0]);
    }

    public function seek(int $seconds): void
    {
        $this->transport->call('Seek', ['InstanceID' => 0, 'Unit' => 'REL_TIME', 'Target' => DidlLite::time($seconds)]);
    }

    /** 0-100, whatever range the renderer counts in. */
    public function volume(): int
    {
        $level = (int) ($this->rendering()->call('GetVolume', ['InstanceID' => 0, 'Channel' => 'Master'])['CurrentVolume'] ?? 0);

        return (int) round($level * 100 / $this->volumeMax);
    }

    public function setVolume(int $percent): void
    {
        $this->rendering()->call('SetVolume', [
            'InstanceID' => 0,
            'Channel' => 'Master',
            'DesiredVolume' => (int) round(max(0, min(100, $percent)) * $this->volumeMax / 100),
        ]);
    }

    public function muted(): bool
    {
        return in_array($this->rendering()->call('GetMute', ['InstanceID' => 0, 'Channel' => 'Master'])['CurrentMute'] ?? '0', ['1', 'true'], true);
    }

    public function setMuted(bool $muted): void
    {
        $this->rendering()->call('SetMute', ['InstanceID' => 0, 'Channel' => 'Master', 'DesiredMute' => $muted ? 1 : 0]);
    }

    private function rendering(): UpnpSoap
    {
        return $this->rendering ?? throw new \App\Integrations\Common\UnsupportedOperationException('This renderer has no volume control.');
    }
}
