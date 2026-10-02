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

    /** A numeric metadata value as an int, null when absent. */
    public function metaInt(string $key): ?int
    {
        $value = $this->metaValue($key);

        return $value !== null ? (int) $value : null;
    }

    /**
     * People credited (MusicBrainz for tracks, Discogs for albums) grouped by role in a fixed order.
     *
     * @return array<string, list<string>> role => names, an instrument or other detail in brackets
     */
    public function credits(): array
    {
        $order = ['composer', 'lyricist', 'writer', 'arranger', 'producer', 'co-producer', 'executive producer', 'engineer', 'recording', 'mix', 'mastering', 'programming', 'conductor', 'vocal', 'instrument', 'performer', 'remixer', 'DJ-mix'];
        $grouped = [];

        foreach ($this->metaJson('credits') as $credit) {
            $grouped[$credit['role']][] = $credit['name'].(isset($credit['detail']) ? " ({$credit['detail']})" : '');
        }

        $rank = fn (string $role) => ($i = array_search($role, $order, true)) === false ? count($order) : $i;
        uksort($grouped, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $grouped;
    }
}
