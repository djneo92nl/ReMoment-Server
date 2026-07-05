<?php

namespace App\Services\Dlna;

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

            if (! $response->successful()) {
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
        if (! $xml) {
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
        if (! $didl) {
            return ['items' => [], 'containers' => [], 'total' => $total];
        }

        $didl->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $didl->registerXPathNamespace('upnp', 'urn:schemas-upnp-org:metadata-1-0/upnp/');

        $items = [];
        foreach ($didl->item ?? [] as $item) {
            $class = (string) $item->children('urn:schemas-upnp-org:metadata-1-0/upnp/')->class;
            if (! str_starts_with($class, 'object.item.audioItem')) {
                continue;
            }

            $dc = $item->children('http://purl.org/dc/elements/1.1/');
            $upnp = $item->children('urn:schemas-upnp-org:metadata-1-0/upnp/');

            $res = $item->res ?? null;
            $url = $res ? (string) $res : null;
            $durationRaw = $res ? ((string) ($res->attributes()->duration ?? '')) : '';

            $items[] = [
                'id' => (string) $item->attributes()->id,
                'title' => (string) $dc->title,
                'artist' => (string) $dc->creator,
                'album' => (string) $upnp->album,
                'track_number' => (int) ($upnp->originalTrackNumber ?? 0),
                'album_art' => (string) ($upnp->albumArtURI ?? ''),
                'url' => $url,
                'duration' => $this->parseDuration($durationRaw),
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
