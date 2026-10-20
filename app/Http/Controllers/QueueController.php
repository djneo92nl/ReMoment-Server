<?php

namespace App\Http\Controllers;

use App\Domain\Device\SpotifyRouting;
use App\Domain\Library\QueueTracks;
use App\Events\Device\QueueUpdated;
use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueuePlayNextInterface;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The library's "Add to queue" and "Play next" buttons (web); the REST twin is Api\QueueController. */
class QueueController extends Controller
{
    public function store(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(QueueTracks::TYPES))],
            'id' => ['required', 'integer'],
            'label' => ['nullable', 'string', 'max:200'],
            'next' => ['nullable', 'boolean'],
        ]);

        abort_unless(QueueTracks::exists($data['type'], (int) $data['id']), 404);

        $next = $request->boolean('next');
        $contract = $next ? QueuePlayNextInterface::class : QueueAddInterface::class;
        $what = isset($data['label']) ? "\"{$data['label']}\"" : 'that';
        $driver = SpotifyRouting::driverFor($device, $contract);

        if (!($driver instanceof $contract)) {
            return back()->with('error', "{$device->device_name} can't edit its queue that way.");
        }

        try {
            $tracks = QueueTracks::for($data['type'], (int) $data['id']);
            $next ? $driver->playNext($tracks) : $driver->addToQueue($tracks);
            QueueUpdated::dispatch($device->id);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', "Could not queue {$what} on {$device->device_name}: {$e->getMessage()}");
        }

        return back()->with('success', ($next ? 'Playing ' : 'Added ').$what.($next ? ' next' : ' to the queue').' on '.$device->device_name.'.');
    }
}
