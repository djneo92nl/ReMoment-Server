<?php

namespace App\Domain\Playback;

use App\Models\RadioStation;

/** What a device plays right now, in the form another device can pick up (see PlaybackCapture). */
final class PlaybackSnapshot
{
    public const LIBRARY = 'library';

    public const SPOTIFY = 'spotify';

    public const RADIO = 'radio';

    /**
     * @param  int[]  $trackIds  library tracks in play order, the current one first (library kind)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $position = 0,
        public readonly bool $paused = false,
        public readonly array $trackIds = [],
        public readonly ?RadioStation $station = null,
    ) {}
}
