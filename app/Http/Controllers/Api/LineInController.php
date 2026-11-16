<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\LineIn\LineIns;
use App\Domain\Storage\StreamUrl;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Line-in on a ReMoment Node as a stream source: the node's ffmpeg pushes MP3 to Icecast and a speaker with
 * `stream_url` plays that URL. See docs/architecture/line-in.md.
 */
class LineInController extends Controller
{
    use GuardsDeviceAccess;

    /** The line-ins (one per node with the role on), what they do now and who plays them. */
    public function index(): JsonResponse
    {
        $icecast = new \App\Domain\Radio\Relay\IcecastStatus;

        return response()->json(['data' => LineIns::nodes()->map(fn ($n) => LineIns::describe($n, $icecast))->all()]);
    }

    /** Play a line-in on a device (capability `stream_url`); `node_id` may be left out while there is only one. */
    public function play(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['node_id' => ['nullable', 'integer']]);
        $nodes = LineIns::nodes();

        if ($nodes->isEmpty()) {
            return response()->json(['error' => 'unsupported', 'message' => 'No node has the line-in role switched on.'], 422);
        }

        $node = isset($data['node_id']) ? $nodes->firstWhere('id', $data['node_id']) : ($nodes->count() === 1 ? $nodes->first() : null);

        if ($node === null) {
            return response()->json(['error' => 'invalid', 'message' => isset($data['node_id']) ? 'That node has no line-in.' : 'More than one line-in: give node_id.'], 422);
        }

        return $this->withDriver($device, PlayStreamUrlInterface::class, 'stream_url', function (PlayStreamUrlInterface $driver) use ($device, $node) {
            if (!LineIns::online($node)) {
                return response()->json(['error' => 'node_offline', 'message' => LineIns::label($node).' is offline.'], 503);
            }

            if (!LineIns::ensureLive($node)) {
                return response()->json(['error' => 'line_in_not_live', 'message' => 'The line-in did not start in time. Check the node, its input and Icecast.'], 502);
            }

            $logo = ($proxy = NowPlayingArtwork::logo('line_in')['proxy_320'] ?? null) ? StreamUrl::storage($proxy) : null;
            $driver->playStreamUrl(LineIns::url($node), LineIns::title($node), $logo);
            LineIns::addTarget($node, $device->id);

            return ['status' => 'ok', 'url' => LineIns::url($node), 'node_id' => $node->id, 'title' => LineIns::title($node)];
        });
    }
}
