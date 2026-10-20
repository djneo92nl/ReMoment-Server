<?php

namespace App\Integrations\Contracts;

interface PlayStreamUrlInterface
{
    /** Play a live (endless) audio stream, e.g. an Icecast mount, replacing what plays now. */
    public function playStreamUrl(string $url, string $title, ?string $art = null): void;
}
