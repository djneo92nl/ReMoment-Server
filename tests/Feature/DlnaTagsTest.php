<?php

namespace Tests\Feature;

use App\Models\DlnaServer;
use App\Models\Media\Album;
use App\Models\Media\Track;
use App\Services\Dlna\DlnaLibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Tags and audio properties a DLNA server reports are kept as track metadata. */
class DlnaTagsTest extends TestCase
{
    use RefreshDatabase;

    private function scan(string $item): DlnaServer
    {
        Queue::fake();

        $didl = '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">'
            .$item.'</DIDL-Lite>';
        $soap = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><s:Body>'
            .'<u:BrowseResponse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            .'<u:Result>'.htmlspecialchars($didl).'</u:Result><u:NumberReturned>1</u:NumberReturned><u:TotalMatches>1</u:TotalMatches>'
            .'</u:BrowseResponse></s:Body></s:Envelope>';
        Http::fake(['*' => Http::response($soap)]);

        $server = DlnaServer::create(['friendly_name' => 'NAS', 'ip' => '10.0.0.5', 'port' => 8200, 'control_url' => 'http://10.0.0.5:8200/ctl']);
        app(DlnaLibraryScanner::class)->scanServer($server);

        return $server;
    }

    private const FULL_ITEM = '<item id="1" parentID="0"><dc:title>Yellow</dc:title><dc:creator>Coldplay</dc:creator><upnp:album>Parachutes</upnp:album>'
        .'<upnp:class>object.item.audioItem.musicTrack</upnp:class><upnp:genre>Rock</upnp:genre><upnp:genre>Alternative</upnp:genre><upnp:genre>Rock</upnp:genre>'
        .'<upnp:originalTrackNumber>5</upnp:originalTrackNumber><upnp:originalDiscNumber>2</upnp:originalDiscNumber>'
        .'<upnp:artist role="AlbumArtist">Coldplay &amp; Friends</upnp:artist><dc:date>2000-07-10</dc:date>'
        .'<res duration="0:04:29.000" bitrate="176400" sampleFrequency="44100" bitsPerSample="16" nrAudioChannels="2" size="47500000" protocolInfo="http-get:*:audio/flac:*">http://10.0.0.5/1.flac</res></item>';

    public function test_tags_and_audio_properties_are_stored_on_the_track(): void
    {
        $server = $this->scan(self::FULL_ITEM);

        $meta = Track::firstOrFail()->metadata()->where('source', 'dlna:'.$server->id)->pluck('value', 'key');

        $this->assertSame('["Rock","Alternative"]', $meta['genres']);
        $this->assertSame('5', $meta['track_number']);
        $this->assertSame('2', $meta['disc_number']);
        $this->assertSame('Coldplay & Friends', $meta['album_artist']);
        $this->assertSame('1411', $meta['bitrate'], 'bytes per second to kbps');
        $this->assertSame('44100', $meta['sample_rate']);
        $this->assertSame('16', $meta['bit_depth']);
        $this->assertSame('2', $meta['channels']);
        $this->assertSame('47500000', $meta['file_size']);
        $this->assertSame('audio/flac', $meta['mime_type']);
        $this->assertSame('2000-07-10', Album::firstOrFail()->released_at->toDateString());
    }

    public function test_a_year_only_date_and_missing_tags_are_handled(): void
    {
        $server = $this->scan('<item id="1" parentID="0"><dc:title>Yellow</dc:title><dc:creator>Coldplay</dc:creator><upnp:album>Parachutes</upnp:album>'
            .'<upnp:class>object.item.audioItem.musicTrack</upnp:class><dc:date>2000</dc:date><res>http://10.0.0.5/1.mp3</res></item>');

        $this->assertSame('2000-01-01', Album::firstOrFail()->released_at->toDateString());
        $this->assertSame(
            ['dlna_url'],
            Track::firstOrFail()->metadata()->where('source', 'dlna:'.$server->id)->pluck('key')->all(),
            'nothing is stored for tags the server did not send',
        );
    }

    public function test_rescan_does_not_rewrite_unchanged_tags(): void
    {
        $server = $this->scan(self::FULL_ITEM);
        $before = DB::table('metadata')->orderBy('id')->pluck('updated_at', 'id');

        $this->travel(1)->minutes();
        app(DlnaLibraryScanner::class)->scanServer($server);

        $tagIds = DB::table('metadata')->where('key', '!=', 'dlna_url')->pluck('id');
        foreach ($tagIds as $id) {
            $this->assertEquals($before[$id], DB::table('metadata')->where('id', $id)->value('updated_at'));
        }
        $this->assertSame($before->count(), DB::table('metadata')->count());
    }
}
