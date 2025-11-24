<?php

namespace App\Integrations\Contracts;

interface VolumeControlInterface
{
    public function setVolume(int $volume): int;

    public function getVolume(): int;

    public function incrementVolume(): void;

    public function decrementVolume(): void;

    public function mute(): void;

    public function unmute(): void;
}
