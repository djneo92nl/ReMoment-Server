<?php

namespace App\Domain\Library;

use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Which source leads the library. With own files indexed they are the main
 * library and streaming libraries (DLNA, Spotify, ...) extend it; without, DLNA or Spotify leads.
 *
 * What a page or API call shows is a scope: `local` (own files), `streaming`
 * (DLNA, Spotify, any future streaming service) or `all`; the API also takes a library id (LibrarySources::LIBRARIES). It defaults to the leading source and is applied
 * through LibrarySources' hidden-source filters, so nothing is deleted and
 * a track available from both sources shows in either scope.
 */
class LeadingSource
{
    public const SETTING = 'library_leading_source';

    /** Admin setting: pick automatically, force a source, or show everything (no leading source). */
    public const MODES = ['auto', 'files', 'dlna', 'all'];

    public const SCOPES = ['local', 'streaming', 'all'];

    private const SESSION_KEY = 'library_scope';

    public static function mode(): string
    {
        $mode = Setting::get(self::SETTING, 'auto');

        return in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /** `files`, `dlna` or `all`: the forced choice, else the own files when indexed, else DLNA when it has tracks, else the own files. */
    public static function leading(): string
    {
        $mode = self::mode();
        if ($mode !== 'auto') {
            return $mode;
        }

        return match (true) {
            Track::where('source', 'files')->exists() => 'files',
            Track::where('source', 'dlna')->exists() => 'dlna',
            default => 'files',
        };
    }

    public static function defaultScope(): string
    {
        return match (self::leading()) {
            'files' => 'local',
            'dlna' => 'streaming',
            default => 'all',
        };
    }

    /** Every value the API accepts as `scope`: the legacy kinds plus each library id. @return list<string> */
    public static function apiScopes(): array
    {
        return [...self::SCOPES, ...LibrarySources::libraryIds()];
    }

    /** A valid scope (`local`, `streaming`, `all` or a library id), the default for anything else. */
    public static function scope(mixed $requested): string
    {
        return is_string($requested) && in_array($requested, self::apiScopes(), true)
            ? $requested
            : self::defaultScope();
    }

    /** Sources a scope hides, in LibrarySources' terms. */
    public static function hidden(string $scope): array
    {
        return match (true) {
            $scope === 'local' => LibrarySources::libraryIdsOfKind('streaming'),
            $scope === 'streaming' => LibrarySources::libraryIdsOfKind('local'),
            in_array($scope, LibrarySources::libraryIds(), true) => LibrarySources::allBut($scope),
            default => [],
        };
    }

    /** The scope of a web request: `?scope=` (remembered in the session), else the remembered one, else the default. */
    public static function forRequest(Request $request): string
    {
        $requested = $request->query('scope');
        if (is_string($requested) && in_array($requested, self::apiScopes(), true)) {
            $request->session()->put(self::SESSION_KEY, $requested);

            return $requested;
        }

        return self::scope($request->session()->get(self::SESSION_KEY));
    }

    /** The toggle group a scope belongs to: `local`, `streaming` or `all` (a library id belongs to its kind's group). */
    public static function group(string $scope): string
    {
        $kind = LibrarySources::LIBRARIES[$scope]['kind'] ?? null;

        return $kind ?? $scope;
    }

    /** Label for a scope, matching the leading source. */
    public static function labels(): array
    {
        return ['local' => 'Local', 'streaming' => 'Streaming', 'all' => 'All'];
    }
}
