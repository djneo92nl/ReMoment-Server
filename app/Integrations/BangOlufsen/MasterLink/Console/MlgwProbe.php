<?php

namespace App\Integrations\BangOlufsen\MasterLink\Console;

use App\Integrations\BangOlufsen\MasterLink\BusTelegram;
use App\Integrations\BangOlufsen\MasterLink\MlgwFrame;
use App\Integrations\BangOlufsen\MasterLink\MlgwFrameBuffer;
use App\Integrations\BangOlufsen\MasterLink\TelnetFilter;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Listens to a Master Link Gateway (MLGW / BLGW) and records what it says, to see what a BeoSound 4 or
 * Ouverture on its bus reports before a driver is written. It only reads: it logs in, asks for the
 * serial number, answers keepalives and starts the bus log; no key, source or volume command is sent.
 */
class MlgwProbe extends Command
{
    protected $signature = 'mlgw:probe
        {host : IP address of the Master Link Gateway}
        {--user=admin : User for the IP protocol and the web interface}
        {--password= : Gateway password (asked for when left out, so it stays out of the shell history)}
        {--port=9000 : MLGW IP protocol port}
        {--telnet-port=23 : Port of the gateway CLI that streams the bus log}
        {--seconds=120 : How long to listen}
        {--no-http : Skip the web interface and its config JSON}
        {--no-api : Skip the IP protocol}
        {--no-bus : Skip the bus log}
        {--out= : Capture file (default: a .jsonl in storage/app/mlgw-probes)}';

    protected $description = 'Listen to a Master Link Gateway (IP protocol and bus log), read-only, and record what it reports';

    private const HTTP_PATHS = ['/mlgw/mlgwpservices.json', '/mlgwpservices.json', '/'];

    /** @var resource|null */
    private $capture = null;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, true> */
    private array $texts = [];

    public function handle(): int
    {
        $host = (string) $this->argument('host');
        $password = (string) ($this->option('password') ?: $this->secret('Gateway password'));
        $user = (string) $this->option('user');

        if ($password === '') {
            $this->error('A password is needed: the gateway answers nothing without a login.');

            return self::FAILURE;
        }

        $file = $this->option('out') ?: storage_path('app/mlgw-probes/'.$host.'-'.date('Ymd-His').'.jsonl');
        File::ensureDirectoryExists(dirname($file));
        $this->capture = fopen($file, 'ab');

        $this->info("Probing {$host}: read-only, nothing is sent that changes the system.");

        if (!$this->option('no-http')) {
            $this->probeHttp($host, $user, $password);
        }

        $this->listen($host, $user, $password);
        $this->summary();

        fclose($this->capture);
        $this->info('Saved '.$file);

        return self::SUCCESS;
    }

    private function probeHttp(string $host, string $user, string $password): void
    {
        $this->line('<options=bold>Web interface</>');

        foreach (self::HTTP_PATHS as $path) {
            try {
                $response = Http::timeout(5)->withDigestAuth($user, $password)->get("http://{$host}{$path}");

                if ($response->status() === 401) {
                    $response = Http::timeout(5)->withBasicAuth($user, $password)->get("http://{$host}{$path}");
                }
            } catch (ConnectionException $e) {
                $this->warn("  {$path}: no answer ({$e->getMessage()})");

                return;
            }

            $this->line(sprintf('  %s: %d, %d bytes', $path, $response->status(), strlen($response->body())));

            if (!$response->successful()) {
                continue;
            }

            $json = $response->json();
            $this->record('http', ['path' => $path, 'status' => $response->status(), 'body' => is_array($json) ? $this->redact($json) : substr($response->body(), 0, 2000)]);

            if (is_array($json)) {
                $this->line('    keys: '.implode(', ', array_keys($json)));
            }
        }
    }

    private function listen(string $host, string $user, string $password): void
    {
        $deadline = microtime(true) + (int) $this->option('seconds');
        $sockets = [];

        if (!$this->option('no-api') && ($stream = $this->connect($host, (int) $this->option('port'), 'api'))) {
            fwrite($stream, MlgwFrame::login($user, $password));
            $this->record('api', ['event' => 'login sent', 'user' => $user]);
            $sockets['api'] = ['stream' => $stream, 'frames' => new MlgwFrameBuffer, 'serial' => false, 'ping' => microtime(true)];
        }

        if (!$this->option('no-bus') && ($stream = $this->connect($host, (int) $this->option('telnet-port'), 'bus'))) {
            $sockets['bus'] = ['stream' => $stream, 'telnet' => new TelnetFilter, 'phase' => 'login', 'text' => '', 'since' => microtime(true), 'keepalive' => microtime(true)];
        }

        if ($sockets === []) {
            $this->warn('Nothing to listen to.');

            return;
        }

        $this->line('<options=bold>Listening</> for '.(int) $this->option('seconds').' s: press keys on the master, change source, turn the volume.');

        while ($sockets !== [] && microtime(true) < $deadline) {
            $read = array_map(fn (array $s) => $s['stream'], $sockets);
            $write = $except = null;

            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $stream) {
                $channel = array_search($stream, array_map(fn (array $s) => $s['stream'], $sockets), true);
                $bytes = fread($stream, 8192);

                if ($bytes === '' || $bytes === false) {
                    if (feof($stream)) {
                        $this->warn("[{$channel}] closed by the gateway");
                        unset($sockets[$channel]);
                    }

                    continue;
                }

                $channel === 'api' ? $this->onApiBytes($sockets['api'], $bytes) : $this->onBusBytes($sockets['bus'], $bytes, $password);

                if ($channel === 'bus' && $sockets['bus']['phase'] === 'failed') {
                    unset($sockets['bus']);
                }
            }

            $this->keepAlive($sockets);
        }

