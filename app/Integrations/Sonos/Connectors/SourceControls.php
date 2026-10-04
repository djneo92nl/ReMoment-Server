<?php

namespace App\Integrations\Sonos\Connectors;

use App\Integrations\Sonos\SonosInputs;

/** Switching a Sonos speaker to its TV (optical/HDMI) or line-in input. */
trait SourceControls
{
    public function getSources(): array
    {
        $inputs = SonosInputs::forModel($this->device->device_product_type);
        if ($inputs === []) {
            return [];
        }

        $active = $this->activeInput();

        return array_map(fn (string $input) => SonosInputs::source($input, $input === $active), $inputs);
    }

    public function activateSource(string $sourceId): void
    {
        if (!in_array($sourceId, SonosInputs::forModel($this->device->device_product_type), true)) {
            throw new \InvalidArgumentException("This speaker has no input '{$sourceId}'.");
        }

        // A grouped follower has no input of its own to play: leave the group first.
        if ($this->deviceApiClient()->getIp() !== $this->device->ip_address) {
            $this->leaveSession();
            $this->deviceApi = null;
        }

        $controller = $this->deviceApiClient();
        $controller->soap('AVTransport', 'SetAVTransportURI', [
            'CurrentURI' => SonosInputs::uri($sourceId, $this->getMultiRoomId()),
            'CurrentURIMetaData' => '',
        ]);
        $controller->play();
    }

    /** The input the transport currently plays, or null when it plays something else. */
    public function activeInput(): ?string
    {
        $media = $this->deviceApiClient()->soap('AVTransport', 'GetMediaInfo')->getArray();

        return SonosInputs::idForUri($media['CurrentURI'] ?? null);
    }
}
