<?php

namespace Remoment\MozartDriver\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use Djneo92nl\BeoMozart\Enums\RenderingStateValue;
use Djneo92nl\BeoMozart\WebSocket\EventClassifier;
use Djneo92nl\BeoMozart\WebSocket\NotificationClient;
use Illuminate\Support\Facades\Log;

class DeviceListener
{
    protected NotificationClient $client;

    protected ?\Closure $onError = null;

    protected ?string $lastSourceType = null;

    public function __construct(string $host, int $wsPort = 9000)
    {
        $this->client = new NotificationClient($host, $wsPort);
    }

    public function onError(\Closure $callback): void
    {
        $this->onError = $callback;
    }

    public function listen(string $deviceId): void
    {
        $cacheKey = "listener_running_{$deviceId}";
        $retryDelaySeconds = 1;
        $maxRetryDelaySeconds = 30;

        DeviceCache::updateState($deviceId, State::Unreachable);

        while (true) {
            cache()->put($cacheKey, true, now()->addSeconds(10));

            $hadError = false;

            try {
                DeviceCache::updateState($deviceId, State::Standby);

                $this->client->connectAndListen(function (string $eventType, mixed $eventData, array $raw) use ($deviceId, $cacheKey) {
                    cache()->put($cacheKey, true, now()->addSeconds(10));
                    $this->applyEvent($eventType, $eventData, $deviceId);
                });
            } catch (\Throwable $e) {
                $hadError = true;
                Log::error("Mozart listener [{$deviceId}] error: {$e->getMessage()}", [
                    'exception' => $e,
                ]);
                if ($this->onError) {
                    ($this->onError)($e);
                }
            } finally {
                cache()->forget($cacheKey);
                DeviceCache::updateState($deviceId, State::Unreachable);
            }

            $retryDelaySeconds = $hadError ? min($retryDelaySeconds * 2, $maxRetryDelaySeconds) : 1;

            sleep($retryDelaySeconds);
        }
    }

    protected function applyEvent(string $eventType, mixed $eventData, string $deviceId): void
    {
        $kind = $this->resolveEventKind($eventType, $eventData);

        switch ($kind) {
            case EventClassifier::PLAYBACK_METADATA:
                if (!is_array($eventData)) {
                    break;
                }

                event(new NowPlayingUpdated(
                    deviceId: $deviceId,
                    nowPlaying: $this->buildNowPlaying($eventData),
                    sourceType: $this->lastSourceType === 'netRadio' ? 'radio' : 'media',
                ));
                break;

            case EventClassifier::PLAYBACK_PROGRESS:
                if (!is_array($eventData)) {
                    break;
                }

                event(new ProgressUpdated(deviceId: $deviceId, progress: (int) ($eventData['progress'] ?? 0)));
                break;

            case EventClassifier::PLAYBACK_STATE:
                if (is_array($eventData) && isset($eventData['value'])) {
                    $this->applyRenderingState((string) $eventData['value'], $deviceId);
                }
                break;

            case EventClassifier::VOLUME:
                if (!is_array($eventData)) {
                    break;
                }

                $level = $eventData['level']['level'] ?? null;
                if ($level !== null) {
                    event(new VolumeUpdated(deviceId: $deviceId, volume: (int) $level));
                }
                break;

            case EventClassifier::SOURCE_CHANGE:
                if (is_array($eventData)) {
                    $this->lastSourceType = $eventData['type']['value'] ?? null;
                }
                break;

            default:
                Log::info("Mozart listener [{$deviceId}]: unrecognized WS event type [{$eventType}]");
        }
    }

    protected function applyRenderingState(string $value, string $deviceId): void
    {
        $state = RenderingStateValue::tryFrom($value);

        match ($state) {
            RenderingStateValue::Started, RenderingStateValue::Buffering => DeviceCache::updateState($deviceId, State::Playing),
            RenderingStateValue::Paused => DeviceCache::updateState($deviceId, State::Paused),
            RenderingStateValue::Idle, RenderingStateValue::Stopped, RenderingStateValue::Ended => $this->handlePlaybackEnded($deviceId),
            default => Log::warning("Mozart listener [{$deviceId}]: playback state [{$value}]"),
        };
    }

    protected function handlePlaybackEnded(string $deviceId): void
    {
        DeviceCache::updateState($deviceId, State::Standby);
        event(new NowPlayingEnded(deviceId: $deviceId));
    }

    /**
     * The Mozart spec never documents a concrete `eventType` value (typed as
     * plain `string` everywhere) — try a couple of plausible literal shapes
     * first, then fall back to EventClassifier's structural sniffing.
     */
    protected function resolveEventKind(string $eventType, mixed $eventData): string
    {
        $normalized = strtoupper((string) preg_replace('/[^a-zA-Z]/', '', str_replace('WebSocketEvent', '', $eventType)));

        $guesses = [
            'PLAYBACKMETADATA' => EventClassifier::PLAYBACK_METADATA,
            'PLAYBACKPROGRESS' => EventClassifier::PLAYBACK_PROGRESS,
            'PLAYBACKSTATE' => EventClassifier::PLAYBACK_STATE,
            'VOLUME' => EventClassifier::VOLUME,
            'SOURCECHANGE' => EventClassifier::SOURCE_CHANGE,
            'PLAYBACKSOURCE' => EventClassifier::SOURCE_CHANGE,
        ];

        return $guesses[$normalized] ?? EventClassifier::classify($eventType, $eventData);
    }

    public function buildNowPlaying(array $metadata): NowPlaying
    {
        $images = array_column($metadata['art'] ?? [], 'url');

        $artist = isset($metadata['artistName'])
            ? new ArtistData(name: $metadata['artistName'])
            : null;

        $track = new TrackData(
            id: isset($metadata['id']) ? (string) $metadata['id'] : null,
            name: $metadata['title'] ?? null,
            source: $metadata['source'] ?? null,
            artist: $artist,
            duration: isset($metadata['totalDurationSeconds']) ? (int) $metadata['totalDurationSeconds'] : null,
            images: $images,
            meta: array_filter([
                'genre' => $metadata['genre'] ?? null,
                'bitrate' => $metadata['bitrate'] ?? null,
                'samplerate' => $metadata['samplerate'] ?? null,
            ], fn ($v) => $v !== null),
        );

        $album = isset($metadata['albumName'])
            ? new AlbumData(name: $metadata['albumName'], artist: $artist)
            : null;

        $isRadio = $this->lastSourceType === 'netRadio';

        $radio = $isRadio
            ? new Radio(name: $metadata['title'] ?? $metadata['containerName'] ?? null, images: $images)
            : null;

        return new NowPlaying(
            track: $track,
            album: $album,
            type: 'music',
            platform: $isRadio ? 'radio' : 'media',
            radio: $radio,
        );
    }
}
