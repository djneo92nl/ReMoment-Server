<?php

namespace App\Domain\Availability;

/** The registry of config/availability.php: which provider browses which external catalogue. */
final class ExternalCatalogues
{
    public static function for(string $provider): ?BrowsesExternalCatalogue
    {
        $class = config('availability.browse.'.$provider);

        return is_string($class) ? app($class) : null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(config('availability.browse', []));
    }
}
