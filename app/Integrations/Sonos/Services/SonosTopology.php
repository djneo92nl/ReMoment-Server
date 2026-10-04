<?php

namespace App\Integrations\Sonos\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Unicast lookups against a speaker's UPnP endpoint (port 1400). No multicast needed. */
class SonosTopology
{
    /**
     * Every visible player in the household, asked of one speaker. Stereo-pair secondaries,
     * surround/sub satellites and bridges (Boost) are left out: they are not separate devices.
     *
     * @return array<int, array{uuid: string, ip: string, name: string}>|null null when the speaker did not answer
     */
    public function members(string $ip): ?array
    {
        try {
            $response = Http::timeout(3)
                ->withHeaders([
                    'SOAPACTION' => '"urn:schemas-upnp-org:service:ZoneGroupTopology:1#GetZoneGroupState"',
                    'Content-Type' => 'text/xml; charset="utf-8"',
                ])
                ->withBody(
                    '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><s:Body><u:GetZoneGroupState xmlns:u="urn:schemas-upnp-org:service:ZoneGroupTopology:1"/></s:Body></s:Envelope>',
                    'text/xml'
                )
                ->post("http://{$ip}:1400/ZoneGroupTopology/Control");
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? self::parseMembers($response->body()) : null;
    }

    /** @return array{name: string, model: string, uuid: string|null}|null */
    public function describe(string $ip): ?array
    {
        try {
            $body = Http::timeout(3)->get("http://{$ip}:1400/xml/device_description.xml")->body();
        } catch (Throwable) {
            return null;
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->device)) {
            return null;
        }

        $device = $xml->device;

        return [
            'name' => (string) ($device->roomName ?: $device->friendlyName),
            'model' => (string) ($device->modelName ?: 'Sonos Speaker'),
            'uuid' => preg_match('/^uuid:(.+)$/', (string) $device->UDN, $m) ? $m[1] : null,
        ];
    }

    /** @return array<int, array{uuid: string, ip: string, name: string}> */
    public static function parseMembers(string $soapBody): array
    {
        $envelope = @simplexml_load_string($soapBody);
        // Strict: an element with only namespaced children is falsy in SimpleXML.
        if ($envelope === false) {
            return [];
        }
        $state = (string) ($envelope->xpath('//ZoneGroupState')[0] ?? '');
        $groups = $state !== '' ? @simplexml_load_string($state) : false;
        if ($groups === false) {
            return [];
        }

        $members = [];
        foreach ($groups->xpath('//ZoneGroupMember') as $member) {
            if ((string) $member['Invisible'] === '1' || (string) $member['IsZoneBridge'] === '1') {
                continue;
            }
            $host = parse_url((string) $member['Location'], PHP_URL_HOST);
            if (!$host) {
                continue;
            }
            $members[] = [
                'uuid' => (string) $member['UUID'],
                'ip' => $host,
                'name' => (string) $member['ZoneName'],
            ];
        }

        return $members;
    }
}
