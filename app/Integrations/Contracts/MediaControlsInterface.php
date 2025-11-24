<?php

namespace App\Integrations\Contracts;

interface MediaControlsInterface
{
    public function play(): void;

    public function pause(): void;

    public function next(): void;

    public function previous(): void;

    public function stop(): void;
}
