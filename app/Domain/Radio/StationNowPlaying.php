<?php

namespace App\Domain\Radio;

use App\Models\RadioStation;

/** What a station plays now, from the cache only (never a live fetch), for the API and the radio page. */
final readonly class StationNowPlaying
{
    public function __construct(
        public ?string $artist,
        public string $title,
        public string $source,
    ) {}

    public static function for(RadioStation $station): ?self
    {
        $raw = StreamTitles::get($station->id);

        if ($raw === null) {
            return null;
        }

        [$artist, $title] = IcyReader::split($raw);

        return new self($artist, $title, StreamTitles::source($station->id) ?? 'icy');
    }

    /** @return array{artist: ?string, title: string, source: string} */
    public function toArray(): array
    {
        return ['artist' => $this->artist, 'title' => $this->title, 'source' => $this->source];
    }
}
