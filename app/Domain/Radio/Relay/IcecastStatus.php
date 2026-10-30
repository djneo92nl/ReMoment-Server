<?php

namespace App\Domain\Radio\Relay;

use Illuminate\Support\Facades\Http;

/** Asks the local Icecast what is live and who listens (`status-json.xsl`), and sets a mount's title (`admin/metadata`). */
final class IcecastStatus
{
    public function __construct(private readonly ?string $baseUrl = null) {}

    /**
     * The mounts that have a source now, by mount name (no leading slash).
     *
     * @return array<string, array{listeners: int}>
     */
    public function mounts(): array
    {
        try {
            $response = Http::timeout(3)->get($this->url('/status-json.xsl'));
        } catch (\Throwable) {
            return [];
        }

        return $response->successful() ? self::parse($response->json() ?? []) : [];
    }

    public function isLive(string $mount): bool
    {
        return isset($this->mounts()[$mount]);
    }

    public function listeners(string $mount): int
    {
        return $this->mounts()[$mount]['listeners'] ?? 0;
    }

    /** Sets what ICY-aware players show for the mount. False when Icecast refused (not live, wrong password). */
    public function setTitle(string $mount, string $title): bool
    {
        try {
            return Http::timeout(3)
                ->withBasicAuth((string) config('radio_relay.icecast.admin_user'), (string) config('radio_relay.icecast.admin_password'))
                ->get($this->url('/admin/metadata'), ['mount' => '/'.$mount, 'mode' => 'updinfo', 'song' => $title])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `icestats.source` is missing with no source, an object with one and a list with several.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, array{listeners: int}>
     */
    public static function parse(array $status): array
    {
        $sources = $status['icestats']['source'] ?? [];

        if ($sources !== [] && array_is_list($sources) === false) {
            $sources = [$sources];
        }

        $mounts = [];

        foreach ($sources as $source) {
            $path = parse_url((string) ($source['listenurl'] ?? ''), PHP_URL_PATH);

            if (is_string($path) && $path !== '') {
                $mounts[ltrim($path, '/')] = ['listeners' => (int) ($source['listeners'] ?? 0)];
            }
        }

        return $mounts;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl ?? (string) config('radio_relay.icecast.internal_url'), '/').$path;
    }
}
