<?php

namespace App\Http\Controllers;

use App\Domain\Library\ApiItems;
use App\Models\Media\Track;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The stem mixer page (docs/frontend/stem-mixer.md): two decks of kept stems (docs/api/stems.md), mixed in the browser.
 * `?a=` and `?b=` are library track ids to load into the decks; the page itself reads everything else over the REST API.
 */
class MixerController extends Controller
{
    public function __invoke(Request $request): View
    {
        $request->validate(['a' => ['nullable', 'integer'], 'b' => ['nullable', 'integer']]);

        $tracks = [];
        foreach (['a', 'b'] as $side) {
            $track = $request->filled($side) ? Track::with(['artist', 'album'])->find($request->integer($side)) : null;
            $tracks[$side] = $track ? ApiItems::track($track) : null;
        }

        return view('mixer.index', ['tracks' => $tracks]);
    }
}
