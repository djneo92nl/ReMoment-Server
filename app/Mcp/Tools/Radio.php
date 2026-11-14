<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Radio stations playable on a device (needs `radio_control`). Without station_id: list them (id, name, favorite). With station_id: play it, set/clear its household-wide favorite when `favorite` is given, or read what it plays now (without playing) when `now` is true. With `relays` true (no device needed): the radio relay, which converts stations a speaker cannot play itself (HTTPS, AAC, playlists) to MP3 on this server: whether it is on, whether its manager runs, and the relays running or waiting to restart (station, listeners, failures).')]
class Radio extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->description('Needed except with relays'),
            'relays' => $schema->boolean()->description('Show the radio relay status instead (GET /radio/relays)'),
            'station_id' => $schema->integer()->description('RadioStation id from the list'),
            'favorite' => $schema->boolean()->description('With station_id: set or clear the favorite instead of playing'),
            'now' => $schema->boolean()->description('With station_id: return what the station plays now instead of playing it'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required_without:relays|nullable|integer', 'relays' => 'nullable|boolean', 'station_id' => 'nullable|integer', 'favorite' => 'nullable|boolean', 'now' => 'nullable|boolean']);
        if ($data['relays'] ?? false) {
            return $this->api('GET', 'radio/relays');
        }

        $path = 'devices/'.$data['device_id'].'/radio';

        return match (true) {
            isset($data['station_id']) && ($data['now'] ?? false) => $this->api('GET', "radio/{$data['station_id']}/now"),
            isset($data['station_id']) && isset($data['favorite']) => $this->api('PUT', "radio/{$data['station_id']}/favorite", ['favorite' => (bool) $data['favorite']]),
            isset($data['station_id']) => $this->api('POST', "$path/{$data['station_id']}"),
            default => $this->api('GET', $path),
        };
    }
}
