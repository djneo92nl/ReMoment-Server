<?php

namespace App\Domain\Device\Settings;

final readonly class WirelessSpeakersState
{
    /** @param  WirelessSpeaker[]  $speakers */
    public function __construct(
        public string $scan,
        public bool $scanning,
        public array $speakers,
    ) {}

    public function toArray(): array
    {
        return [
            'scan' => $this->scan,
            'scanning' => $this->scanning,
            'speakers' => array_map(fn (WirelessSpeaker $s) => $s->toArray(), $this->speakers),
        ];
    }
}
