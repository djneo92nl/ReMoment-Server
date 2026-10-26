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
        /** Physical connection ("HDMI 5", "DVB"); null for purely digital sources. */
        public ?string $input = null,
        /** Currently selected source; null when the device cannot tell. */
        public ?bool $active = null,
        /** The source has a signal (an HDMI box that is on); null when the device cannot tell. */
        public ?bool $signal = null,
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
            'input' => $this->input,
            'active' => $this->active,
            'signal' => $this->signal,
        ], fn ($v) => $v !== null);
    }
}
