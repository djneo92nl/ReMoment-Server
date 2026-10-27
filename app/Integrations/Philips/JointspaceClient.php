<?php

namespace App\Integrations\Philips;

use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Jointspace v6 HTTP API of a Philips Android TV: HTTPS on port 1926 with a self-signed certificate and
 * digest authentication (the device id and key that pairing hands out). Without credentials only the open
 * endpoints answer (`system`) and the pairing calls work.
 */
class JointspaceClient
{
    public function __construct(
        private readonly string $host,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        private readonly int $timeout = 6,
    ) {}

    public static function forDevice(Device $device): self
    {
        $meta = fn (string $key) => $device->meta()->where('key', $key)->value('value');

        return new self((string) $device->ip_address, $meta('philips_user'), $meta('philips_password'));
    }

    public function paired(): bool
    {
        return $this->user !== null && $this->password !== null;
    }

    /** @return array<string, mixed> */
    public function system(): array
    {
        return $this->get('system');
    }

    /** Asks the TV to show a PIN. @return array<string, mixed> `auth_key`, `timestamp`, `timeout` */
    public function pairRequest(string $deviceId): array
    {
        return $this->post('pair/request', ['scope' => ['read', 'write', 'control'], 'device' => Pairing::deviceInfo($deviceId)], authenticated: false);
    }

    /** Hands the PIN back, signed. Authenticated with the device id and the key of pairRequest(). */
    public function pairGrant(string $deviceId, string $authKey, string $pin, int|string $timestamp): array
    {
        $client = new self($this->host, $deviceId, $authKey, $this->timeout);

        return $client->post('pair/grant', [
            'auth' => [
                'auth_AppId' => '1',
                'pin' => $pin,
                'auth_timestamp' => $timestamp,
                'auth_signature' => Pairing::signature($timestamp, $pin),
            ],
            'device' => Pairing::deviceInfo($deviceId),
        ]);
    }

    /** `On` / `Standby`; null on a TV that has no `powerstate` (some firmware answers 404). */
    public function powerState(): ?string
    {
        try {
            return $this->get('powerstate')['powerstate'] ?? null;
        } catch (UnsupportedOperationException) {
            return null;
        }
    }

    /** @return array<int, array{id: string, mac: string, type: string, 'wake-on-lan'?: string}> the TV's network interfaces */
    public function networkDevices(): array
    {
        return $this->get('network/devices');
    }

    /** The MAC of the interface that can wake the TV (Wake-on-LAN enabled), else the first one. */
    public function wakeMac(): ?string
    {
        $devices = $this->networkDevices();
        $enabled = collect($devices)->first(fn ($d) => strcasecmp((string) ($d['wake-on-lan'] ?? ''), 'Enabled') === 0);

        return ($enabled ?? $devices[0] ?? null)['mac'] ?? null;
    }

    /** @return array{current: int, min: int, max: int, muted: bool} the TV's own volume scale (0-60 on the lab TV) */
    public function volume(): array
    {
        $reply = $this->get('audio/volume');

        return [
            'current' => (int) ($reply['current'] ?? 0),
            'min' => (int) ($reply['min'] ?? 0),
            'max' => max(1, (int) ($reply['max'] ?? 100)),
            'muted' => (bool) ($reply['muted'] ?? false),
        ];
    }

    public function setVolume(int $current, bool $muted): void
    {
        $this->post('audio/volume', ['current' => $current, 'muted' => $muted]);
    }

    public function key(string $key): void
    {
        $this->post('input/key', ['key' => $key]);
    }

    /** @return array<int, array{id: string, label: string, intent: array}> the installed apps, by package */
    public function applications(): array
    {
        $apps = [];

        foreach ($this->get('applications')['applications'] ?? [] as $app) {
            $package = $app['intent']['component']['packageName'] ?? null;

            if ($package !== null) {
                $apps[] = ['id' => $package, 'label' => (string) ($app['label'] ?? $package), 'intent' => $app['intent']];
            }
        }

        return $apps;
    }

    public function launch(array $intent): void
    {
        $this->post('activities/launch', ['intent' => $intent]);
    }

    /** The package of the app in front, null when the TV does not say. */
    public function currentPackage(): ?string
    {
        try {
            return $this->get('activities/current')['component']['packageName'] ?? null;
        } catch (UnsupportedOperationException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        return $this->answer($this->request()->get($this->url($path)), $path);
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body, bool $authenticated = true): array
    {
        return $this->answer($this->request($authenticated)->post($this->url($path), $body), $path);
    }

    private function request(bool $authenticated = true): PendingRequest
    {
        $request = Http::withoutVerifying()->timeout($this->timeout)->acceptJson();

        return $authenticated && $this->paired() ? $request->withDigestAuth($this->user, $this->password) : $request;
    }

    private function url(string $path): string
    {
        return "https://{$this->host}:1926/6/{$path}";
    }

    private function answer(Response $response, string $path): array
    {
        if ($response->status() === 401) {
            throw new \RuntimeException("The TV refused the login for {$path}: pair it again (php artisan philips:pair).");
        }

        if ($response->status() === 404) {
            throw new UnsupportedOperationException("This TV has no {$path}.");
        }

        if (!$response->successful()) {
            throw new \RuntimeException("{$path}: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }
}
