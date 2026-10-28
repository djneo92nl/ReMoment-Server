<?php

namespace App\Domain\Availability;

/** An artist, album or track as an external service offers it (never stored): what search and browse return. */
final class ExternalItem
{
    public function __construct(
        public readonly string $provider,
        public readonly string $type, // artist | album | track
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $artist = null,
        public readonly ?string $year = null,
        public readonly ?int $trackCount = null,
        public readonly ?string $image = null,
        /** The library record (artist, album or track id) when the library already has it. */
        public readonly ?int $libraryId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist,
            'year' => $this->year,
            'track_count' => $this->trackCount,
            'image' => $this->image,
            'in_library' => $this->libraryId !== null,
            'library_id' => $this->libraryId,
        ];
    }
}
