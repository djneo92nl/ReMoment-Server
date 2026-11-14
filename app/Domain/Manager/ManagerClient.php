<?php

namespace App\Domain\Manager;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The app's side of the ReMoment Manager API (docs/architecture/manager.md): status, logs, restart, audit.
 * The manager is a host service that outlives the app, so every call can fail and says why in a ManagerException.
 * Used by the Settings › Manager page (admin only); the token is the manager's own, never the admin password.
 */
class ManagerClient
{
    /** The manager's health: no token needed. Null when nothing answers. */
    public function health(): ?array
    {
        try {
            $response = Http::timeout(3)->acceptJson()->get($this->url('/health'));
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    /** @return array{project: string, services: list<array<string, mixed>>, host: array<string, mixed>} */
    public function status(): array
    {
        return $this->send('get', '/status')->json();
    }

    public function logs(string $service, int $tail = 200): string
    {
        return $this->send('get', '/logs/'.rawurlencode($service), ['tail' => max(1, min($tail, 2000))])->body();
    }

    public function restart(string $service): void
    {
        $this->send('post', '/restart/'.rawurlencode($service));
    }

    public function restartAll(): void
    {
        $this->send('post', '/restart-all');
    }

    /** Starts the upgrade in the manager (it answers at once and works in the background; poll upgradeStatus()). */
    public function upgrade(): void
    {
        $this->send('post', '/upgrade');
    }

    /** Starts the rollback of the last failed upgrade. Nothing about it is chosen by the caller. */
    public function rollback(): void
    {
        $this->send('post', '/rollback');
    }

    /**
     * @return array{running: bool, mode: string, can_rollback: bool, upgrade: ?array<string, mixed>, rollback: ?array<string, mixed>, log: list<array{t: string, line: string}>, log_next: int}
     */
    public function upgradeStatus(int $since = 0): array
    {
        return $this->send('get', '/upgrade', ['since' => max(0, $since)])->json();
    }

    /**
     * SMB shares (manager/smb.js): Samba state, the three fixed shares, the share user (name only, never a password).
     *
     * @return array{dry_run: bool, running: bool, service: string, include_present: bool, conf_file: string, problems: list<string>, user: ?array<string, mixed>, sessions: ?array<string, mixed>, shares: list<array<string, mixed>>}
     */
    public function smb(): array
    {
        return $this->send('get', '/smb')->json();
    }

    /** The Samba fragment for the current choices; nothing is written. */
    public function smbConfig(): string
    {
        return (string) $this->send('get', '/smb/config')->json('config');
    }

    /**
     * Switches one of the fixed shares (library, exports, inbox) on or off; `$writable` only counts for the library.
     * With `$dryRun` the manager renders the config and the steps and changes nothing.
     *
     * @return array<string, mixed>
     */
    public function smbShare(string $share, bool $enabled, ?bool $writable = null, bool $dryRun = false): array
    {
        $body = ['enabled' => $enabled] + ($writable === null ? [] : ['writable' => $writable]);

        return $this->send('post', '/smb/shares/'.rawurlencode($share).($dryRun ? '?dry_run=1' : ''), body: $body)->json();
    }

    /**
     * Creates the share user or sets a new password. The answer carries the generated password once; the caller must
     * show it and drop it. Nothing here logs it.
     *
     * @return array{status?: string, user: string, created?: bool, password?: string, dry_run?: bool, actions?: list<string>}
     */
    public function smbCreateUser(bool $dryRun = false): array
    {
        return $this->send('post', '/smb/user'.($dryRun ? '?dry_run=1' : ''))->json();
    }

    public function smbRemoveUser(): void
    {
        $this->send('delete', '/smb/user');
    }

    /** @return list<array{time: string, action: string, service: ?string, result: string}> */
    public function audit(int $limit = 20): array
    {
        return $this->send('get', '/audit', ['limit' => max(1, min($limit, 500))])->json('data') ?? [];
    }

    /** The manager's token, or null when this install has none to read. */
    public function token(): ?string
    {
        $file = (string) config('manager.token_file');

        if ($file !== '' && is_readable($file) && is_file($file)) {
            $token = trim((string) file_get_contents($file));

            if ($token !== '') {
                return $token;
            }
        }

        $token = trim((string) config('manager.token'));

        return $token !== '' ? $token : null;
    }

    private function send(string $method, string $path, array $query = [], array $body = []): Response
    {
        $token = $this->token();

        if ($token === null) {
            throw new ManagerException('no_token', 'This server has no manager token to read. Install the manager (ansible/manager.yml) and redeploy so the app can read its token.');
        }

        try {
            $response = $this->request($token)->$method($this->url($path), $method === 'get' ? $query : $body);
        } catch (ConnectionException) {
            throw new ManagerException('unreachable', 'The manager does not answer at '.config('manager.url').'. Is the remoment-manager service running?');
        }

        if ($response->successful()) {
            return $response;
        }

        $error = (string) ($response->json('error') ?? 'error');
        $message = (string) ($response->json('message') ?? '');

        throw new ManagerException($error, match ($response->status()) {
            401 => 'The manager refused the token. The token file the app reads is not the one the manager uses.',
            409 => 'The manager is busy with another change. Try again in a moment.',
            default => trim("The manager answered {$response->status()} {$error}. {$message}"),
        });
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->withHeaders(['x-remoment-manager' => '1'])
            ->timeout((int) config('manager.timeout'))
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('manager.url'), '/').$path;
    }
}
