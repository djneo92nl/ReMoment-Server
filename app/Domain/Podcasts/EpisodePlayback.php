<?php

namespace App\Domain\Podcasts;

use App\Domain\Device\DeviceRouting;
use App\Domain\Device\HardwareFeatures;
use App\Domain\Device\State;
use App\Domain\Resume\ResumeOnPlay;
use App\Domain\Storage\StreamUrl;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Models\Device;
use App\Models\PodcastEpisode;

/**
 * Plays a podcast episode on a device (workstream 8.2): the publisher's enclosure URL goes to the same
 * `stream_url` path as radio and the line-in (`PlayStreamUrlInterface::playStreamUrl`), so the speaker fetches the audio
 * itself and the server carries no traffic. The bookmark of 8.3 is restored a moment later (`resume` in the request).
 */
final class EpisodePlayback
{
    /**
     * @return array{status: string, episode_id: int, title: string, resume_position: ?int}
     *
     * @throws UnsupportedOperationException when the device cannot play a stream URL
     * @throws DeviceUnreachable
     */
    public static function play(Device $device, PodcastEpisode $episode): array
    {
        if ($device->state === State::Unreachable) {
            throw new DeviceUnreachable('Device is not reachable.');
        }

        $driver = DeviceRouting::driverFor($device, PlayStreamUrlInterface::class);

        if (!$driver instanceof PlayStreamUrlInterface || !HardwareFeatures::allows($device, 'stream_url')) {
            throw new UnsupportedOperationException('This device does not support stream_url.');
        }

        $podcast = $episode->podcast;
        $cover = PodcastArtwork::resolve($episode->image_url ?: $podcast?->image_url)['proxy_320'] ?? null;

        $driver->playStreamUrl($episode->enclosure_url, $episode->title, $cover ? (StreamUrl::storage($cover) ?? $cover) : null);

        return [
            'status' => 'ok',
            'episode_id' => $episode->id,
            'title' => $episode->title,
            'resume_position' => ResumeOnPlay::afterEpisode($device, $episode->id, $episode->title, $episode->duration),
        ];
    }
}
