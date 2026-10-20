<?php

namespace App\Integrations\Contracts;

interface ReportsStreamUrlInterface
{
    /** The URL the device has loaded right now (a stream or a track); null when it holds nothing. */
    public function loadedStreamUrl(): ?string;
}
