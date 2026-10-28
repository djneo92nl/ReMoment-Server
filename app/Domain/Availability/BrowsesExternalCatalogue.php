<?php

namespace App\Domain\Availability;

/**
 * An external catalogue the household can browse, play from and add to the library (Spotify now; Deezer, Tidal and media
 * servers later). Results are transient `ExternalItem`s with `in_library` resolved; a service that does not answer throws.
 */
interface BrowsesExternalCatalogue
{
    public function id(): string;

    /** Connected and usable (credentials present, account linked). */
    public function enabled(): bool;

    /**
     * @param  'artist'|'album'|'track'  $type
     * @return list<ExternalItem>
     */
    public function search(string $term, string $type, int $limit = 10): array;

    /** @return array{artist: ExternalItem, albums: list<ExternalItem>}|null null when the service does not know the artist */
    public function artist(string $id): ?array;

    /** @return array{album: ExternalItem, tracks: list<ExternalItem>}|null */
    public function album(string $id): ?array;

    /**
     * The account's playlists on the service (read live, never imported).
     *
     * @return list<ExternalItem> type `playlist`: title, `artist` = the owner, track count
     */
    public function playlists(): array;

    /**
     * What the account saved on the service: its saved albums, liked songs and recently played tracks (read live, newest first).
     * A service refuses what the account's grant does not cover; that throws and the caller says so.
     *
     * @param  'saved-albums'|'liked-tracks'|'recent'  $section
     * @return list<ExternalItem>
     */
    public function account(string $section, int $limit = 50): array;

    /**
     * One playlist and its tracks; `tracks` is null when the service does not share them (a followed playlist of someone else).
     *
     * @return array{playlist: ExternalItem, tracks: list<ExternalItem>|null}|null null when the service does not know it
     */
    public function playlist(string $id): ?array;

    /**
     * The artist's popular tracks on the service, most popular first (the service's own endpoint, or a stand-in).
     *
     * @return list<ExternalItem>
     */
    public function popularTracks(string $artistId, string $artistName, int $limit = 10): array;
}
