<?php

namespace App\Domain\Radio\Relay;

/** One running ffmpeg, as far as the manager cares. */
interface RelayProcess
{
    public function running(): bool;

    /** Ends it (TERM, then KILL after a few seconds). */
    public function stop(): void;

    /** The exit code once it has ended. */
    public function exitCode(): ?int;

    /** The last of what it printed on stderr, for the log. */
    public function tail(): string;
}
