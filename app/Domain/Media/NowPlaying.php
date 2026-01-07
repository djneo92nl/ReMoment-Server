<?php

namespace App\Domain\Media;

use Carbon\Carbon;

class NowPlaying
{
    public Carbon $endTime;

    public function __construct(
        public ?track $track = null,
        public ?Artist $artist = null,
        public ?Album $album = null,
        public ?string $state = null,
        public ?int $position = 0,
        public ?string $type = null,
        public ?string $platform = null,
        public ?Radio $radio = null
    ) {}

    public function toArray(): array
    {
        $endTime = '';

        if (isset($this->track->duration)) {

            $this->endTime = Carbon::now()->addSeconds($this->track->duration);
            $endTime = $this->endTime->toDateTimeString();

        }

        return array_filter([
            'track' => $this->track?->toArray(),
            'artist' => $this->artist?->toArray(),
            'album' => $this->album?->toArray(),
            'radio' => $this->radio?->toArray(),
            'state' => $this->state,
            'position' => $this->position,
            'platform' => $this->platform,
            'type' => $this->type,
            'endTime' => $endTime,
        ], fn ($value) => $value !== null);
    }
}
