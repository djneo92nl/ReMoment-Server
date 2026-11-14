<?php

namespace App\Domain\Export;

/**
 * One track of an export plan. `reason`: highest_available, within_ceiling (copy); lossless, above_ceiling,
 * codec_not_accepted, unknown_bitrate, sample_rate, channels (transcode); no_own_file (skip).
 * `target` (transcodes only): codec (mp3|aac|opus), bitrate in kbit/s, sample_rate (null = keep), stereo (force 2 channels),
 * gain_db (loudness option: null = no gain, a number = the level change baked in).
 */
final readonly class ExportItem
{
    /** @param array{codec: ?string, bitrate: int, sample_rate: ?int, stereo: bool, gain_db: ?float}|null $target */
    public function __construct(
        public ?int $trackId,
        public ?int $fileId,
        public string $action,
        public string $reason,
        public ?array $target,
        public int $estimatedBytes,
        public ?string $sourceCodec = null,
        public ?int $sourceKbps = null,
    ) {}

    public function withGain(?float $gain): self
    {
        return new self(
            $this->trackId, $this->fileId, $this->action, $this->reason, $this->target === null ? null : ['gain_db' => $gain] + $this->target,
            $this->estimatedBytes, $this->sourceCodec, $this->sourceKbps,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
