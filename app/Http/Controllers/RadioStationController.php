<?php

namespace App\Http\Controllers;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Radio\RadioPlayLog;
use App\Domain\Radio\RadioSettings;
use App\Domain\Radio\RadioSuggestions;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Radio\Nts\NtsImporter;
use App\Models\Device;
use App\Models\RadioPlay;
use App\Models\RadioStation;
use Illuminate\Http\Request;

class RadioStationController extends Controller
{
    public function index()
    {
        $stations = RadioStation::query()
            ->with('meta')
            ->orderByRaw('favorited_at IS NULL')
            ->orderByDesc('favorited_at')
            ->orderBy('name')
            ->get();

        // NTS is opt-in: while it is off its imported stations stay out of the page (they are not deleted).
        if (!RadioSettings::ntsEnabled()) {
            $stations = $stations->reject(fn (RadioStation $s) => $s->getMeta('nts_id') !== null)->values();
        }

        LibraryItemArtwork::preload($stations->pluck('image_url'));
        $art = $stations->mapWithKeys(fn (RadioStation $s) => [$s->id => LibraryItemArtwork::forUrl($s->image_url)]);

        $ntsOn = RadioSettings::ntsEnabled();
        $isNts = fn (RadioStation $s) => $ntsOn && $s->getMeta('nts_id') !== null;
        $nts = $stations->filter($isNts)
            ->sortBy(fn (RadioStation $s) => [$s->getMeta('nts_channel') === null, $s->name])->values();
        $rest = $stations->reject($isNts);

        return view('radio.index', [
            'favourites' => $rest->filter(fn (RadioStation $s) => $s->favorited_at !== null)->values(),
            'others' => $rest->filter(fn (RadioStation $s) => $s->favorited_at === null)->values(),
            'nts' => $nts,
            'art' => $art,
            'heard' => app(RadioSuggestions::class)->yourArtistsOnTheRadio(6),
            'total' => $stations->count(),
            'ntsOn' => $ntsOn,
        ]);
    }

    /** Add NTS' live channels and mixtapes (or refresh their streams). */
    public function importNts(NtsImporter $importer)
    {
        abort_unless(RadioSettings::ntsEnabled(), 404);

        $result = $importer->import();

        return redirect()->route('radio.index')->with('success', "NTS: {$result['created']} added, {$result['updated']} refreshed.");
    }

    /** A station: what it plays now and the songs it played recently, each linked to the library when it has the track. */
    public function show(RadioStation $radio)
    {
        $radio->load('meta');
        $plays = $radio->radioPlays()->latest('started_at')->latest('id')->limit(50)->get();
        $tracks = $plays->mapWithKeys(fn (RadioPlay $play) => [$play->id => RadioPlayLog::libraryTrack($play)]);

        return view('radio.show', ['station' => $radio, 'plays' => $plays, 'tracks' => $tracks]);
    }

    public function create()
    {
        return view('radio.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'genre' => ['nullable', 'string', 'max:100'],
            'identifiers.url' => ['nullable', 'url', 'max:2048'],
        ]);

        $station = RadioStation::create(collect($validated)->only(['name', 'image_url', 'genre'])->all());

        foreach ($request->input('identifiers', []) as $platform => $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier !== '') {
                $station->setMeta($platform, $identifier);
            }
        }

        return redirect()->route('radio.index')->with('success', 'Radio station added.');
    }

    public function edit(RadioStation $radio)
    {
        $radio->load('meta');

        return view('radio.edit', compact('radio'));
    }

    public function update(Request $request, RadioStation $radio)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'genre' => ['nullable', 'string', 'max:100'],
            'identifiers.url' => ['nullable', 'url', 'max:2048'],
        ]);

        $radio->update(collect($validated)->only(['name', 'image_url', 'genre'])->all());

        foreach ($request->input('identifiers', []) as $platform => $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier !== '') {
                $radio->setMeta($platform, $identifier);
            } else {
                $radio->meta()->where('key', $platform)->delete();
                $radio->unsetRelation('meta');
            }
        }

        return redirect()->route('radio.index')->with('success', 'Radio station updated.');
    }

    public function favorite(RadioStation $radio)
    {
        $radio->toggleFavorite();

        return back();
    }

    public function destroy(RadioStation $radio)
    {
        $radio->delete();

        return redirect()->route('radio.index')->with('success', 'Radio station removed.');
    }

    public function play(RadioStation $radio, Device $device)
    {
        try {
            $driver = $device->driver;

            if (!($driver instanceof RadioControlInterface)) {
                return back()->with('error', "{$device->device_name} does not support radio playback.");
            }

            if (!$driver->canPlayRadioStation($radio)) {
                return back()->with('error', "No {$driver->radioPlatform()} identifier set for {$radio->name}.");
            }

            $driver->playRadioStation($radio);
            $radio->markPlayed();
        } catch (\Throwable $e) {
            return back()->with('error', "Could not reach {$device->device_name}: {$e->getMessage()}");
        }

        return back()->with('success', "Playing {$radio->name} on {$device->device_name}.");
    }

    private function radioCapableDevices(): \Illuminate\Support\Collection
    {
        return Device::all()->filter(
            fn (Device $device) => is_a($device->device_driver, RadioControlInterface::class, true)
        )->values();
    }
}
