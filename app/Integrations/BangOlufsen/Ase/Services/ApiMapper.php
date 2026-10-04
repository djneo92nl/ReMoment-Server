<?php

namespace App\Integrations\BangOlufsen\Ase\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Crawls the self-describing REST API of a B&O ASE-style device (also used by
 * the NetworkLink/MasterLink converter) and returns a map of its endpoints.
 *
 * Only GET requests are ever made, so mapping never changes a device. Links
 * (`_links.*.href`) are followed; zone areas that only list `features` are
 * probed for children derived from the feature names.
 */
class ApiMapper
{
    /** Streams and health checks: never useful to crawl. */
    private const SKIP = '#(^|/)(BeoNotify|Ping)(/|$)#i';

    /** Resources that hold account data: record that they exist, never their content. */
    private const OPAQUE = '#(^|/)(credentials|Sessions)(/|$)#i';

    private const SENSITIVE_KEY = '/pass|token|secret|psk|api_?key|credential/i';

    /**
     * Always tried in addition to the root service list: `BeoZone/` itself
     * 404s and `BeoZone/Zone` answers with an empty feature list, so the
     * zone areas have to be named.
     */
    private const DEFAULT_SEEDS = [
        'BeoZone/Zone', 'BeoZone/System',
        'BeoZone/Zone/Sound', 'BeoZone/Zone/Sources', 'BeoZone/Zone/ActiveSources', 'BeoZone/Zone/Stream',
        'BeoZone/Zone/Stand', 'BeoZone/Zone/Light', 'BeoZone/Zone/List', 'BeoZone/Zone/PlayQueue',
        'BeoZone/Zone/Snapshot',
    ];

    /** Siblings of one template (e.g. a list of favourites) are crawled only this many times. */
    private const MAX_PER_TEMPLATE = 3;

    private string $base;

    /** @var array<string, array> */
    private array $endpoints = [];

    /** @var array<string, int> */
    private array $templateCount = [];

    private int $skipped = 0;

    /**
     * @param  string[]  $extraSeeds  paths to try in addition to the discovered ones
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 8080,
        private readonly int $max = 600,
        private readonly array $extraSeeds = [],
        private readonly int $timeout = 5,
    ) {
        $this->base = "http://{$host}:{$port}/";
    }

    /**
     * @return array{device: array, endpoints: array<string, array>, skipped: int}
     *
     * @throws ConnectionException when the device does not answer at all
     */
    public function map(): array
    {
        $root = $this->fetch('');
        $queue = [...$this->servicePaths($root['json'] ?? []), ...self::DEFAULT_SEEDS, ...$this->extraSeeds];

        $fetched = 0;
        while ($queue && $fetched < $this->max) {
            $path = array_shift($queue);
            $key = $this->key($path);

            if (isset($this->endpoints[$key]) || preg_match(self::SKIP, $key) || !$this->claimTemplate($key)) {
                continue;
            }

            $response = $this->fetch($path);
            $fetched++;

            // Not-found paths are only guesses (probes, stale links): leave them out of the map.
            if ($response['status'] === 404 || $response['status'] === 0) {
                continue;
            }

            $json = $response['json'];
            $this->endpoints[$key] = $this->describe($key, $response['status'], $json);

            if (!is_array($json)) {
                continue;
            }

            $url = $this->base.ltrim($path, '/');
            foreach ($this->links($json, $url) as $link) {
                $queue[] = $link;
            }
            foreach ($this->featureProbes($key, $json) as $probe) {
                $queue[] = $probe;
            }
        }

        ksort($this->endpoints);

        return [
            'device' => $this->deviceInfo(),
            'endpoints' => $this->endpoints,
            'skipped' => $this->skipped,
        ];
    }

    /**
     * Paths present in only one of two maps, with ids normalised so two
     * devices with different favourites still compare equal.
     *
     * @return array{only_a: string[], only_b: string[]}
     */
    public static function compare(array $a, array $b): array
    {
        $normalise = fn (array $map) => array_values(array_unique(array_map(
            [self::class, 'template'],
            array_keys($map['endpoints'] ?? [])
        )));

        $pathsA = $normalise($a);
        $pathsB = $normalise($b);

        return [
            'only_a' => array_values(array_diff($pathsA, $pathsB)),
            'only_b' => array_values(array_diff($pathsB, $pathsA)),
        ];
    }

    /** The path with id-like segments replaced, so list members share one template. */
    public static function template(string $path): string
    {
        $segments = array_map(function (string $segment) {
            $isId = preg_match('/^\d{6,}$/', $segment)
                || preg_match('/%3a|%2f/i', $segment)
                || (preg_match('/^[0-9a-f_:]{12,}$/i', $segment) && preg_match('/\d/', $segment));

            return $isId ? '{id}' : $segment;
        }, explode('/', trim($path, '/')));

        return implode('/', $segments);
    }

    /** @return array{status: int, json: mixed} */
    private function fetch(string $path): array
    {
        try {
            $response = Http::timeout($this->timeout)->acceptJson()->get($this->base.ltrim($path, '/'));
        } catch (ConnectionException $e) {
            if ($path === '') {
                throw $e;
            }

            return ['status' => 0, 'json' => null];
        }

        return ['status' => $response->status(), 'json' => $response->json()];
    }

