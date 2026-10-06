<?php

namespace App\Domain\Device\Settings;

/** One wired (power-link / line) speaker output of a device. */
final readonly class WiredSpeaker
{
    /**
     * @param  string  $position  left | right
     * @param  bool  $connected  the device senses a speaker on the output
     * @param  string[]  $types  what `type` may be set to
     * @param  string[]  $sounds  what `sound` may be set to (none, or a test noise to localise the speaker)
     */
    public function __construct(
        public string $id,
        public string $position,
        public bool $connected,
        public string $type,
        public array $types,
        public string $sound,
        public array $sounds,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'connected' => $this->connected,
            'type' => $this->type,
            'types' => $this->types,
            'sound' => $this->sound,
            'sounds' => $this->sounds,
        ];
    }
}
