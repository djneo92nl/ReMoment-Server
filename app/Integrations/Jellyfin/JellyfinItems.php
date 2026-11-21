<?php

namespace App\Integrations\Jellyfin;

use App\Domain\Media\MediaItem;

/** Jellyfin items (resume, next up) as normalised `MediaItem`s. */
final class JellyfinItems
{
    public const TICKS_PER_SECOND = 10_000_000;

    private const KINDS = ['Movie' => 'movie', 'Episode' => 'episode', 'Series' => 'series', 'Season' => 'season', 'MusicAlbum' => 'album', 'Audio' => 'track'];

    /** @param  array<string, mixed>  $item an item of the Jellyfin API (must have an `Id`) */
    public static function item(array $item, int $serverId, string $baseUrl): MediaItem
    {
        $isEpisode = ($item['Type'] ?? null) === 'Episode';
        $subtitle = $isEpisode
            ? trim(sprintf('S%dE%d · %s', $item['ParentIndexNumber'] ?? 1, $item['IndexNumber'] ?? 1, $item['Name'] ?? ''), ' ·')
            : (isset($item['ProductionYear']) ? (string) $item['ProductionYear'] : null);
        $runtime = (int) ($item['RunTimeTicks'] ?? 0);
        $ticks = (int) ($item['UserData']['PlaybackPositionTicks'] ?? 0);
        $imageId = isset($item['ImageTags']['Primary']) ? $item['Id'] : ($item['SeriesId'] ?? $item['Id'] ?? null);

        return new MediaItem(
            serverId: $serverId,
            itemId: (string) $item['Id'],
            kind: self::KINDS[$item['Type'] ?? ''] ?? 'movie',
            title: (string) ($isEpisode ? ($item['SeriesName'] ?? $item['Name'] ?? '') : ($item['Name'] ?? '')),
            subtitle: $subtitle,
            year: isset($item['ProductionYear']) ? (int) $item['ProductionYear'] : null,
            image: $imageId === null ? null : "{$baseUrl}/Items/{$imageId}/Images/Primary?maxWidth=400",
            progress: $runtime > 0 ? min(100, (int) round($ticks / $runtime * 100)) : 0,
            resumePosition: $ticks / self::TICKS_PER_SECOND,
            duration: $runtime > 0 ? (int) round($runtime / self::TICKS_PER_SECOND) : null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<MediaItem>
     */
    public static function items(array $items, int $serverId, string $baseUrl): array
    {
        return array_map(fn ($item) => self::item($item, $serverId, $baseUrl), array_values(array_filter($items, fn ($i) => isset($i['Id']))));
    }

    /** The resume position of an item in Jellyfin's ticks (what the Jellyfin routes call `ticks`). */
    public static function ticks(float $seconds): int
    {
        return (int) round($seconds * self::TICKS_PER_SECOND);
    }
}
