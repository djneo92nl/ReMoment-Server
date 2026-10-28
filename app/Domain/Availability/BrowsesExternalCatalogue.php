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
     * The artist's popular tracks on the service, most popular first (the service's own endpoint, or a stand-in).
     *
     * @return list<ExternalItem>
     */
    public function popularTracks(string $artistId, string $artistName, int $limit = 10): array;

    /**
     * Puts the item in the library: queued jobs that create the records through LibraryIdentity (source = this provider).
     * An artist adds the given album ids, else all its albums that the library lacks.
     *
     * @param  'artist'|'album'|'track'  $type
     * @param  list<string>|null  $albumIds
     * @return array{albums: int, tracks: int} how many imports were queued
     */
    public function add(string $type, string $id, ?array $albumIds = null): array;
}
