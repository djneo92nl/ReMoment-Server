<?php

namespace App\Domain\System;

use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\StemWeights;
use App\Domain\Analysis\Tools;
use App\Domain\Export\ExportFormats;
use App\Domain\HomeAssistant\HomeAssistant;
use App\Domain\LineIn\LineIns;
use App\Domain\MediaServers\MediaServers;
use App\Domain\Profile\Profile;
use App\Domain\Push\Vapid;
use App\Domain\Similar\Embeddings;
use Illuminate\Support\Facades\Cache;

/**
 * The `features` object of `GET /api/info` (workstream 8.7, docs/api/server-info.md): every optional capability a client
 * needs to branch on, built in this one place.
 *
 * Convention: a key is present (true) only when the server really has the capability enabled and the profile allows it;
 * a client treats a missing key as false. Three keys are always present because they are not yes/no: `profile`,
 * `analysis` (an empty Sound page needs an explanation) and nothing else. A feature that is not built yet (`podcasts`)
 * has no key until it is. Adding a key never bumps `api_version`.
 *
 * Every check is cheap (config, a setting, a row, or something cached for a minute): the endpoint is called by every
 * client on every connect and must never wait on a device or a sidecar that is down.
 */
final class ServerFeatures
{
    /** Seconds the stems weights state is remembered: it comes from a call to the stems service. */
    private const WEIGHTS_TTL = 30;

    private const WEIGHTS_KEY = 'server_features:stems_weights';

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $deepKinds = DeepAnalysis::availableKinds();

        $features = [
            // GET /api/alerts, the alert model (docs/architecture/alerts.md): every profile.
            'alerts' => true,
            // GET /api/scenes: household-wide step lists (docs/api/scenes.md).
            'scenes' => Profile::allows('scenes'),
            // The profile this server runs: control | standard | full (docs/architecture/profiles.md).
            'profile' => Profile::current(),
            // A deep-analysis service answers its health check (cached for a minute); deep_analysis_kinds says which kinds can run.
            'deep_analysis' => $deepKinds !== [],
            // Audio analysis of the own files is enabled and the profile allows it: the Sound data (TrackAnalysis, waveform) can exist.
            'analysis' => AudioAnalysis::enabled() && Profile::allows('analysis'),
        ];

        // Optional keys: present only when true.
        $optional = [
            'deep_analysis_kinds' => $deepKinds !== [] ? $deepKinds : null,
            'stems_weights' => self::stemsWeights(),
            // GET /api/library/discover: the shelves need the library (play history and favourites; docs/api/discover.md).
            'discover' => Profile::allows('library') ? true : null,
            // At least one own file has a sound embedding, so GET /api/library/tracks/{id}/similar can answer (docs/api/similar-tracks.md).
            'similar_tracks' => Profile::allows('analysis') && Embeddings::any() ? true : null,
            // Export from the library as zip (docs/api/export.md): library and own files in the profile, and ffmpeg can transcode.
            'export' => self::export() ? true : null,
            'export_formats' => self::export() ? ExportFormats::availableIds() : null,
            // Rip upload from the ripper app (admin files route): Full profile with the own-file storage.
            'rip' => Profile::allows('rip_upload') && Profile::allows('file_storage') ? true : null,
            // At least one enabled media server with complete credentials (docs/api/media-servers.md).
            'media_servers' => self::mediaServers() ? true : null,
            // The server-side sleep timer POST|GET|DELETE /api/devices/{id}/sleep (docs/api/scenes.md): every profile.
            'sleep_timer' => true,
            // Nodes (workstream 18): clients with kind `node` register and take jobs (docs/architecture/node-agent.md): every profile.
            'nodes' => true,
            // A node with the line-in role is switched on, so GET /api/line-ins lists something (docs/architecture/line-in.md).
            'line_in' => Profile::allows('line_in') && self::lineIn() ? true : null,
            // Sub devices: host and sub_devices in the device detail, the admin link routes (docs/api/sub-devices.md): every profile.
            'sub_devices' => true,
            // Background Web Push for warning and critical alerts: the server holds a VAPID key pair, so POST /api/push/subscribe works (docs/frontend/pwa.md).
            'push' => self::push() ? true : null,
            // Home Assistant MQTT discovery is switched on (docs/architecture/home-assistant.md).
            'ha_discovery' => Profile::allows('home_assistant') && HomeAssistant::enabled() ? true : null,
        ];

        return $features + array_filter($optional, fn ($value) => $value !== null);
    }

    /** Forget the remembered weights state (the download was just started: the next read asks the service). */
    public static function forgetStemsWeights(): void
    {
        Cache::forget(self::WEIGHTS_KEY);
    }

    private static function export(): bool
    {
        return Profile::allows('library') && Profile::allows('file_storage') && Tools::available(Tools::ffmpeg());
    }

    private static function mediaServers(): bool
    {
        try {
            return MediaServers::all()->contains(function ($server) {
                if (!$server->enabled) {
                    return false;
                }
                $driver = MediaServers::driver($server);

                return $driver !== null && $driver->configured();
            });
        } catch (\Throwable) {
            return false;   // no table yet (a fresh install mid-migration): never an error in /api/info
        }
    }

    private static function lineIn(): bool
    {
        try {
            return LineIns::nodes()->isNotEmpty();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function push(): bool
    {
        try {
            return Vapid::exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `missing | downloading | ready | failed`: where the opt-in Demucs weights download stands (docs/api/stems.md, "Weights").
     * Present only with a stems service in a profile that runs deep analysis; null when the service cannot be asked.
     * `ready` is also what puts `stems` and `stem_audio` into `deep_analysis_kinds`.
     */
    private static function stemsWeights(): ?string
    {
        if (!Profile::allows('deep_analysis') || !StemWeights::configured()) {
            return null;
        }

        return Cache::remember(self::WEIGHTS_KEY, self::WEIGHTS_TTL, function () {
            try {
                [$status, $body] = StemWeights::status();
            } catch (\Throwable) {
                return null;
            }
            $state = $body['state'] ?? null;

            return $status === 200 && in_array($state, [StemWeights::MISSING, StemWeights::DOWNLOADING, StemWeights::READY, StemWeights::FAILED], true) ? $state : null;
        });
    }
}
