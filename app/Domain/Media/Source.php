<?php

namespace App\Domain\Media;

class Source
{
    public function __construct(
        public ?string $name = null,
        public ?string $category = null,
        public ?string $jid = null,
        public ?string $sourceType = null,
        public ?string $connector = null,
        /** True while the device itself outputs the source, false while only joined players hear it, null when unknown (a BeoLink Converter reports this). */
        public ?bool $playsLocally = null,
    ) {}

    /** The physical connection of a TV input ("HDMI 2", "DVB", "AV"), null for a digital source. */
    public function input(): ?string
    {
        return \App\Domain\Device\SourceInput::label($this->sourceType, $this->connector);
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'category' => $this->category,
            'sourceType' => $this->sourceType,
            'connector' => $this->connector,
            'input' => $this->input(),
            'jid' => $this->jid,
            'playsLocally' => $this->playsLocally,
        ], fn ($value) => $value !== null);
    }
}
