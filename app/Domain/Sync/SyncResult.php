<?php

namespace App\Domain\Sync;

/**
 * The outcome of `SyncPlanner::plan()`: the diff against what the target holds, the wanted entries with the facts
 * the executor needs (`wanted`: key => candidate), warnings and the totals the budget check and the estimate show.
 */
final readonly class SyncResult
{
    /**
     * @param  array<string, array<string, mixed>>  $wanted  key => candidate (track, file, delivery, spec ...)
     * @param  list<array<string, mixed>>  $warnings
     * @param  array{count: int, bytes: int}  $deferred  new units held back by the churn limit
     * @param  list<string>  $encoderMissing  formats ffmpeg cannot encode that the wanted set needs
     */
    public function __construct(
        public SyncDiff $diff,
        public array $wanted,
        public array $warnings,
        public bool $overBudget,
        public ?int $limitBytes,
        public int $candidates,
        public int $candidateBytes,
        public int $wantedBytes,
        public int $copied,
        public int $transcoded,
        public array $deferred,
        public array $encoderMissing,
        public bool $confirmRequired,
        public bool $removeFirst,
    ) {}

    public function wantedCount(): int
    {
        return count($this->wanted);
    }

    /** @return array<string, mixed> */
    public function totals(): array
    {
        return [
            'tracks' => $this->candidates, 'bytes' => $this->candidateBytes,
            'wanted' => $this->wantedCount(), 'wanted_bytes' => $this->wantedBytes,
            'copied' => $this->copied, 'transcoded' => $this->transcoded,
            'over_budget' => $this->overBudget, 'budget_bytes' => $this->limitBytes,
            'deferred' => $this->deferred,
        ];
    }
}