    /** @return string[] */
    private function servicePaths(mixed $root): array
    {
        $paths = [];
        foreach ($root['services'] ?? [] as $service) {
            if (isset($service['path'])) {
                $paths[] = $service['path'];
            }
        }

        return $paths;
    }

    private function key(string $path): string
    {
        return trim($path, '/');
    }

    private function claimTemplate(string $key): bool
    {
        $template = self::template($key);
        $this->templateCount[$template] = ($this->templateCount[$template] ?? 0) + 1;

        if ($this->templateCount[$template] > self::MAX_PER_TEMPLATE) {
            $this->skipped++;

            return false;
        }

        return true;
    }

    /** @return string[] resolved paths (no query) of every `href` in the response */
    private function links(array $json, string $currentUrl): array
    {
        $hrefs = [];
        array_walk_recursive($json, function ($value, $key) use (&$hrefs) {
            if ($key === 'href' && is_string($value) && $value !== '') {
                $hrefs[] = $value;
            }
        });

        $current = new Uri($currentUrl);
        $paths = [];
        foreach (array_unique($hrefs) as $href) {
            $resolved = UriResolver::resolve($current, new Uri($href));
            if ($resolved->getHost() !== $current->getHost() || $resolved->getPort() !== $current->getPort()) {
                continue;
            }
            $paths[] = ltrim($resolved->getPath(), '/');
        }

        return $paths;
    }

    /**
     * A response that is only `{"features": [...]}` lists names, not paths.
     * `SPEAKER_WIRELESS_SETUP` under `BeoZone/Zone/Sound` lives at
     * `Sound/SpeakerWirelessSetup`, `SOUND_ADJUSTMENT` at `Sound/Adjustment`:
     * try the full name, the name without its first word and the first two words.
     *
     * @return string[]
     */
    private function featureProbes(string $key, array $json): array
    {
        $features = $json['features'] ?? null;
        if (!is_array($features) || count($json) !== 1) {
            return [];
        }

        $probes = [];
        foreach ($features as $feature) {
            $words = array_map(fn ($w) => ucfirst(strtolower($w)), explode('_', (string) $feature));
            $variants = [implode('', $words), implode('', array_slice($words, 1)), implode('', array_slice($words, 0, 2))];

            foreach (array_filter(array_unique($variants)) as $variant) {
                $probes[] = "{$key}/{$variant}";
            }
        }

        return $probes;
    }

    private function describe(string $key, int $status, mixed $json): array
    {
        $node = ['status' => $status];

        if (!is_array($json)) {
            return $node;
        }

        if (isset($json['features']) && is_array($json['features'])) {
            $node['features'] = $json['features'];
        }

        $editable = $values = $ranges = $relations = [];
        $this->collect($json, '', $editable, $values, $ranges, $relations);

        foreach (['editable' => $editable, 'values' => $values, 'ranges' => $ranges, 'relations' => $relations] as $name => $found) {
            if ($found) {
                $node[$name] = $found;
            }
        }

        if (preg_match(self::OPAQUE, $key)) {
            $node['body'] = null;
            $node['redacted'] = true;
        } else {
            $node['body'] = $this->redact($json);
        }

        return $node;
    }

    /**
     * Gathers what a resource says about itself: editable fields, allowed
     * values, ranges and `/relation/*` links, each keyed by its dotted path.
     */
    private function collect(array $node, string $trail, array &$editable, array &$values, array &$ranges, array &$relations): void
    {
        foreach ($node['_capabilities']['editable'] ?? [] as $field) {
            $editable[] = ltrim("{$trail}.{$field}", '.');
        }
        foreach ($node['_capabilities']['value'] ?? [] as $field => $allowed) {
            $values[ltrim("{$trail}.{$field}", '.')] = $allowed;
        }
        foreach ($node['_capabilities']['range'] ?? [] as $field => $range) {
            $ranges[ltrim("{$trail}.{$field}", '.')] = $range;
        }
        foreach ($node['_links'] ?? [] as $relation => $link) {
            if (str_starts_with((string) $relation, '/relation/') && isset($link['href'])) {
                $relations[ltrim("{$trail}.{$relation}", '.')] = $link['href'];
            }
        }

        foreach ($node as $name => $child) {
            if (in_array($name, ['_capabilities', '_links'], true) || !is_array($child)) {
                continue;
            }
            $childTrail = is_int($name) ? "{$trail}[]" : ltrim("{$trail}.{$name}", '.');
            $this->collect($child, $childTrail, $editable, $values, $ranges, $relations);
        }
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function deviceInfo(): array
    {
        $device = $this->endpoints['BeoDevice']['body']['beoDevice'] ?? [];

        return array_filter([
            'host' => $this->host,
            'port' => $this->port,
            'product_type' => $device['productId']['productType'] ?? null,
            'name' => $device['productFriendlyName']['productFriendlyName'] ?? null,
            'software' => $device['software']['version'] ?? null,
            'mapped_at' => now()->toIso8601String(),
        ]);
    }
}
