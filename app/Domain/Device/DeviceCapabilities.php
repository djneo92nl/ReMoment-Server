<?php

namespace App\Domain\Device;

use App\Domain\Library\LibraryPlayback;
use App\Models\Device;

/**
 * The `capabilities` a device reports in the REST API (and on its web page):
 * its driver's (Capabilities::forDriver), plus Spotify's playback ones while
 * Spotify is routed to it (SpotifyRouting), plus `library_playback` when the
 * library can be played on it by DLNA or Spotify (LibraryPlayback), minus
 * capabilities its model has no hardware for (HardwareFeatures).
 */
final class DeviceCapabilities
{
    /** @return string[] */
    public static function for(Device $device): array
    {
        $capabilities = SpotifyRouting::capabilities($device);

        if (!in_array('library_playback', $capabilities, true) && LibraryPlayback::availableFor($device)) {
            $capabilities[] = 'library_playback';
        }

        // A driver covers a whole platform; some features need hardware only some models have.
        $capabilities = array_filter($capabilities, fn (string $capability) => HardwareFeatures::allows($device, $capability));

        return Capabilities::sort($capabilities);
    }
}
