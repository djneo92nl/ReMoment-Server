<?php

namespace App\Integrations\Upnp;

use App\Models\Device;

/**
 * What one renderer can do beyond the basics every UPnP renderer with AVTransport has. Recorded at
 * discovery from the SCPDs (`upnp_capabilities` meta) and applied by {@see \App\Domain\Device\HardwareFeatures},
 * so the API's capability list and its 422 check see the same set. Queue, shuffle and repeat are not here:
 * the server provides them for every renderer.
 */
final class UpnpCapabilities
{
    /** REST capability strings a renderer may lack. */
    public const OPTIONAL = ['seek', 'volume_control'];

    /** Not a REST capability: the renderer takes the next track ahead of time (`SetNextAVTransportURI`). */
    public const GAPLESS = 'gapless';

    /**
     * @param  string[]  $transportActions  AVTransport actions
     * @param  string[]  $renderingActions  RenderingControl actions (empty without that service)
     * @return string[]
     */
    public static function fromActions(array $transportActions, array $renderingActions): array
    {
        $capabilities = [];

        if (in_array('Seek', $transportActions, true)) {
            $capabilities[] = 'seek';
        }

        if (in_array('GetVolume', $renderingActions, true) && in_array('SetVolume', $renderingActions, true)) {
            $capabilities[] = 'volume_control';
        }

        if (in_array('SetNextAVTransportURI', $transportActions, true)) {
            $capabilities[] = self::GAPLESS;
        }

        return $capabilities;
    }

    /** @return string[] declared by discovery; a device added by hand (nothing declared) gets the optional ones */
    public static function for(Device $device): array
    {
        $json = $device->meta()->where('key', 'upnp_capabilities')->value('value');

        return $json ? (json_decode($json, true) ?: []) : self::OPTIONAL;
    }

    public static function has(Device $device, string $capability): bool
    {
        return in_array($capability, self::for($device), true);
    }
}
