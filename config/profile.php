<?php

/*
|--------------------------------------------------------------------------
| Profiles (Control / Standard / Full)
|--------------------------------------------------------------------------
|
| The one gate table of workstream 1a (docs/architecture/profiles.md). `PROFILE` in .env picks the profile the
| containers start for; App\Domain\Profile\Profile reads it and answers `allows($feature)`. Scheduled tasks,
| supervised workers, API routes, web pages and the navigation all ask that one class, so a feature appears here
| once: which profiles run it, and which URLs belong to it.
|
| A test keeps docs/architecture/profiles.md in step with this file.
*/

return [

    /*
    | The active profile: control, standard or full. Anything else falls back to `default` (and is logged once).
    | Existing installs have no PROFILE and so stay on standard.
    */
    'current' => env('PROFILE', 'standard'),

    'default' => 'standard',

    /*
    | What each profile is for, what it needs, and its database. Shown by the setup wizard and /settings/profile.
    */
    'profiles' => [
        'control' => [
            'label' => 'Control',
            'tagline' => 'Wall panels and a few devices',
            'hardware' => 'Raspberry Pi 2 and up (Pi 2 is 32-bit armv7: unverified)',
            'database' => 'sqlite',
            'runs' => 'Device listeners, REST API, MQTT, live web UI, radio, play history, alerts, backup',
            'needs' => 'App, Redis and Mosquitto containers. The database is a SQLite file in a named volume; no MariaDB.',
        ],
        'standard' => [
            'label' => 'Standard',
            'tagline' => 'Control plus a music library',
            'hardware' => 'Raspberry Pi 3 (1 GB) and up',
            'database' => 'mariadb',
            'runs' => 'Adds library browsing and scan, metadata enrichment, artwork, DLNA, own-file indexing, catalogue sync',
            'needs' => 'App, Redis, Mosquitto and MariaDB containers.',
        ],
        'full' => [
            'label' => 'Full',
            'tagline' => 'Everything',
            'hardware' => '64-bit, 2 cores and 4 GB RAM at least; 6 GB and 4 cores for the ML sidecar',
            'database' => 'mariadb',
            'runs' => 'Adds audio analysis and its sidecars (Essentia, structure, stems), library health reports, rip upload',
            'needs' => 'Standard plus the analysis, structure and stems containers (compose profiles, optional) and optional Meilisearch.',
        ],
    ],

    /*
    | Capability table: feature => the profiles that run it. The first column of each row is the gate name used in
    | code (Profile::allows('library')).
    */
    'features' => [
        // Always on: the base of every profile. Listed so the table is complete and a test can see nothing is gated by accident.
        'devices' => ['label' => 'Devices, playback, sources, multiroom', 'profiles' => ['control', 'standard', 'full']],
        'history' => ['label' => 'Play history and insights', 'profiles' => ['control', 'standard', 'full']],
        'radio' => ['label' => 'Radio', 'profiles' => ['control', 'standard', 'full']],
        'alerts' => ['label' => 'Alerts, health and backup', 'profiles' => ['control', 'standard', 'full']],

        'library' => ['label' => 'Library browsing, playlists, library cache, artwork prerender, Spotify browse', 'profiles' => ['standard', 'full']],
        'enrichment' => ['label' => 'Metadata enrichment (MusicBrainz, Wikipedia, Last.fm, lyrics ...)', 'profiles' => ['standard', 'full']],
        'dlna' => ['label' => 'DLNA servers and scan', 'profiles' => ['standard', 'full']],
        'file_storage' => ['label' => 'Own files: storage locations, indexing, import, streaming to speakers', 'profiles' => ['standard', 'full']],
        'catalogue' => ['label' => 'Catalogue sync, wishlist, new releases, physical collection', 'profiles' => ['standard', 'full']],

        'analysis' => ['label' => 'Audio analysis (loudness, fingerprints, Essentia) and library health reports', 'profiles' => ['full']],
        'deep_analysis' => ['label' => 'Deep analysis of one track: sections (structure) and stems', 'profiles' => ['full']],
        'rip_upload' => ['label' => 'Rip upload from the ripper app', 'profiles' => ['full']],
        'search_engine' => ['label' => 'Meilisearch (optional, unused by the app today)', 'profiles' => ['full']],
    ],

    /*
    | URLs that belong to a feature, as Request::is() patterns without the leading slash. The first feature whose
    | pattern matches wins, so the narrow rows come before `library`. A URL no row matches is open in every profile.
    | `App\Http\Middleware\EnforceProfile` answers 422 not_available_in_profile (api/*) or redirects to
    | /not-in-profile (web) when the active profile lacks the feature.
    */
    'routes' => [
        'deep_analysis' => ['api/library/tracks/*/deep-analysis', 'api/library/tracks/*/deep-analysis/*'],
        'rip_upload' => ['api/admin/files'],
        'analysis' => ['settings/analysis', 'settings/analysis/*', 'settings/library-health', 'settings/library-health/*', 'api/library/health'],
        'catalogue' => [
            'api/library/wishlist', 'api/library/new-releases', 'api/library/wanted-tracks', 'api/library/wanted-tracks/*',
            'api/library/catalogue/*', 'api/library/physical', 'api/library/physical/*', 'api/library/artists/*/discography',
            'library/wishlist', 'library/collection',
        ],
        'file_storage' => [
            'settings/storage', 'settings/storage/*', 'stream/*',
            'api/admin/storage/*', 'api/admin/files/*', 'api/admin/tracks/*',
        ],
        'dlna' => ['settings/dlna', 'settings/dlna/*'],
        'library' => [
            'library', 'library/*', 'artists', 'artists/*', 'albums', 'albums/*', 'genres', 'genres/*', 'playlists', 'playlists/*',
            'tracks/*', 'external/*', 'spotify/tracks/*', 'settings/library', 'settings/library/*', 'settings/clients/artwork-export', 'settings/clients/artwork-export/*',
            'api/library/*', 'api/devices/*/library/*',
        ],
    ],

    /*
    | Supervised programs (docker/8.4/supervisord*.conf) and the feature each needs; a program without a row starts in
    | every profile. docker/8.4/profile-env.sh turns this into the START_* variables supervisor reads
    | (`autostart=%(ENV_START_...)s`); tests/Unit/ProfileSupervisorTest.php keeps the two in step.
    | `history-queue` is the single writer of the play history in Control (see Profile::historyQueue()).
    */
    'workers' => [
        'history-queue' => ['profiles' => ['control'], 'env' => 'START_HISTORY_QUEUE'],
        'storage-queue' => ['features' => 'file_storage', 'env' => 'START_STORAGE_QUEUE'],
        'heavy-queue' => ['features' => 'library', 'env' => 'START_HEAVY_QUEUE'],
        'analysis-queue' => ['features' => 'analysis', 'env' => 'START_ANALYSIS_QUEUE'],
        'stems-queue' => ['features' => 'deep_analysis', 'env' => 'START_STEMS_QUEUE'],
    ],

    /*
    | The queue the play history listener runs on in Control, served by exactly one worker so there is one writer.
    | Standard and Full keep the default queue.
    */
    'history_queue' => 'history',

    /*
    | Tables whose row counts `profile:upgrade` compares between SQLite and MariaDB after the copy.
    */
    'upgrade_verify_tables' => ['users', 'settings', 'devices', 'device_meta', 'device_sources', 'clients', 'client_device', 'plays', 'radio_stations', 'alerts', 'admin_tokens', 'multiroom_presets', 'tracks', 'albums', 'artists'],

    /*
    | The sidecar compose profiles each profile may enable (docker-compose.prod.yml). Ansible turns them on from
    | `analysis_enabled`, `structure_enabled` and `stems_enabled`, never for a profile that lacks the feature.
    */
    'compose_profiles' => [
        'control' => [],
        'standard' => ['db'],
        'full' => ['db', 'analysis', 'structure', 'stems', 'search'],
    ],
];
