<?php

namespace App\Domain\Availability;

use App\Domain\Library\LibrarySources;

/**
 * The availability providers (docs/architecture/availability-layer.md, "Providers"): where an album can be had and whether that
 * counts as owned. Ownership is a property of the provider (config/availability.php `providers`), not of
 * `LibrarySources::LIBRARIES[*]['kind']`: DLNA is a `streaming` library there (this server does not hold the files) but serves
 * the household's own music, so it is owned here. A provider id may carry an instance suffix (`jellyfin:3`, like `dlna:3`).
 */
final class Providers
{
    public const OWNED = 'owned';

    public const STREAMABLE = 'streamable';

    /** The provider id without an instance suffix. */
    public static function base(string $provider): string
    {
        return explode(':', $provider, 2)[0];
    }

    public static function known(string $provider): bool
    {
        return config('availability.providers.'.self::base($provider)) !== null;
    }

    /** `owned` or `streamable`; an unknown provider counts as streamable (never as a closed wish). */
    public static function ownership(string $provider): string
    {
        return config('availability.providers.'.self::base($provider).'.ownership') === self::OWNED ? self::OWNED : self::STREAMABLE;
    }

    public static function isOwned(string $provider): bool
    {
        return self::ownership($provider) === self::OWNED;
    }

    /** Whether any of these provider ids is an owned one. @param  iterable<string>  $providers */
    public static function anyOwned(iterable $providers): bool
    {
        foreach ($providers as $provider) {
            if (self::isOwned($provider)) {
                return true;
            }
        }

        return false;
    }

    /** The owned providers that are libraries (their tracks carry the source): what `owned` means for a track. @return list<string> */
    public static function ownedLibraries(): array
    {
        return array_values(array_filter(LibrarySources::libraryIds(), self::isOwned(...)));
    }

    /** The libraries that are not owned (streaming services). @return list<string> */
    public static function streamingLibraries(): array
    {
        return array_values(array_diff(LibrarySources::libraryIds(), self::ownedLibraries()));
    }

    /** @return list<string> every configured provider id */
    public static function ids(): array
    {
        return array_keys(config('availability.providers', []));
    }
}
