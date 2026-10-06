<?php

namespace App\Domain\Library;

use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Which source leads the library. With DLNA connected it is the main library
 * and streaming services extend it; without, Spotify leads.
 *
 * What a page or API call shows is a scope: `local` (DLNA), `streaming`
 * (Spotify) or `all`. It defaults to the leading source and is applied
 * through LibrarySources' hidden-source filters, so nothing is deleted and
 * a track available from both sources shows in either scope.
 */
class LeadingSource
{
    public const SETTING = 'library_leading_source';

    /** Admin setting: pick automatically, force a source, or show everything (no leading source). */
    public const MODES = ['auto', 'dlna', 'spotify', 'all'];

    public const SCOPES = ['local', 'streaming', 'all'];

    private const SESSION_KEY = 'library_scope';

    public static function mode(): string
    {
        $mode = Setting::get(self::SETTING, 'auto');

        return in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /** `dlna`, `spotify` or `all`: the forced choice, else DLNA when it has imported tracks, else Spotify. */
    public static function leading(): string
    {
        $mode = self::mode();
        if ($mode !== 'auto') {
            return $mode;
        }

        return Track::where('source', 'dlna')->exists() ? 'dlna' : 'spotify';
    }

    public static function defaultScope(): string
    {
        return match (self::leading()) {
            'dlna' => 'local',
            'spotify' => 'streaming',
            default => 'all',
        };
    }

    /** A valid scope, the default for anything else. */
    public static function scope(mixed $requested): string
    {
        return is_string($requested) && in_array($requested, self::SCOPES, true)
            ? $requested
            : self::defaultScope();
    }

    /** Sources a scope hides, in LibrarySources' terms. */
    public static function hidden(string $scope): array
    {
        return match ($scope) {
            'local' => ['spotify'],
            'streaming' => ['dlna'],
            default => [],
        };
    }

    /** The scope of a web request: `?scope=` (remembered in the session), else the remembered one, else the default. */
    public static function forRequest(Request $request): string
    {
        $requested = $request->query('scope');
        if (is_string($requested) && in_array($requested, self::SCOPES, true)) {
            $request->session()->put(self::SESSION_KEY, $requested);

            return $requested;
        }

        return self::scope($request->session()->get(self::SESSION_KEY));
    }

    /** Label for a scope, matching the leading source. */
    public static function labels(): array
    {
        return ['local' => 'Local', 'streaming' => 'Streaming', 'all' => 'All'];
    }
}
