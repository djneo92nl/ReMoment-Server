<?php

namespace App\Domain\Device;

use App\Domain\Control\SourceControls;
use App\Domain\Device\Cache\Battery;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Playback\PlaybackCapture;
use App\Domain\Playback\SyncGroups;
use App\Integrations\ReMoment\PlayerDevice;
use App\Integrations\ReMoment\PlayerDriver;
use App\Models\Device;

/**
 * The `capabilities` a device reports in the REST API (and on its web page):
 * its driver's (Capabilities::forDriver), plus Spotify's playback ones while
 * Spotify is routed to it (SpotifyRouting), plus `library_playback` when the
 * library can be played on it by DLNA or Spotify (LibraryPlayback), minus
 * capabilities its model has no hardware for (HardwareFeatures).
 * `sync_group` is reported for a device that can follow a leader (SyncGroups::canFollow).
 * `move_playback` is reported while something plays that can be moved to another device
 * (PlaybackCapture::movable). `source_controls` is reported while the active source has controls a
 * transport can deliver (SourceControls). `battery` is reported only once the
 * device's listener has seen a battery: a driver also covers mains-powered
 * models.
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

        // A player says at registration which optional controls it has (an ESP32 may lack seek).
        if (is_a($device->device_driver, PlayerDriver::class, true)) {
            $capabilities = array_diff($capabilities, array_diff(PlayerDevice::OPTIONAL, PlayerDevice::capabilities($device)));
        }

        if (SourceControls::available($device) !== null) {
            $capabilities[] = 'source_controls';
        }

        if (app(PlaybackCapture::class)->movable($device)) {
            $capabilities[] = 'move_playback';
        }

        if (SyncGroups::canFollow($device)) {
            $capabilities[] = 'sync_group';
        }

        if (Battery::get($device->id) === null) {
            $capabilities = array_diff($capabilities, ['battery']);
        }

        return Capabilities::sort($capabilities);
    }
}
