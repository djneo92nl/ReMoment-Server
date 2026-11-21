<?php

namespace Tests\Support;

use App\Integrations\Contracts\SeekInterface;

/** A library-playback fake that can also seek, recording the seeks (resume position tests). */
class FakeSeekingLibraryDriver extends FakeLibraryPlaybackDriver implements SeekInterface
{
    /** @var list<int> */
    public static array $seeks = [];

    public static function reset(): void
    {
        parent::reset();
        self::$seeks = [];
    }

    public function seek(int $seconds): void
    {
        self::$seeks[] = $seconds;
    }
}
