<?php

namespace Tests\Unit\Library;

use App\Domain\Library\SpotifyUri;
use PHPUnit\Framework\TestCase;

class SpotifyUriTest extends TestCase
{
    public function test_it_reads_and_builds_uris(): void
    {
        $this->assertSame('spotify:track:abc', SpotifyUri::track('abc'));
        $this->assertSame('abc', SpotifyUri::trackId('spotify:track:abc'));
        $this->assertSame('xyz', SpotifyUri::albumId('spotify:album:xyz'));
        $this->assertTrue(SpotifyUri::isPlaylist(SpotifyUri::playlist('p1')));
    }

    public function test_anything_else_is_not_a_uri(): void
    {
        $this->assertNull(SpotifyUri::trackId('spotify:track:'));
        $this->assertNull(SpotifyUri::trackId('1:42'));
        $this->assertNull(SpotifyUri::trackId(null));
        $this->assertFalse(SpotifyUri::isAlbum('spotify:track:abc'));
    }

    public function test_the_track_uri_comes_from_meta_only_for_spotify(): void
    {
        $meta = [['spotifyId' => 'spotify:track:abc']];

        $this->assertSame('spotify:track:abc', SpotifyUri::fromMeta($meta, 'spotify'));
        $this->assertSame('spotify:track:abc', SpotifyUri::fromMeta(['spotifyId' => 'spotify:track:abc'], 'spotify'));
        $this->assertNull(SpotifyUri::fromMeta($meta, 'dlna'));
        $this->assertNull(SpotifyUri::fromMeta([['spotifyId' => 'nope']], 'spotify'));
    }
}
