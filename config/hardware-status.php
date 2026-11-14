<?php

/*
 * How well each driver and model is verified, for the generated table in docs/user/hardware.md
 * (`php artisan hardware:table`). Hand-written on purpose: it records what someone saw on a real device.
 *
 * Statuses: `verified` (seen working on real hardware), `spec` (built against the vendor spec or the
 * protocol as documented, never run against a device), `fake` (only exercised by tests with fakes).
 * Never claim `verified` without a source in docs/ or the device-lab notes; when unsure use `spec`.
 *
 * `drivers` is keyed by driver class; `models` overrides one model (`brand => model => entry`).
 * Entry: status, date (when it was seen or recorded, YYYY-MM-DD), note (one line, plain language).
 */

use App\Integrations\BangOlufsen\Ase\ConverterDriver;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver as AseMusic;
use App\Integrations\BangOlufsen\Ase\VideoPlayerDriver as AseVideo;
use App\Integrations\Philips\Streamium\MusicPlayerDriver as Streamium;
use App\Integrations\Philips\VideoPlayerDriver as PhilipsTv;
use App\Integrations\ReMoment\PlayerDriver as RemomentPlayer;
use App\Integrations\Sonos\MusicPlayerDriver as Sonos;
use App\Integrations\Spotify\MusicPlayerDriver as Spotify;
use App\Integrations\Upnp\MusicPlayerDriver as Upnp;
use Remoment\MozartDriver\MusicPlayerDriver as Mozart;

return [
    'statuses' => [
        'verified' => 'verified on hardware',
        'spec' => 'built against the vendor spec, unverified',
        'fake' => 'fake-tested only',
    ],

    'drivers' => [
        AseMusic::class => ['status' => 'spec', 'date' => '2026-11-14', 'note' => 'ASE driver; only the models listed as verified were seen on a real device.'],
        AseVideo::class => ['status' => 'spec', 'date' => '2026-11-14', 'note' => 'ASE TV driver; see the model entry.'],
        ConverterDriver::class => ['status' => 'spec', 'date' => '2026-11-14', 'note' => 'Usually switched off in the lab; its sources were only seen through the V1.'],
        Mozart::class => ['status' => 'spec', 'date' => '2026-11-14', 'note' => 'Built from B&O\'s OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md.'],
        Sonos::class => ['status' => 'spec', 'date' => '2026-11-14', 'note' => 'Uses the duncan3dc/sonos library; no hardware run is recorded in the repository docs.'],
        Spotify::class => ['status' => 'spec', 'date' => '2026-11-14', 'note' => 'Virtual device on the Spotify Web API; no recorded run with a real account or speaker.'],
        RemomentPlayer::class => ['status' => 'fake', 'date' => '2026-11-14', 'note' => 'Virtual player of this server; covered by tests.'],
        Upnp::class => ['status' => 'verified', 'date' => '2026-10-22', 'note' => 'Verified on a Philips Streamium MCi900 only; other renderers are untested, a WiiM is built from documentation.'],
        PhilipsTv::class => ['status' => 'verified', 'date' => '2026-10-27', 'note' => 'Pairing, apps as sources and standby seen on real TVs; remote keys, other apps and waking from full sleep are not.'],
        Streamium::class => ['status' => 'verified', 'date' => '2026-10-22', 'note' => 'MCi900 web remote (power, radio presets) tested on the unit.'],
    ],

    'models' => [
        'Bang & Olufsen' => [
            'BeoSound Essence' => ['status' => 'verified', 'date' => '2026-10-04', 'note' => 'Essence Mk2 in the lab; mapped with ase:map.'],
            'Beoplay M3' => ['status' => 'verified', 'date' => '2026-10-04', 'note' => 'In the lab; mapped with ase:map.'],
            'Beoplay M5' => ['status' => 'verified', 'date' => '2026-10-04', 'note' => 'In the lab; mapped with ase:map.'],
            'BeoSound Moment' => ['status' => 'spec', 'date' => '2026-11-14', 'note' => 'Usually switched off in the lab; the WiSA endpoints are not confirmed.'],
            'Beoplay V1' => ['status' => 'verified', 'date' => '2026-10-04', 'note' => 'Sources and waking by sending a source seen; remote keys are not confirmed.'],
        ],
    ],
];
