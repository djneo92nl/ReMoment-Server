<?php

namespace App\Domain\Radio;

use App\Integrations\Radio\Nts\NtsProvider;
use App\Models\RadioStation;

/** The providers in order of preference; the first that supports a station and has a title wins. */
class NowPlayingProviders
{
    /** @var list<class-string<NowPlayingProvider>> */
    private const PROVIDERS = [NtsProvider::class, IcyProvider::class];

    public function supports(RadioStation $station): bool
    {
        return $this->first(fn (NowPlayingProvider $p) => $p->supports($station)) !== null;
    }

    /** @return array{0: string, 1: string}|null [source, title] */
    public function fetch(RadioStation $station): ?array
    {
        foreach (self::PROVIDERS as $class) {
            $provider = app($class);

            if (!$provider->supports($station)) {
                continue;
            }

            try {
                $title = $provider->title($station);
            } catch (\Throwable) {
                continue; // a provider that fails simply has no title for now
            }

            if ($title !== null) {
                return [$provider->source(), $title];
            }
        }

        return null;
    }

    private function first(callable $test): ?NowPlayingProvider
    {
        foreach (self::PROVIDERS as $class) {
            if ($test($provider = app($class))) {
                return $provider;
            }
        }

        return null;
    }
}
