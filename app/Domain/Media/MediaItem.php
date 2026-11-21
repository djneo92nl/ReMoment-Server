<?php

namespace App\Domain\Media;

/**
 * One title of a media server (a film, an episode, a series, an album or a track), the same shape for every server
 * type (docs/architecture/media-servers.md). `id` is namespaced by the server (`3:abc`), so items of several servers
 * can sit in one list and `POST /api/devices/{id}/media/play` needs nothing else. Times are seconds.
 */
final readonly class MediaItem
{
    public const KINDS = ['movie', 'episode', 'series', 'season', 'album', 'track'];

    public function __construct(
        public int $serverId,
        public string $itemId,
        public string $kind,
        public string $title,
        public ?string $subtitle = null,
        public ?int $year = null,
        /** An image on the media server (the web UI shows it through the artwork pipeline). */
        public ?string $image = null,
        /** 0-100 of what was watched. */
        public int $progress = 0,
        /** Where to resume, in seconds (may be fractional). */
        public float $resumePosition = 0,
        public ?int $duration = null,
        public ?string $library = null,
    ) {}

    public function id(): string
    {
        return self::key($this->serverId, $this->itemId);
    }

    public static function key(int $serverId, string $itemId): string
    {
        return "{$serverId}:{$itemId}";
    }

    /** @return array{0: ?int, 1: string} server id (null when not namespaced) and item id of `3:abc` or a bare `abc` */
    public static function parse(string $id): array
    {
        if (preg_match('/^(\d+):(.+)$/', $id, $m)) {
            return [(int) $m[1], $m[2]];
        }

        return [null, $id];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'server_id' => $this->serverId,
            'item_id' => $this->itemId,
            'kind' => $this->kind,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'year' => $this->year,
            'image' => $this->image,
            'progress' => $this->progress,
            'resume_position' => $this->resumePosition,
            'duration' => $this->duration,
            'library' => $this->library,
        ];
    }
}
