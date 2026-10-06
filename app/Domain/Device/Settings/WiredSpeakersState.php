<?php

namespace App\Domain\Device\Settings;

final readonly class WiredSpeakersState
{
    /** @param  WiredSpeaker[]  $speakers */
    public function __construct(public array $speakers) {}

    public function speaker(string $id): ?WiredSpeaker
    {
        foreach ($this->speakers as $speaker) {
            if ($speaker->id === $id) {
                return $speaker;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return ['speakers' => array_map(fn (WiredSpeaker $s) => $s->toArray(), $this->speakers)];
    }
}
