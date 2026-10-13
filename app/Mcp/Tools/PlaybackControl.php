<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Control playback on a device: play, pause, stop, next, previous, seek (position in seconds), shuffle (value true/false), repeat (value off|all|one), like (value true/false, Spotify), power (value true = on, false = standby). Errors: unreachable, unsupported (missing capability), invalid, driver_error.')]
class PlaybackControl extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'action' => $schema->string()->enum(['play', 'pause', 'stop', 'next', 'previous', 'seek', 'shuffle', 'repeat', 'like', 'power'])->required(),
            'position' => $schema->integer()->description('seek: absolute offset in seconds')->min(0),
            'value' => $schema->string()->description('shuffle/like/power: true or false; repeat: off, all or one'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
            'action' => 'required|in:play,pause,stop,next,previous,seek,shuffle,repeat,like,power',
            'position' => 'nullable|integer|min:0',
            'value' => 'nullable|string',
        ]);

        $path = 'devices/'.$data['device_id'];
        $bool = filter_var($data['value'] ?? null, FILTER_VALIDATE_BOOLEAN);

        return match ($data['action']) {
            'seek' => $this->api('PUT', "$path/seek", ['position' => (int) ($data['position'] ?? 0)]),
            'shuffle' => $this->api('PUT', "$path/shuffle", ['shuffle' => $bool]),
            'repeat' => $this->api('PUT', "$path/repeat", ['repeat' => $data['value'] ?? '']),
            'like' => $this->api('PUT', "$path/like", ['liked' => $bool]),
            'power' => $this->api('PUT', "$path/power", ['on' => $bool]),
            default => $this->api('POST', "$path/{$data['action']}"),
        };
    }
}
