<?php

namespace Tests\Feature;

use App\Models\DlnaServer;
use App\Models\Media\Track;
use App\Services\Dlna\DlnaContentDirectoryClient;
use App\Services\Dlna\DlnaLibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A Browse reply names its out-arguments plain (`<Result>`, as Jellyfin does) or qualified (`<u:Result>`). */
class DlnaBrowseReplyTest extends TestCase
{
    use RefreshDatabase;

    private const ITEM = '<item id="1" parentID="0"><dc:title>Closer</dc:title><dc:creator>Nine Inch Nails</dc:creator><upnp:album>The Downward Spiral</upnp:album>'
        .'<upnp:class>object.item.audioItem.musicTrack</upnp:class><res duration="0:04:05" protocolInfo="http-get:*:audio/flac:*">http://10.0.0.5/1.flac</res></item>';

    private function reply(string $prefix): string
    {
        $didl = '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">'.self::ITEM.'</DIDL-Lite>';

        return '<?xml version="1.0" encoding="utf-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><SOAP-ENV:Body>'
            .'<u:BrowseResponse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            ."<{$prefix}Result>".htmlspecialchars($didl)."</{$prefix}Result><{$prefix}NumberReturned>1</{$prefix}NumberReturned><{$prefix}TotalMatches>1</{$prefix}TotalMatches>"
            .'</u:BrowseResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    public static function replyStyles(): array
    {
        return ['plain, as Jellyfin sends it' => [''], 'qualified with the service prefix' => ['u:']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('replyStyles')]
    public function test_the_client_reads_the_items_of_either_reply_style(string $prefix): void
    {
        Http::fake(['*' => Http::response($this->reply($prefix))]);

        $result = (new DlnaContentDirectoryClient('http://10.0.0.5:8096/ctl'))->browseChildren('0');

        $this->assertCount(1, $result['items']);
        $this->assertSame('Closer', $result['items'][0]['title']);
        $this->assertSame('http://10.0.0.5/1.flac', $result['items'][0]['url']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('replyStyles')]
    public function test_a_scan_indexes_tracks_from_either_reply_style(string $prefix): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->reply($prefix))]);
        $server = DlnaServer::create(['friendly_name' => 'Jellyfin', 'ip' => '10.0.0.5', 'port' => 8096, 'control_url' => 'http://10.0.0.5:8096/ctl']);

        $count = app(DlnaLibraryScanner::class)->scanServer($server);

        $this->assertSame(1, $count);
        $this->assertSame('Closer', Track::firstOrFail()->name);
    }

    /** A server with films and music: Browse answers per object id, and every id asked for is recorded. */
    private function fakeServerWithFilmsAndMusic(): \ArrayObject
    {
        $asked = new \ArrayObject;
        $wrap = fn (string $inner, int $total) => '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><SOAP-ENV:Body>'
            .'<u:BrowseResponse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1"><Result>'
            .htmlspecialchars('<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">'.$inner.'</DIDL-Lite>')
            ."</Result><NumberReturned>{$total}</NumberReturned><TotalMatches>{$total}</TotalMatches></u:BrowseResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>";
        $folder = fn (string $id, string $title) => '<container id="'.$id.'" parentID="0"><dc:title>'.$title.'</dc:title><upnp:class>object.container.storageFolder</upnp:class></container>';

        Http::fake(function ($request) use ($asked, $wrap, $folder) {
            preg_match('#<ObjectID>(.*?)</ObjectID>#', $request->body(), $m);
            $asked[] = $m[1];

            return Http::response(match ($m[1]) {
                '0' => $wrap($folder('films', 'Films').$folder('music', 'Muziek'), 2),
                'music' => $wrap($folder('songs', 'Nummers'), 1),
                'songs' => $wrap(self::ITEM, 1),
                default => $wrap('', 0),
            });
        });

        return $asked;
    }

    public function test_a_scan_can_start_in_a_folder_and_skips_the_rest_of_the_server(): void
    {
        Queue::fake();
        $asked = $this->fakeServerWithFilmsAndMusic();
        $server = DlnaServer::create(['friendly_name' => 'Jellyfin', 'ip' => '10.0.0.5', 'port' => 8096, 'control_url' => 'http://10.0.0.5:8096/ctl']);

        $count = app(DlnaLibraryScanner::class)->scanServer($server, null, 'muziek');

        $this->assertSame(1, $count);
        $this->assertNotContains('films', $asked->getArrayCopy(), 'the film folder is never opened');
        $this->assertSame(['0', 'music', 'songs'], $asked->getArrayCopy());
    }

    public function test_a_folder_can_be_given_as_a_path_or_by_object_id(): void
    {
        Queue::fake();
        $this->fakeServerWithFilmsAndMusic();
        $server = DlnaServer::create(['friendly_name' => 'Jellyfin', 'ip' => '10.0.0.5', 'port' => 8096, 'control_url' => 'http://10.0.0.5:8096/ctl']);

        $this->assertSame(1, app(DlnaLibraryScanner::class)->scanServer($server, null, 'Muziek/Nummers'));
        $this->assertSame(1, app(DlnaLibraryScanner::class)->scanServer($server, null, 'music'));
    }

    public function test_an_unknown_folder_says_what_exists(): void
    {
        $this->fakeServerWithFilmsAndMusic();
        $server = DlnaServer::create(['friendly_name' => 'Jellyfin', 'ip' => '10.0.0.5', 'port' => 8096, 'control_url' => 'http://10.0.0.5:8096/ctl']);

        $this->expectExceptionMessage("No folder 'Jazz' here. Available: Films, Muziek.");

        app(DlnaLibraryScanner::class)->scanServer($server, null, 'Jazz');
    }

    public function test_without_a_folder_the_whole_server_is_crawled(): void
    {
        Queue::fake();
        $asked = $this->fakeServerWithFilmsAndMusic();
        $server = DlnaServer::create(['friendly_name' => 'Jellyfin', 'ip' => '10.0.0.5', 'port' => 8096, 'control_url' => 'http://10.0.0.5:8096/ctl']);

        app(DlnaLibraryScanner::class)->scanServer($server);

        $this->assertContains('films', $asked->getArrayCopy());
    }
}
