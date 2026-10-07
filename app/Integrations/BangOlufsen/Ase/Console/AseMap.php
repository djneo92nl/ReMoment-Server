<?php

namespace App\Integrations\BangOlufsen\Ase\Console;

use App\Integrations\BangOlufsen\Ase\Services\ApiMapper;
use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AseMap extends Command
{
    protected $signature = 'ase:map
        {target : Device id or IP address}
        {--port=8080 : API port}
        {--out= : Write the map here (default: a file in storage/app/ase-maps)}
        {--max=600 : Maximum number of requests}
        {--seed=* : Extra paths to try, e.g. BeoZone/Zone/Video}
        {--compare= : Compare with an earlier map file instead of only printing this one}';

    protected $description = 'Crawl a B&O ASE device (or NetworkLink/MasterLink converter) API, GET-only, and map its endpoints';

    public function handle(): int
    {
        $host = $this->resolveHost((string) $this->argument('target'));

        $this->info("Mapping http://{$host}:{$this->option('port')}/ (GET requests only)");

        try {
            $map = (new ApiMapper($host, (int) $this->option('port'), (int) $this->option('max'), $this->option('seed')))->map();
        } catch (ConnectionException $e) {
            $this->error("{$host} did not answer: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->printSummary($map);

        $file = $this->option('out') ?: storage_path('app/ase-maps/'.$host.'-'.Str::slug($map['device']['product_type'] ?? 'unknown').'.json');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info('Saved '.$file);

        if ($compare = $this->option('compare')) {
            return $this->printComparison($map, (string) $compare);
        }

        return self::SUCCESS;
    }

    private function resolveHost(string $target): string
    {
        if (ctype_digit($target) && ($device = Device::find((int) $target)) && $device->ip_address) {
            return $device->ip_address;
        }

        return $target;
    }

    private function printSummary(array $map): void
    {
        $device = $map['device'];
        $this->line(sprintf('%s  %s  firmware %s', $device['name'] ?? '?', $device['product_type'] ?? '?', $device['software'] ?? '?'));

        $rows = [];
        foreach ($map['endpoints'] as $path => $node) {
            $rows[] = [
                $path,
                $node['status'].($node['status'] === 405 ? ' (write-only)' : ''),
                Str::limit(implode(', ', $node['editable'] ?? []), 50),
                count($node['relations'] ?? []) ?: '',
            ];
        }

        $this->table(['Path', 'Status', 'Editable', 'Links'], $rows);
        $this->line(count($rows).' endpoints'.($map['skipped'] ? ", {$map['skipped']} list members skipped" : ''));
    }

    private function printComparison(array $map, string $file): int
    {
        if (!File::exists($file)) {
            $this->error("Cannot compare: {$file} does not exist");

            return self::FAILURE;
        }

        $other = json_decode(File::get($file), true);
        $diff = ApiMapper::compare($map, $other);

        $this->newLine();
        $this->line('Only on this device ('.($map['device']['product_type'] ?? 'this').'):');
        foreach ($diff['only_a'] as $path) {
            $this->line("  + {$path}");
        }
        $this->line('Only in '.basename($file).' ('.($other['device']['product_type'] ?? 'other').'):');
        foreach ($diff['only_b'] as $path) {
            $this->line("  - {$path}");
        }

        return self::SUCCESS;
    }
}
