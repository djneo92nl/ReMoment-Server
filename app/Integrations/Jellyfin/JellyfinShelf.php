<?php

namespace App\Integrations\Jellyfin;

/** Jellyfin items (resume, next up) as the tiles of the TV page's shelf. */
final class JellyfinShelf
{
    private const TICKS_PER_SECOND = 10_000_000;

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, title: string, subtitle: ?string, image: ?string, progress: int, ticks: int}
     */
    public static function tile(array $item, string $baseUrl): array
    {
        $isEpisode = ($item['Type'] ?? null) === 'Episode';
        $subtitle = $isEpisode
            ? trim(sprintf('S%dE%d · %s', $item['ParentIndexNumber'] ?? 1, $item['IndexNumber'] ?? 1, $item['Name'] ?? ''), ' ·')
            : (isset($item['ProductionYear']) ? (string) $item['ProductionYear'] : null);
        $runtime = (int) ($item['RunTimeTicks'] ?? 0);
        $ticks = (int) ($item['UserData']['PlaybackPositionTicks'] ?? 0);
        $imageId = isset($item['ImageTags']['Primary']) ? $item['Id'] : ($item['SeriesId'] ?? $item['Id'] ?? null);

        return [
            'id' => (string) $item['Id'],
            'title' => (string) ($isEpisode ? ($item['SeriesName'] ?? $item['Name'] ?? '') : ($item['Name'] ?? '')),
            'subtitle' => $subtitle,
            'image' => $imageId === null ? null : "{$baseUrl}/Items/{$imageId}/Images/Primary?maxWidth=400",
            'progress' => $runtime > 0 ? min(100, (int) round($ticks / $runtime * 100)) : 0,
            'ticks' => $ticks,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{id: string, title: string, subtitle: ?string, image: ?string, progress: int, ticks: int}>
     */
    public static function tiles(array $items, string $baseUrl): array
    {
        return array_map(fn ($item) => self::tile($item, $baseUrl), array_values(array_filter($items, fn ($i) => isset($i['Id']))));
    }
}
