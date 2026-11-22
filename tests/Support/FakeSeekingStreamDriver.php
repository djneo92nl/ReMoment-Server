<?php

namespace Tests\Support;

use App\Integrations\Contracts\SeekInterface;

/** A stream-URL driver that can also seek (podcast resume tests). */
class FakeSeekingStreamDriver extends FakeStreamDriver implements SeekInterface
{
    /** @var list<int> */
    public static array $seeks = [];

    public function seek(int $seconds): void
    {
        self::$seeks[] = $seconds;
    }
}
