<?php

namespace App\Integrations\Upnp;

use App\Models\Device;
use App\Models\DeviceMeta;
use App\Services\Discovery\SsdpClient;

/**
 * What the driver needs to know about a renderer (control URLs, volume range, optional capabilities), from its
 * descriptor. Discovery stores it with the device; a device added by hand has none yet, so the first use finds
 * the renderer at its stored descriptor URL, else at its IP by a unicast search, and stores it then.
 */
final class RendererSetup
{
    public const MEDIA_RENDERER = 'urn:schemas-upnp-org:device:MediaRenderer:1';

    public const AV_TRANSPORT = 'urn:schemas-upnp-org:service:AVTransport:1';

    private const SEARCH_TARGETS = [self::MEDIA_RENDERER, self::AV_TRANSPORT, 'upnp:rootdevice'];

    /** @return array<string, string> the device's `upnp_*` meta */
    public static function meta(DeviceDescription $description): array
    {
        $transportActions = $description->actions(DeviceDescription::AV_TRANSPORT);
        $renderingControl = $description->controlUrl(DeviceDescription::RENDERING_CONTROL);
        $renderingActions = $renderingControl ? $description->actions(DeviceDescription::RENDERING_CONTROL) : [];

        return array_filter([
            'upnp_uuid' => $description->udn,
            'upnp_location' => $description->location,
            'upnp_avtransport_url' => $description->controlUrl(DeviceDescription::AV_TRANSPORT),
            'upnp_renderingcontrol_url' => $renderingControl,
            'upnp_volume_max' => (string) ($description->volumeMax() ?? 100),
            'upnp_capabilities' => json_encode(UpnpCapabilities::fromActions($transportActions, $renderingActions)),
        ], fn ($value) => $value !== null);
    }

    /** Looks the renderer up at the device's IP and stores its meta. False when nothing there answers as a renderer. */
    public static function ensure(Device $device): bool
    {
        if (!$device->ip_address) {
            return false;
        }

        // A descriptor URL kept from earlier (or set by hand, for a renderer that does not answer searches) first.
        $locations = array_filter([$device->meta()->where('key', 'upnp_location')->value('value')]);

        if ($locations === []) {
            $ssdp = app(SsdpClient::class);

            foreach (self::SEARCH_TARGETS as $target) {
                $locations = array_column($ssdp->searchHost($device->ip_address, $target), 'location');

                if ($locations !== []) {
                    break;
                }
            }
        }

        foreach ($locations as $location) {
            $description = DeviceDescription::fetch($location);

            if ($description?->controlUrl(DeviceDescription::AV_TRANSPORT)) {
                foreach (self::meta($description) as $key => $value) {
                    DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => $key], ['value' => $value]);
                }

                return true;
            }
        }

        return false;
    }
}
