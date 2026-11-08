<?php

namespace App\Domain\Export;

/**
 * One track of an export plan. `reason`: highest_available, within_ceiling (copy); lossless, above_ceiling,
 * codec_not_accepted, unknown_bitrate, sample_rate, channels (transcode); no_own_file (skip).
 * `target` (transcodes only): codec, bitrate in kbit/s, sample_rate (null = keep), stereo (force 2 channels).
 */
final readonly class ExportItem
{
    /** @param array{codec: ?string, bitrate: int, sample_rate: ?int, stereo: bool}|null $target */
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
