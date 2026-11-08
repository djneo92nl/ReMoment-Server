<?php

namespace App\Domain\Developer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two developer switches on `/stream/{track}` (docs/architecture/developer-tools.md): a request log, and fault
 * injection (a delay, or an error now and then). Both live in the cache with a time limit so they cannot be
 * forgotten on, and cost one cache read per stream request when off.
 */
final class StreamDiagnostics
{
    public const LOG_MINUTES = 60;

    public const FAULT_MINUTES = 15;

    public const FAULT_MODES = ['delay', '404', '500', '503'];

    private const KEEP = 100;

    public static function logging(): bool
    {
        return (bool) Cache::get('dev:stream:log');
    }

    public static function setLogging(bool $on): void
    {
        $on ? Cache::put('dev:stream:log', true, now()->addMinutes(self::LOG_MINUTES)) : Cache::forget('dev:stream:log');
    }

    /** @return array{mode: string, percent: int, delay_ms: int}|null */
    public static function fault(): ?array
    {
        return Cache::get('dev:stream:fault');
    }

    public static function setFault(?string $mode, int $percent = 100, int $delayMs = 2000): void
    {
        if ($mode === null || !in_array($mode, self::FAULT_MODES, true)) {
            Cache::forget('dev:stream:fault');

            return;
        }

        Cache::put('dev:stream:fault', [
            'mode' => $mode,
            'percent' => max(1, min(100, $percent)),
            'delay_ms' => max(0, min(10000, $delayMs)),
        ], now()->addMinutes(self::FAULT_MINUTES));
    }

    /** The injected failure for this request, or null to serve it normally (a delay sleeps and then also serves it). */
    public static function inject(): ?Response
    {
        $fault = self::fault();

        if (!$fault || random_int(1, 100) > $fault['percent']) {
            return null;
        }

        if ($fault['mode'] === 'delay') {
            usleep($fault['delay_ms'] * 1000);

            return null;
        }

        return response('Injected by /settings/developer', (int) $fault['mode']);
    }

    public static function record(Request $request, int $trackId, int $status, ?int $bytes, int $ms, ?string $note = null): void
    {
        $rows = self::entries();
        array_unshift($rows, [
            'at' => now()->format('H:i:s'),
            'track' => $trackId,
            'ip' => $request->ip(),
            'agent' => (string) $request->userAgent(),
            'range' => $request->header('Range'),
            'profile' => $request->query('profile'),
            'status' => $status,
            'bytes' => $bytes,
            'ms' => $ms,
            'note' => $note,
        ]);

        Cache::put('dev:stream:entries', array_slice($rows, 0, self::KEEP), now()->addMinutes(self::LOG_MINUTES));
    }

    /** @return array<int, array<string, mixed>> newest first */
    public static function entries(): array
    {
        return Cache::get('dev:stream:entries', []);
    }

    public static function clear(): void
    {
        Cache::forget('dev:stream:entries');
    }

    public static function sizeOf(Response $response): ?int
    {
        return $response instanceof BinaryFileResponse ? $response->getFile()->getSize() : ((int) $response->headers->get('Content-Length') ?: null);
    }
}
