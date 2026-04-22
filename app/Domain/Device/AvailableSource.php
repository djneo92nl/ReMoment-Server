<?php

namespace App\Domain\Device;

class AvailableSource
{
    public function __construct(
        public string $sourceId,
        public string $friendlyName,
        public string $sourceType,
        public string $category,
        public bool $inUse,
        public bool $borrowed,
        public ?string $providerJid,
        public ?string $providerName,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'source_id' => $this->sourceId,
            'friendly_name' => $this->friendlyName,
            'source_type' => $this->sourceType,
            'category' => $this->category,
            'in_use' => $this->inUse,
            'borrowed' => $this->borrowed,
            'provider_jid' => $this->providerJid,
            'provider_name' => $this->providerName,
        ], fn ($v) => $v !== null);
    }
}
