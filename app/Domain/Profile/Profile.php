<?php

namespace App\Domain\Profile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The active profile (control, standard, full) and what it runs. The single source of truth is `PROFILE` in .env,
 * read through config/profile.php; everything that depends on a profile (scheduler, workers, routes, navigation,
 * /api/info) asks this class. See docs/architecture/profiles.md.
 */
final class Profile
{
    public const CONTROL = 'control';

    public const STANDARD = 'standard';

    public const FULL = 'full';

    /** @var list<string> */
    public const ALL = [self::CONTROL, self::STANDARD, self::FULL];

    private static bool $warned = false;

    /** The active profile. An unknown PROFILE value falls back to the default, logged once per process. */
    public static function current(): string
    {
        $value = strtolower(trim((string) config('profile.current')));

        if (in_array($value, self::ALL, true)) {
            return $value;
        }

        if (!self::$warned) {
            self::$warned = true;
            Log::warning("Unknown PROFILE '{$value}', using ".config('profile.default').'.');
        }

        return (string) config('profile.default');
    }

    public static function is(string ...$profiles): bool
    {
        return in_array(self::current(), $profiles, true);
    }

    /** Does the active profile run this feature? An unknown feature is a bug in the caller, not a "no". */
    public static function allows(string $feature): bool
    {
        return self::allowsIn(self::current(), $feature);
    }

    public static function allowsIn(string $profile, string $feature): bool
    {
        $row = config("profile.features.{$feature}");
        if ($row === null) {
            throw new InvalidArgumentException("Unknown profile feature '{$feature}'.");
        }

        return in_array($profile, $row['profiles'], true);
    }

    /** @return list<string> the features a profile runs */
    public static function featuresOf(string $profile): array
    {
        return array_values(array_filter(array_keys(config('profile.features')), fn (string $f) => self::allowsIn($profile, $f)));
    }

    /** The lowest profile that runs the feature (control < standard < full). */
    public static function lowestFor(string $feature): string
    {
        foreach (self::ALL as $profile) {
            if (self::allowsIn($profile, $feature)) {
                return $profile;
            }
        }

        throw new InvalidArgumentException("No profile runs '{$feature}'.");
    }

    public static function label(?string $profile = null): string
    {
        return (string) config('profile.profiles.'.($profile ?? self::current()).'.label');
    }

    /** The database driver the profile is meant for: sqlite for Control, mariadb otherwise. */
    public static function database(?string $profile = null): string
    {
        return (string) config('profile.profiles.'.($profile ?? self::current()).'.database');
    }

    /** The feature a request URL belongs to, null when it is open in every profile. */
    public static function featureForRequest(Request $request): ?string
    {
        foreach (config('profile.routes') as $feature => $patterns) {
            if ($request->is(...$patterns)) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * The queue the play history is written on. Control runs one worker on its own queue so SQLite has a single
     * writer for it; Standard and Full keep the default queue, as before.
     */
    public static function historyQueue(): string
    {
        return self::is(self::CONTROL) ? (string) config('profile.history_queue') : 'default';
    }

    /** Supervised programs the active profile starts, keyed by program name (see config profile.workers). @return array<string, bool> */
    public static function workers(?string $profile = null): array
    {
        $profile ??= self::current();
        $result = [];

        foreach (config('profile.workers') as $program => $row) {
            $result[$program] = isset($row['features'])
                ? self::allowsIn($profile, $row['features'])
                : in_array($profile, $row['profiles'], true);
        }

        return $result;
    }

    /** Queues that get a heartbeat job and are watched: only those with a worker in this profile. */
    public static function runsQueue(string $queue): bool
    {
        $program = match ($queue) {
            'history' => 'history-queue',
            'storage' => 'storage-queue',
            'heavy', 'catalogue' => 'heavy-queue',
            'analysis' => 'analysis-queue',
            'stems' => 'stems-queue',
            default => null,
        };

        return $program === null || (self::workers()[$program] ?? true);
    }

    /** The explanation a client or a page shows for a feature the profile lacks. */
    public static function unavailableMessage(string $feature): string
    {
        $row = config("profile.features.{$feature}");
        $needs = ucfirst(self::lowestFor($feature));

        return "{$row['label']} is not part of the ".self::label().' profile; it needs the '.($needs === 'Full' ? 'Full' : "{$needs} or Full").' profile.';
    }
}
