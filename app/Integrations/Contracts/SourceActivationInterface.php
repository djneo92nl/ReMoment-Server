<?php

namespace App\Integrations\Contracts;

interface SourceActivationInterface
{
    public function activateSource(string $sourceId): void;
}
