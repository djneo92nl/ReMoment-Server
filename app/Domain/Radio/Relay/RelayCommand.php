<?php

namespace App\Domain\Radio\Relay;

/** The ffmpeg command line that turns a station into a live mount on Icecast. */
final class RelayCommand
{
    /** The mount of a station: a flat name, so no player has to handle a directory in the path. */
    public static function mount(int $stationId): string
    {
        return "relay-{$stationId}.mp3";
    }

    /**
     * MP3, 44.1 kHz stereo, CBR. The input options ask ffmpeg to follow a dropping stream (`-reconnect*`, which
     * only applies to HTTP inputs) and to give up on a silent one (`-rw_timeout`, microseconds), so the manager's
     * restart is the second line of defence, not the first.
     *
     * @return string[]
     */
    public static function build(string $ffmpeg, string $upstreamUrl, int $stationId): array
    {
        $icecast = parse_url((string) config('radio_relay.icecast.internal_url')) ?: [];
        $target = sprintf(
            'icecast://source:%s@%s:%d/%s',
            rawurlencode((string) config('radio_relay.icecast.source_password')),
            $icecast['host'] ?? '127.0.0.1',
            $icecast['port'] ?? 8000,
            self::mount($stationId),
        );

        return [
            $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '10', '-rw_timeout', '15000000',
            '-user_agent', (string) config('radio_relay.user_agent'),
            '-i', $upstreamUrl,
            '-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100',
            '-c:a', 'libmp3lame', '-b:a', ((int) config('radio_relay.bitrate')).'k',
            '-f', 'mp3', '-content_type', 'audio/mpeg',
            $target,
        ];
    }
}
