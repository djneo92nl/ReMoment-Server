<?php

namespace App\Integrations\Radio\Nts;

use App\Domain\Radio\NowPlayingProvider;
use App\Domain\Radio\RadioSettings;
use App\Models\RadioStation;

/** The show on air on NTS' live channels (identifier `nts_channel`: 1 or 2); a show title, not a track. Mixtapes have no live title. */
class NtsProvider implements NowPlayingProvider
{
    public function __construct(private readonly NtsClient $client) {}

    public function source(): string
    {
        return 'nts';
    }

    public function supports(RadioStation $station): bool
    {
        return RadioSettings::ntsEnabled() && $station->getMeta('nts_channel') !== null;
    }

    public function title(RadioStation $station): ?string
    {
        return $this->client->now($station->getMeta('nts_channel'))['title'] ?? null;
    }
}
