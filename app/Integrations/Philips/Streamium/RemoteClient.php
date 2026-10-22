<?php

namespace App\Integrations\Philips\Streamium;

use App\Models\Device;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The phone web remote of a Philips Streamium HiFi player (MCi900 and its siblings), port 8889: the unit's
 * own screens and local sources (internet radio, FM, AUX, disc), which UPnP cannot reach. Plain HTTP, no login.
 * Commands are GETs answered `{'command':'SUCCESS'}` (JavaScript, not JSON), except standby, a form POST the
 * unit never answers. See docs/architecture/upnp-renderer.md (the MCi900 section).
 */
class RemoteClient
{
    public const PORT = 8889;

    private const CONNECT_TIMEOUT = 20;

    public function __construct(private readonly string $base, private readonly int $timeout = 4) {}

    public static function forDevice(Device $device): self
    {
        $url = $device->meta()->where('key', 'streamium_remote_url')->value('value');

        return new self($url ?: 'http://'.$device->ip_address.':'.self::PORT);
    }

    /** Does something answer as the web remote? */
    public function reachable(): bool
    {
        try {
            return str_contains($this->get('/HOMESTATUS'), "'command'");
        } catch (\Throwable) {
            return false;
        }
    }

    /** @throws \RuntimeException when the unit does not accept the command */
    public function command(string $path): void
    {
        $answer = $this->get($path);

        // NEXT/PREV answer NOWPLAY, a change signal, instead of SUCCESS.
        if (!str_contains($answer, "'SUCCESS'") && !str_contains($answer, "'NOWPLAY'")) {
            throw new \RuntimeException("The player did not accept {$path}: ".trim($answer));
        }
    }

    /** Toggles standby. The unit acts on it but never answers, so the timeout is the normal outcome. */
    public function toggleStandby(): void
    {
        try {
            Http::timeout(2)->asForm()->post($this->base.'/CTRL$STANDBY', ['command' => '']);
        } catch (ConnectionException) {
            // Expected: no reply.
        }
    }

    /**
     * What the unit's local source is doing, or null: `/ELAPSE` alternates a change signal with the status, so it
     * is asked again until the status comes. It lags a command by about one poll.
     *
     * @return array{elapsed: int, playing: bool, paused: bool, muted: bool, shuffle: int, repeat: int, volume: int}|null
     */
    public function status(int $attempts = 4): ?array
    {
        for ($i = 0; $i < $attempts; $i++) {
            $status = self::parseStatus($this->get('/ELAPSE'));

            if ($status !== null) {
                return $status;
            }

            usleep(400_000);
        }

        return null;
    }

    /** @return array{title: string, station: string|null, art: string|null, volume: int, max_volume: int, elapsed: int}|null */
    public function nowPlaying(): ?array
    {
        return self::parsePlayItem($this->get('/nowplay'));
    }

    /** @return array<int, array{id: int, name: string, target: string}> the unit's ten radio presets */
    public function radioPresets(): array
    {
        return self::parsePresets($this->get('/nav$03$02$001$0'));
    }

    /** The unit answers when the stream is up (a page about connecting), which takes several seconds. */
    public function playRadioPreset(string $target): void
    {
        $this->get($target, self::CONNECT_TIMEOUT);
    }

    private function get(string $path, ?int $timeout = null): string
    {
        return Http::timeout($timeout ?? $this->timeout)->get($this->base.$path)->body();
    }

    /** @return array{elapsed: int, playing: bool, paused: bool, muted: bool, shuffle: int, repeat: int, volume: int}|null null for the change signal */
    public static function parseStatus(string $body): ?array
    {
        if (!str_contains($body, "'command':'ELAPSE'")) {
            return null;
        }

        $number = fn (string $key) => preg_match("/'{$key}':(-?\d+)/", $body, $m) ? (int) $m[1] : 0;

        return [
            'elapsed' => $number('value'),
            'playing' => $number('play') === 1,
            'paused' => $number('play') === 2,
            'muted' => $number('mute') === 1,
            'shuffle' => $number('shuffle'),
            'repeat' => $number('repeat'),
            'volume' => $number('volume'),
        ];
    }

    /**
     * The `playItem` of the play screen. For radio the title is the stream's own ("Station - Show - Artists") in
     * single quotes, the sub title the station, the art the station logo (TuneIn's old RadioTime host).
     *
     * @return array{title: string, station: string|null, art: string|null, volume: int, max_volume: int, elapsed: int}|null
     */
    public static function parsePlayItem(string $html): ?array
    {
        if (!preg_match('/var playItem = \{(.*?)\};/s', $html, $block)) {
            return null;
        }

        $text = fn (string $key) => preg_match("/'{$key}':'((?:\\\\.|[^'\\\\])*)'/", $block[1], $m) ? self::unescape($m[1]) : null;
        $number = fn (string $key) => preg_match("/'{$key}':(-?\d+)/", $block[1], $m) ? (int) $m[1] : 0;

        $title = trim((string) $text('title'), " '");

        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'station' => ($station = trim((string) $text('subTitle'))) !== '' ? $station : null,
            'art' => ($art = $text('albumArt')) ? $art : null,
            'volume' => $number('volume'),
            'max_volume' => $number('maxvolume') ?: 64,
            'elapsed' => $number('elapsetime'),
        ];
    }

    /** @return array<int, array{id: int, name: string, target: string}> */
    public static function parsePresets(string $html): array
    {
        preg_match_all("/\{'title':'((?:\\\\.|[^'\\\\])*)'\s*,\s*'id':(\d+),.*?'target':'([^']+)'/s", $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => ['id' => (int) $m[2], 'name' => self::unescape($m[1]), 'target' => $m[3]], $matches);
    }

    private static function unescape(string $javascript): string
    {
        return preg_replace('/\\\\(.)/', '$1', $javascript);
    }
}
