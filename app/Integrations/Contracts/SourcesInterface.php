<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\AvailableSource;

interface SourcesInterface
{
    /** @return AvailableSource[] */
    public function getSources(): array;
}
