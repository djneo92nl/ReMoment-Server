<?php

namespace App\Integrations\Wiim;

use App\Models\Device;
use Illuminate\Support\Facades\Http;

/**
 * The LinkPlay HTTP API of a WiiM ("HTTP API for WiiM Products" 1.2): `GET https://{ip}/httpapi.asp?command=...`, no login,
 * most answers JSON. The device certificate is self-signed in practice (the document does not say), so verification is
 * skipped for this host only. Tolerant on purpose: keys are trimmed (the document's own examples have `" status"` and
 * `"albumArtURI "`) and numbers arrive as strings. See docs/architecture/wiim.md.
 */
class WiimClient
{
    /** Loop modes reported by getPlayerStatus: code => [shuffle, repeat]. */
    public const LOOP_STATUS = [0 => [false, 'all'], 1 => [false, 'one'], 2 => [true, 'all'], 3 => [true, 'off'], 4 => [false, 'off']];

    /** Loop modes to set with `loopmode:n` (the codes differ from the reported ones). 3 is not in the document's list of settable codes. */
    public const LOOP_SET = ['off' => 0, 'one' => 1, 'all' => -1, 'shuffle_all' => 2, 'shuffle_off' => 3];

    /** `mode` of getPlayerStatus => [name, category, sourceType]. */
    public const MODES = [
        1 => ['AirPlay', 'music', 'AIRPLAY'],
        2 => ['DLNA', 'music', 'DLNA'],
        10 => ['WiiM', 'music', 'MUSIC'],
        11 => ['USB disk', 'music', 'MUSIC'],
        16 => ['TF card', 'music', 'MUSIC'],
        31 => ['Spotify Connect', 'music', 'SPOTIFY'],
        32 => ['TIDAL Connect', 'music', 'TIDAL'],
        40 => ['Line in', 'music', 'LINE_IN'],
        41 => ['Bluetooth', 'music', 'BLUETOOTH'],
        42 => ['External storage', 'music', 'MUSIC'],
        43 => ['Optical', 'music', 'LINE_IN'],
        50 => ['Mirror', 'music', 'CAST'],
    ];

    /** Inputs for `switchmode:` and the `mode` each one shows up as. */
    public const INPUTS = [
        'line-in' => ['Line in', 'LINE_IN', 40],
        'bluetooth' => ['Bluetooth', 'BLUETOOTH', 41],
        'optical' => ['Optical', 'LINE_IN', 43],
        'udisk' => ['USB disk', 'MUSIC', 11],
        'wifi' => ['Network (WiiM)', 'MUSIC', 10],
    ];

    public function __construct(private readonly string $base, private readonly int $timeout = 4) {}

    public static function forDevice(Device $device): self
    {
        $url = $device->meta()->where('key', 'wiim_url')->value('value');

        return new self($url ?: 'https://'.$device->ip_address);
    }

    /** @throws \Illuminate\Http\Client\ConnectionException|\RuntimeException */
    public function raw(string $command): string
    {
        $response = Http::withoutVerifying()->timeout($this->timeout)->get(rtrim($this->base, '/').'/httpapi.asp?command='.self::encode($command));

        if (!$response->successful()) {
            throw new \RuntimeException("The WiiM answered {$response->status()} to {$command}.");
        }

        return trim($response->body());
    }

    /** Colons stay as they are (the document's examples have them raw); spaces and the like are escaped. */
    public static function encode(string $command): string
    {
        return str_replace('%3A', ':', rawurlencode($command));
    }

    /** The command's answer as a trimmed JSON array; null when it is not JSON (a plain `OK`, an error page). */
    public function json(string $command): ?array
    {
        return self::decode($this->raw($command));
    }

    public static function decode(string $body): ?array
    {
        $data = json_decode($body, true);

        return is_array($data) ? self::trimKeys($data) : null;
    }

