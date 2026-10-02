<?php

namespace App\Services\Dlna;

use App\Domain\Library\ReleaseDate;
use Illuminate\Support\Facades\Http;

class DlnaContentDirectoryClient
{
    private const CHUNK = 200;

    public function __construct(private string $controlUrl) {}

    /** @return array{items: array, containers: array} */
    public function browseChildren(string $objectId): array
    {
        $items = [];
        $containers = [];
        $start = 0;

        do {
            $body = $this->soapRequest($objectId, $start);
            if ($body === null) {
                break;
            }

            ['items' => $newItems, 'containers' => $newContainers, 'total' => $total] = $this->parseDidlLite($body);
            $items = array_merge($items, $newItems);
            $containers = array_merge($containers, $newContainers);
            $start += count($newItems) + count($newContainers);
        } while ($start < $total && (count($newItems) + count($newContainers)) > 0);

        return compact('items', 'containers');
    }

    private function soapRequest(string $objectId, int $startingIndex): ?string
    {
        $chunk = self::CHUNK;
        $soap = <<<XML
            <?xml version="1.0"?>
            <s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"
                        s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
              <s:Body>
                <u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">
                  <ObjectID>{$objectId}</ObjectID>
                  <BrowseFlag>BrowseDirectChildren</BrowseFlag>
                  <Filter>*</Filter>
                  <StartingIndex>{$startingIndex}</StartingIndex>
                  <RequestedCount>{$chunk}</RequestedCount>
                  <SortCriteria></SortCriteria>
                </u:Browse>
              </s:Body>
            </s:Envelope>
            XML;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'text/xml; charset="utf-8"',
                'SOAPAction' => '"urn:schemas-upnp-org:service:ContentDirectory:1#Browse"',
            ])->withBody($soap, 'text/xml')->post($this->controlUrl);

            if (!$response->successful()) {
                return null;
            }

            return $response->body();
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{items: array, containers: array, total: int} */
    private function parseDidlLite(string $soapResponse): array
    {
        $xml = simplexml_load_string($soapResponse, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) {
            return ['items' => [], 'containers' => [], 'total' => 0];
        }

        $body = $xml->children('http://schemas.xmlsoap.org/soap/envelope/')->Body;
        $browseResponse = $body->children('urn:schemas-upnp-org:service:ContentDirectory:1')->BrowseResponse;

        $total = (int) ($browseResponse->TotalMatches ?? 0);
        $resultXml = (string) ($browseResponse->Result ?? '');

        if (empty($resultXml)) {
            return ['items' => [], 'containers' => [], 'total' => $total];
        }

        $didl = simplexml_load_string($resultXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$didl) {
            return ['items' => [], 'containers' => [], 'total' => $total];
        }

        $didl->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $didl->registerXPathNamespace('upnp', 'urn:schemas-upnp-org:metadata-1-0/upnp/');

        $items = [];
        foreach ($didl->item ?? [] as $item) {
            $class = (string) $item->children('urn:schemas-upnp-org:metadata-1-0/upnp/')->class;
            if (!str_starts_with($class, 'object.item.audioItem')) {
                continue;
            }

            $dc = $item->children('http://purl.org/dc/elements/1.1/');
            $upnp = $item->children('urn:schemas-upnp-org:metadata-1-0/upnp/');

            $res = $item->res ?? null;
            $url = $res ? (string) $res : null;
            $resAttributes = $res ? $res->attributes() : null;
            $durationRaw = $resAttributes ? ((string) ($resAttributes->duration ?? '')) : '';

            $items[] = [
                'id' => (string) $item->attributes()->id,
                'title' => (string) $dc->title,
                'artist' => (string) $dc->creator,
                'album' => (string) $upnp->album,
                'track_number' => (int) ($upnp->originalTrackNumber ?? 0),
                'album_art' => (string) ($upnp->albumArtURI ?? ''),
                'url' => $url,
                'duration' => $this->parseDuration($durationRaw),
                'disc_number' => (int) ($upnp->originalDiscNumber ?? 0) ?: null,
                'genres' => $this->genres($upnp),
                'album_artist' => $this->albumArtist($upnp),
                'released_at' => ReleaseDate::parse((string) $dc->date),
                'audio' => $this->audioProperties($resAttributes),
            ];
        }

        $containers = [];
        foreach ($didl->container ?? [] as $container) {
            $containers[] = [
                'id' => (string) $container->attributes()->id,
                'title' => (string) $container->children('http://purl.org/dc/elements/1.1/')->title,
                'class' => (string) $container->children('urn:schemas-upnp-org:metadata-1-0/upnp/')->class,
            ];
        }

        return compact('items', 'containers', 'total');
    }

    /** @return list<string> distinct genres, in order */
    private function genres(\SimpleXMLElement $upnp): array
    {
        $genres = [];
        foreach ($upnp->genre as $genre) {
            $name = trim((string) $genre);
            if ($name !== '' && !in_array($name, $genres, true)) {
                $genres[] = $name;
            }
        }

        return $genres;
    }

    /** Servers such as MinimServer mark it as `<upnp:artist role="AlbumArtist">`. */
    private function albumArtist(\SimpleXMLElement $upnp): ?string
    {
        foreach ($upnp->artist as $artist) {
            if (strcasecmp((string) ($artist->attributes()->role ?? ''), 'AlbumArtist') === 0 && trim((string) $artist) !== '') {
                return trim((string) $artist);
            }
        }

        return null;
    }

    /**
     * The audio properties a server advertises on `<res>`; absent or zero ones are left out.
     * UPnP gives `bitrate` in bytes per second, so it is converted to kbps.
     *
     * @return array{bitrate?: int, sample_rate?: int, bit_depth?: int, channels?: int, file_size?: int, mime_type?: string}
     */
    private function audioProperties(?\SimpleXMLElement $attributes): array
    {
        if ($attributes === null) {
            return [];
        }

        $audio = array_filter([
            'bitrate' => (int) round(((int) $attributes->bitrate) * 8 / 1000),
            'sample_rate' => (int) $attributes->sampleFrequency,
            'bit_depth' => (int) $attributes->bitsPerSample,
            'channels' => (int) $attributes->nrAudioChannels,
            'file_size' => (int) $attributes->size,
        ]);

        // protocolInfo is "http-get:*:audio/flac:*"; the third field is the MIME type.
        $mime = strtolower(explode(':', (string) $attributes->protocolInfo)[2] ?? '');
        if (str_contains($mime, '/')) {
            $audio['mime_type'] = $mime;
        }

        return $audio;
    }

    private function parseDuration(string $raw): ?int
    {
        if (empty($raw)) {
            return null;
        }
        // Format: H:MM:SS.mmm
        [$time] = explode('.', $raw);
        $parts = explode(':', $time);
        if (count($parts) !== 3) {
            return null;
        }

        return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
    }
}
