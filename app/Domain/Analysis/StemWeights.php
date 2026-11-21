<?php

namespace App\Domain\Analysis;

use Illuminate\Support\Facades\Http;

/**
 * The opt-in download of the Demucs weights (docs/architecture/stems-service.md, "Weights"). The licence of the weights
 * is unclear, so neither the image nor the service fetches them by itself: the service starts without them, answers
 * `weights: missing` in its health and refuses stem jobs, and this class asks it (GET and POST `/weights` of the service)
 * to download the pinned file, checked against a pinned SHA-256, into its models volume. The admin triggers it
 * (`POST /api/admin/stems/weights`, the card on /settings/analysis, MCP `deep_analysis` action `download_weights`);
 * the state is open to read (`GET /api/stems/weights`).
 */
final class StemWeights
{
    public const MISSING = 'missing';

    public const DOWNLOADING = 'downloading';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Whether a stems service is configured at all (STEMS_URL). */
    public static function configured(): bool
    {
        return (bool) config('analysis.stems_url');
    }

    /**
     * Where the download stands, from the service: state missing | downloading | ready | failed, `size` (bytes on disk when
     * ready), `downloaded`, `total`, `percent` (null unless downloading with a known total, or ready), the pinned `source` and `sha256`, `error`
     * and the licence note and link.
     *
     * @return array{0: int, 1: array} HTTP status (200, 409 not configured, 503 unreachable, 502 the service answered wrongly) and the body
     */
    public static function status(): array
    {
        return self::call('GET');
    }

    /**
     * Asks the service to start the download (nothing happens when the weights are there or arriving): 202 with the status
     * while it runs, 200 when ready already.
     *
     * @return array{0: int, 1: array}
     */
    public static function download(): array
    {
        [$status, $body] = self::call('POST');
        // The health the app remembers for a minute may say "not ok": look again soon, so the kinds appear when the model loaded.
        DeepAnalysis::forgetHealth('stems');
        DeepAnalysis::forgetHealth('stem_audio');

        return [$status, $body];
    }

    /** True when the service has the weights: a stems job can run (the model may still be loading, `/health` says). */
    public static function ready(): bool
    {
        [$status, $body] = self::status();

        return $status === 200 && ($body['state'] ?? null) === self::READY;
    }

    /** @return array{0: int, 1: array} */
    private static function call(string $method): array
    {
        $url = config('analysis.stems_url');
        if (!$url) {
            return [409, ['error' => 'not_available', 'message' => 'No stems service is configured (STEMS_URL).']];
        }

        try {
            $request = Http::timeout(5)->acceptJson();
            $response = $method === 'POST' ? $request->post(rtrim($url, '/').'/weights') : $request->get(rtrim($url, '/').'/weights');
        } catch (\Throwable) {
            return [503, ['error' => 'unreachable', 'message' => 'The stems service is not reachable (STEMS_URL).']];
        }

        $data = $response->json();
        if (!$response->successful() || !is_array($data) || !isset($data['state'])) {
            return [502, ['error' => 'driver_error', 'message' => 'The stems service answered HTTP '.$response->status().' to /weights; it may be an older image without the opt-in download (rebuild it).']];
        }

        return [$method === 'POST' && $data['state'] !== self::READY ? 202 : 200, self::shape($data)];
    }

    /** The service's answer as the API's: the same keys always, plus a percentage and the licence. */
    private static function shape(array $data): array
    {
        $total = isset($data['total']) ? (int) $data['total'] : null;
        $downloaded = (int) ($data['downloaded'] ?? 0);
        $state = (string) $data['state'];

        return [
            'state' => $state,
            'model' => $data['model'] ?? null,
            'size' => isset($data['size']) ? (int) $data['size'] : null,
            'downloaded' => $downloaded,
            'total' => $total,
            'percent' => match (true) {
                $state === self::READY => 100,
                $state === self::DOWNLOADING && $total > 0 => (int) min(100, floor($downloaded * 100 / $total)),
                default => null,
            },
            'source' => $data['source'] ?? null,
            'sha256' => $data['sha256'] ?? null,
            'error' => $data['error'] ?? null,
            'licence' => [
                'note' => config('analysis.stems_weights_licence_note'),
                'url' => config('analysis.stems_weights_licence_url'),
            ],
        ];
    }
}