    private static function trimKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[is_string($key) ? trim($key) : $key] = is_array($value) ? self::trimKeys($value) : $value;
        }

        return $out;
    }

    public function statusEx(): array
    {
        return $this->json('getStatusEx') ?? throw new \RuntimeException('The device did not answer getStatusEx as a WiiM does.');
    }

    /** @return array{status: string, mode: int, loop: int, position: int, duration: int, volume: int, muted: bool, playlist_count: int, playlist_index: int, slave: bool}  position and duration in seconds */
    public function playerStatus(): array
    {
        return self::parsePlayerStatus($this->json('getPlayerStatus') ?? throw new \RuntimeException('The device did not answer getPlayerStatus as a WiiM does.'));
    }

    public static function parsePlayerStatus(array $d): array
    {
        return [
            'status' => (string) ($d['status'] ?? 'stop'),
            'mode' => (int) ($d['mode'] ?? 0),
            'loop' => (int) ($d['loop'] ?? 4),
            // curpos and totlen are in ms here, seek takes seconds; both can be 0 for a stream.
            'position' => intdiv((int) ($d['curpos'] ?? 0), 1000),
            'duration' => intdiv((int) ($d['totlen'] ?? 0), 1000),
            'volume' => max(0, min(100, (int) ($d['vol'] ?? 0))),
            'muted' => (string) ($d['mute'] ?? '0') === '1',
            'playlist_count' => (int) ($d['plicount'] ?? 0),
            'playlist_index' => (int) ($d['plicurr'] ?? 0),
            'slave' => (string) ($d['type'] ?? '0') === '1',
        ];
    }

    /** @return array{title: string, artist: string, album: string, art: ?string, sample_rate: ?int, bit_depth: ?int} */
    public function metaInfo(): array
    {
        $d = $this->json('getMetaInfo') ?? [];
        $m = is_array($d['metaData'] ?? null) ? $d['metaData'] : $d;
        $text = fn (string $key) => ($v = trim((string) ($m[$key] ?? ''))) === '' || strtolower($v) === 'unknown' ? '' : $v;

        return [
            'title' => $text('title'),
            'artist' => $text('artist'),
            'album' => $text('album'),
            'art' => $text('albumArtURI') ?: null,
            'sample_rate' => ($v = (int) ($m['sampleRate'] ?? 0)) > 0 ? $v : null,
            'bit_depth' => ($v = (int) ($m['bitDepth'] ?? 0)) > 0 ? $v : null,
        ];
    }

    /** @return array<int, array{number: int, name: string, url: ?string, source: ?string, art: ?string}> */
    public function presets(): array
    {
        $list = ($this->json('getPresetInfo') ?? [])['preset_list'] ?? [];

        return collect($list)->filter(fn ($p) => isset($p['number']))->map(fn ($p) => [
            'number' => (int) $p['number'],
            'name' => (string) ($p['name'] ?? 'Preset '.$p['number']),
            'url' => $p['url'] ?? null,
            'source' => $p['source'] ?? null,
            'art' => $p['picurl'] ?? null,
        ])->sortBy('number')->values()->all();
    }

    /** @return string[] */
    public function eqList(): array
    {
        return array_values(array_filter($this->json('EQGetList') ?? [], 'is_string'));
    }

    public function eqOn(): ?bool
    {
        $stat = ($this->json('EQGetStat') ?? [])['EQStat'] ?? null;

        return $stat === null ? null : strtolower((string) $stat) === 'on';
    }

    /** A control command: answered `OK` (setPlayerCmd) or `{"status":"OK"}` (EQ, shutdown). @throws \RuntimeException when refused */
    public function command(string $command): void
    {
        $body = $this->raw($command);
        $ok = strcasecmp($body, 'OK') === 0 || strcasecmp((string) (self::decode($body)['status'] ?? ''), 'OK') === 0;

        if (!$ok) {
            throw new \RuntimeException("The WiiM did not accept {$command}: ".substr($body, 0, 100));
        }
    }

    public function player(string $cmd): void
    {
        $this->command('setPlayerCmd:'.$cmd);
    }
}
