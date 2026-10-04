<?php

namespace App\Domain\Device\Settings;

/** A wireless (WiSA) speaker found by, or paired with, the device. */
final readonly class WirelessSpeaker
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $state = null,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'state' => $this->state];
    }
}
