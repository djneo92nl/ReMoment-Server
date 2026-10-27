<?php

namespace Tests\Unit;

use App\Domain\Storage\AudioTags;
use PHPUnit\Framework\TestCase;

/** The tags of a FLAC as the desktop ripper writes them (Vorbis comments, front cover embedded). */
class AudioTagsFlacTest extends TestCase
{
    public function test_it_reads_vorbis_album_artist_disc_and_cover_from_a_ripped_flac(): void
    {
        $tags = AudioTags::read(__DIR__.'/../Fixtures/ripper-tagged.flac');

        $this->assertSame('Terminus', $tags['title']);
        $this->assertSame('Dark Tranquillity feat. Someone', $tags['artist']);
        $this->assertSame('Dark Tranquillity', $tags['album_artist']); // not the track artist, or a guest splits the album
        $this->assertSame('Fiction', $tags['album']);
        $this->assertSame('2007', $tags['year']);
        $this->assertSame(3, $tags['track_number']);
        $this->assertSame(1, $tags['disc_number']);
        $this->assertSame('373f9354-9f96-405b-9437-e8c3ae18321c', $tags['mbid']);
        $this->assertSame('image/jpeg', $tags['picture']['mime']);
    }
}
