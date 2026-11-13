<?php

namespace App\Http\Controllers;

use App\Domain\Device\SpotifyRouting;
use App\Domain\Tags\PlayTags;
use App\Models\Device;
use App\Support\SelectedDevice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The page a tag's QR code opens: it plays the tag's target on `?device=`, else the tag's own device, else the
 * player pinned in this browser; with none of those it asks which device.
 */
class TagPageController extends Controller
{
    public function __invoke(Request $request, string $uid, SelectedDevice $selected): View
    {
        $tag = PlayTags::find($uid);
        if (!$tag) {
            PlayTags::rememberUnknown($uid);

            return view('tags.play', ['tag' => null, 'uid' => PlayTags::normalise($uid)]);
        }

        $device = ($request->filled('device') ? Device::find((int) $request->query('device')) : null) ?? $tag->device ?? $selected->device();
        $name = PlayTags::targetName($tag->target_type, PlayTags::target($tag)) ?? $tag->label;

        if (!$device) {
            return view('tags.play', ['tag' => $tag, 'name' => $name, 'devices' => SpotifyRouting::visible(Device::orderBy('device_name')->get())]);
        }

        [$status, $body] = PlayTags::play($tag, $device);

        return view('tags.play', [
            'tag' => $tag,
            'name' => $name,
            'device' => $device,
            'error' => $status < 300 ? null : ($body['message'] ?? 'It could not be played.'),
        ]);
    }
}
