<?php

namespace App\Models\Media\Concerns;

trait ReadsMetadata
{
    /** The first stored metadata value for a key, whatever its source. */
    public function metaValue(string $key): ?string
    {
        $value = $this->relationLoaded('metadata')
            ? $this->metadata->firstWhere('key', $key)?->value
            : $this->metadata()->where('key', $key)->value('value');

        return ($value !== null && $value !== '') ? $value : null;
    }

    /** @return array<mixed> a JSON metadata value, decoded; empty when absent */
    public function metaJson(string $key): array
    {
        $value = $this->metaValue($key);

        return $value ? (json_decode($value, true) ?: []) : [];
    }
}
