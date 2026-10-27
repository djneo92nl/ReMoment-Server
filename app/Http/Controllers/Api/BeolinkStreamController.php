<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Beolink\BeolinkException;
use App\Domain\Beolink\BeolinkStream;
use App\Domain\Storage\StreamUrl;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a B&O source plays, through the server: the beolink container joins the source's multiroom experience and
 * serves it as an Icecast stream that devices outside the B&O ecosystem play. See docs/architecture/beolink-stream.md.
 */
class BeolinkStreamController extends Controller
{
    use GuardsDeviceAccess;

    /** The stream's address, whether it is switched on, and what it is doing, for clients that want to play it themselves. */
    public function show(): JsonResponse
    {
        return response()->json(BeolinkStream::status());
    }

    /**
     * Play the stream on a device (capability `stream_url`). With `host_device_id` (a B&O device that plays) the
     * receiver first joins that device's experience, so the stream carries what it plays.
     */
    public function play(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['host_device_id' => ['nullable', 'integer', 'exists:devices,id']]);

        if (!BeolinkStream::enabled()) {
            return response()->json(['error' => 'unsupported', 'message' => 'The BeoLink stream is not enabled (BEOLINK_ENABLED).'], 422);
        }

        return $this->withDriver($device, PlayStreamUrlInterface::class, 'stream_url', function (PlayStreamUrlInterface $driver) use ($device, $data) {
            if (isset($data['host_device_id'])) {
                try {
                    BeolinkStream::listenTo(Device::findOrFail($data['host_device_id']));
                } catch (BeolinkException $e) {
                    return response()->json(['error' => 'beolink_unavailable', 'message' => $e->getMessage()], 502);
                }
            }

            $url = BeolinkStream::url();
            $logo = ($proxy = NowPlayingArtwork::logo('music')['proxy_320'] ?? null) ? StreamUrl::storage($proxy) : null;

            $driver->playStreamUrl($url, BeolinkStream::title(), $logo);
            BeolinkStream::addTarget($device->id);

            return ['status' => 'ok', 'url' => $url] + BeolinkStream::status();
        });
    }

    /** Stop the stream on a device; the receiver leaves its source when no device plays it any more. */
    public function stop(Device $device): JsonResponse
    {
        return $this->withDriver($device, MediaControlsInterface::class, 'media_controls', function (MediaControlsInterface $driver) use ($device) {
            $driver->stop();
            BeolinkStream::removeTarget($device->id);

            return ['status' => 'ok'] + BeolinkStream::status();
        }, reachable: false);
    }
}
