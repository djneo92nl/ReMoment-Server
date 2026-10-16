<?php

namespace App\Domain\Library;

use App\Models\Media\Playlist;
use Illuminate\Validation\ValidationException;

/**
 * How a playlist plays, decided by the server from the library the client browsed in:
 *
 * - `library` mode: the playlist's own library plays it (a Spotify playlist as a Spotify context);
 *   local and smart playlists play their tracks as before (DLNA first, then Spotify URIs).
 * - `local` mode: only the playlist's tracks that have a playable copy in the Local library
 *   (LOCAL_LIBRARY) are queued, the others skipped. Chosen when a playlist of another library is
 *   opened from the Local library.
 *
 * Never mixed. Without a requested library the mode is `library`.
 */
final class PlaylistMode
{
    public const LIBRARY = 'library';

    public const LOCAL = 'local';

    /** The id of the Local (own files) library. */
    public const LOCAL_LIBRARY = 'files';

    public function __construct(
        public readonly string $mode,
        /** The library the playback goes through; null for a local/smart playlist opened without a library (its tracks decide). */
        public readonly ?string $library,
    ) {}

    public function isLocal(): bool
    {
        return $this->mode === self::LOCAL;
    }

    /**
     * @param  string|null  $requested  the library id the client browsed in
     *
     * @throws ValidationException when the library makes no sense for the playlist (field `library`)
     */
    public static function resolve(Playlist $playlist, ?string $requested): self
    {
        $own = LibraryPlayback::playlistLibrary($playlist);

        if ($requested === null || $requested === '') {
            return new self(self::LIBRARY, $own);
        }

        if ($own === null) {
            if ($requested !== self::LOCAL_LIBRARY) {
                self::refuse($playlist, $requested);
            }

            return new self(self::LIBRARY, self::LOCAL_LIBRARY);
        }

        if ($requested === $own) {
            return new self(self::LIBRARY, $own);
        }

        if ($requested === self::LOCAL_LIBRARY) {
            return new self(self::LOCAL, self::LOCAL_LIBRARY);
        }

        self::refuse($playlist, $requested);
    }

    private static function refuse(Playlist $playlist, string $requested): never
    {
        $own = LibraryPlayback::playlistLibrary($playlist);
        $name = fn (string $id) => LibrarySources::LIBRARIES[$id]['name'] ?? $id;

        throw ValidationException::withMessages(['library' => [
            $own === null
                ? "\"{$playlist->name}\" is a local playlist; it can't be opened from the {$name($requested)} library."
                : "\"{$playlist->name}\" is a {$name($own)} playlist; it can be opened from {$name($own)} or from the Local library, not {$name($requested)}.",
        ]]);
    }
}
