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
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'category' => $this->category,
            'sourceType' => $this->sourceType,
            'connector' => $this->connector,
            'jid' => $this->jid,
        ], fn ($value) => $value !== null);
    }
}
