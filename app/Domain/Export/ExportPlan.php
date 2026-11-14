<?php

namespace App\Domain\Export;

/** The result of ExportPlanner::plan(): the items in order and the totals shown before an export starts. */
final readonly class ExportPlan
{
    /**
     * @param  list<ExportItem>  $items
     * @param  list<string>  $exceeds  the limits the estimate is over: `max_gb`, `dir_max_gb`
     * @param  int  $leveled  transcoded files that get a gain (0 dB counts: they were measured)
     * @param  int  $unleveled  transcoded files without data to level them (loudness option on)
     */
    public function __construct(
        public string $profile,
        public array $items,
        public int $tracks,
        public int $copied,
        public int $transcoded,
        public int $skipped,
        public int $estimatedBytes,
        public int $parts,
        public array $exceeds,
        public string $loudness = 'off',
        public int $leveled = 0,
        public int $unleveled = 0,
    ) {}

    public function exportable(): int
    {
        return $this->copied + $this->transcoded;
    }

    public function exceedsLimits(): bool
    {
        return $this->exceeds !== [];
    }

    /** @return array<string, mixed> */
    public function totals(): array
    {
        return [
            'profile' => $this->profile, 'tracks' => $this->tracks, 'exportable' => $this->exportable(), 'copied' => $this->copied,
            'transcoded' => $this->transcoded, 'skipped' => $this->skipped, 'estimated_bytes' => $this->estimatedBytes,
            'parts' => $this->parts, 'exceeds_limits' => $this->exceedsLimits(), 'exceeds' => $this->exceeds,
            'loudness' => ['mode' => $this->loudness, 'leveled' => $this->leveled, 'unleveled' => $this->unleveled],
        ];
    }
}
