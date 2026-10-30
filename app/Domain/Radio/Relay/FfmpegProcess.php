<?php

namespace App\Domain\Radio\Relay;

use Symfony\Component\Process\Process;

/** A real ffmpeg child. Its output is read and dropped as it comes (only the tail is kept), so a stream that warns for hours costs no memory. */
final class FfmpegProcess implements RelayProcess
{
    private const TAIL_BYTES = 2000;

    private string $tail = '';

    private readonly Process $process;

    /** @param string[] $command */
    public function __construct(array $command)
    {
        $this->process = new Process($command);
        $this->process->setTimeout(null);
        $this->process->start(function (string $type, string $buffer): void {
            $this->tail = substr($this->tail.$buffer, -self::TAIL_BYTES);
            $this->process->clearOutput();
            $this->process->clearErrorOutput();
        });
    }

    public static function launch(array $command): self
    {
        return new self($command);
    }

    public function running(): bool
    {
        return $this->process->isRunning();
    }

    public function stop(): void
    {
        $this->process->stop(5);
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function tail(): string
    {
        return trim($this->tail);
    }
}
