<?php

namespace App\Integrations\Jellyfin;

use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;

/** The Jellyfin session of one client (found by its address) as a `NowPlaying`. */
final class JellyfinNowPlaying
{
    private const TICKS_PER_SECOND = 10_000_000;

    /**
     * @param  array<int, array<string, mixed>>  $sessions  `JellyfinClient::sessions()`
     * @return NowPlaying|null null when no session of that client is playing something
     */
    public static function forClient(array $sessions, string $ip, string $baseUrl, string $appName = 'Jellyfin'): ?NowPlaying
    {
        foreach ($sessions as $session) {
            $item = $session['NowPlayingItem'] ?? null;
            $endpoint = preg_replace('/^::ffff:/', '', (string) ($session['RemoteEndPoint'] ?? ''));

            if ($item === null || $endpoint !== $ip) {
                continue;
            }

            return self::fromItem($item, $session['PlayState'] ?? [], $baseUrl, $appName);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $state
     */
    public static function fromItem(array $item, array $state, string $baseUrl, string $appName = 'Jellyfin'): NowPlaying
    {
        $isMusic = ($item['Type'] ?? null) === 'Audio';
        $subtitle = $isMusic
            ? ($item['Artists'][0] ?? $item['AlbumArtist'] ?? null)
            : ($item['SeriesName'] ?? (isset($item['ProductionYear']) ? (string) $item['ProductionYear'] : null));
        $name = isset($item['SeriesName'], $item['IndexNumber']) && !$isMusic
            ? sprintf('S%02dE%02d · %s', $item['ParentIndexNumber'] ?? 1, $item['IndexNumber'], $item['Name'] ?? '')
            : ($item['Name'] ?? null);
        $duration = isset($item['RunTimeTicks']) ? (int) round($item['RunTimeTicks'] / self::TICKS_PER_SECOND) : null;

        // Images are served without a login; an episode or track falls back to its series or album poster.
        $imageId = isset($item['ImageTags']['Primary']) ? $item['Id'] : ($item['SeriesId'] ?? $item['AlbumId'] ?? $item['Id'] ?? null);
        $images = $imageId === null ? [] : ["{$baseUrl}/Items/{$imageId}/Images/Primary?maxWidth=600"];

        return new NowPlaying(
            track: new TrackData(
                id: $item['Id'] ?? null,
                name: $name,
                source: 'jellyfin',
                artist: $subtitle === null ? null : new ArtistData(name: $subtitle),
                duration: $duration ?: null,
                images: $images,
            ),
            album: isset($item['Album']) ? new AlbumData(name: $item['Album'], images: $images) : null,
            state: ($state['IsPaused'] ?? false) ? 'paused' : 'playing',
            position: (int) round(($state['PositionTicks'] ?? 0) / self::TICKS_PER_SECOND),
            type: $isMusic ? 'music' : 'video',
            platform: 'media',
            source: new Source(name: $appName, category: 'video', sourceType: 'APP', connector: 'org.jellyfin.androidtv'),
        );
    }
}
