<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Integrations\Common\PlaysDlnaLibrary;

trait LibraryPlayback
{
    use PlaysDlnaLibrary;

    protected function startDlna(string $url): void
    {
        $this->playDlnaTrack($url);
    }

    protected function enqueueDlna(string $url): void
    {
        $this->playDlnaTrack($url, instant: false);
    }
}