        foreach ($sockets as $socket) {
            fclose($socket['stream']);
        }
    }

    private function connect(string $host, int $port, string $channel)
    {
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 5);

        if (!$stream) {
            $this->warn("[{$channel}] cannot connect to {$host}:{$port}: {$error}");

            return null;
        }

        stream_set_blocking($stream, false);
        $this->line("[{$channel}] connected to {$host}:{$port}");

        return $stream;
    }

    /** @param array<string, mixed> $socket */
    private function onApiBytes(array &$socket, string $bytes): void
    {
        foreach ($socket['frames']->feed($bytes) as $frame) {
            $this->show('api', $frame->name(), trim($frame->name().'  '.$frame->describe()), $frame->hex());
            $this->record('api', ['type' => $frame->type, 'name' => $frame->name(), 'hex' => $frame->hex(), 'fields' => $frame->describe()]);
            $this->counts['api '.$frame->name()] = ($this->counts['api '.$frame->name()] ?? 0) + 1;

            if ($frame->type === MlgwFrame::LOGIN_STATUS && !$socket['serial']) {
                fwrite($socket['stream'], MlgwFrame::encode(MlgwFrame::SERIAL_REQUEST));
                $socket['serial'] = true;
            }
        }
    }

    /** @param array<string, mixed> $socket */
    private function onBusBytes(array &$socket, string $bytes, string $password): void
    {
        [$text, $reply] = $socket['telnet']->filter($bytes);

        if ($reply !== '') {
            fwrite($socket['stream'], $reply);
        }

        $socket['text'] .= $text;

        if ($socket['phase'] === 'login' && preg_match('/login:\s*$/i', $socket['text'])) {
            fwrite($socket['stream'], $password."\n");
            $socket['phase'] = 'prompt';
            $socket['text'] = '';
            $this->record('bus', ['event' => 'password sent']);

            return;
        }

        if ($socket['phase'] === 'prompt') {
            if (preg_match('/login:\s*$/i', $socket['text'])) {
                $this->error('[bus] the gateway asked for the login again: wrong password');
                $this->record('bus', ['event' => 'login refused']);
                $socket['phase'] = 'failed';

                return;
            }

            if (preg_match('/LGW\s*>/', $socket['text'])) {
                fwrite($socket['stream'], "_MLLOG ONLINE\r\n");
                $socket['phase'] = 'log';
                $socket['text'] = '';
                $this->record('bus', ['event' => 'bus log started']);
                $this->line('[bus] logged in, bus log started');
            }

            return;
        }

        if ($socket['phase'] !== 'log') {
            return;
        }

        while (($newline = strpos($socket['text'], "\n")) !== false) {
            $this->onBusLine(substr($socket['text'], 0, $newline));
            $socket['text'] = substr($socket['text'], $newline + 1);
        }
    }

    private function onBusLine(string $line): void
    {
        $line = trim($line);

        if ($line === '') {
            return;
        }

        $telegram = BusTelegram::parse($line);

        if ($telegram === null) {
            $this->show('bus', 'text', $line, '');
            $this->record('bus', ['raw' => $line]);

            return;
        }

        $this->show('bus', 'telegram', $telegram->describe(), $telegram->hex());
        $this->record('bus', ['time' => $telegram->time, 'hex' => $telegram->hex(), 'fields' => $telegram->describe()]);

        $key = 'bus '.(BusTelegram::PAYLOAD_TYPES[$telegram->payloadType()] ?? 'other');
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        if (($text = $telegram->text()) !== null) {
            $this->texts[$text] = true;
        }
    }

    /** @param array<string, array<string, mixed>> $sockets */
    private function keepAlive(array &$sockets): void
    {
        if (isset($sockets['api']) && microtime(true) - $sockets['api']['ping'] > 240) {
            fwrite($sockets['api']['stream'], MlgwFrame::encode(MlgwFrame::PING));
            $sockets['api']['ping'] = microtime(true);
        }

        if (isset($sockets['bus'])) {
            if (microtime(true) - $sockets['bus']['keepalive'] > 240) {
                fwrite($sockets['bus']['stream'], "\0");
                $sockets['bus']['keepalive'] = microtime(true);
            }

            if ($sockets['bus']['phase'] !== 'log' && microtime(true) - $sockets['bus']['since'] > 15) {
                $this->warn('[bus] no login or prompt recognised after 15 s; the gateway said: '.json_encode($sockets['bus']['text']));
                $this->record('bus', ['event' => 'no prompt', 'phase' => $sockets['bus']['phase'], 'text' => $sockets['bus']['text']]);
                fclose($sockets['bus']['stream']);
                unset($sockets['bus']);
            }
        }
    }

    private function summary(): void
    {
        $this->newLine();
        $this->line('<options=bold>Summary</>');

        if ($this->counts === []) {
            $this->warn('Nothing was received. Check the IP, the password and that the master is on.');

            return;
        }

        ksort($this->counts);
        $this->table(['Message', 'Count'], array_map(fn ($k, $v) => [$k, $v], array_keys($this->counts), $this->counts));

        if ($this->texts !== []) {
            $this->line('Texts seen on the bus: '.implode(' | ', array_keys($this->texts)));
        }
    }

    private function show(string $channel, string $kind, string $description, string $hex): void
    {
        $this->line(sprintf('<fg=gray>%s</> <fg=cyan>%-3s</> %s%s', date('H:i:s'), $channel, $description !== '' ? $description : $kind, $hex !== '' ? "  <fg=gray>[{$hex}]</>" : ''));
    }

    /** @param array<string, mixed> $data */
    private function record(string $channel, array $data): void
    {
        fwrite($this->capture, json_encode(['at' => date('c'), 'channel' => $channel] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|key|camera|url/i', $key)) {
                $data[$key] = '(hidden)';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
