<?php

namespace App\Domain\Sync;

use App\Domain\Export\ExportFormats;
use App\Models\Client;
use App\Models\SyncArtifact;
use App\Models\SyncTarget;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The one implementation of the sync operations (docs/architecture/offline-sync.md section 4): target CRUD, estimate and
 * the stateless plan. The REST controllers, the node routes, the web page and MCP (through the REST API) all call it.
 */
class SyncService
{
    public function __construct(private readonly SyncPlanner $planner = new SyncPlanner) {}

    /**
     * @param  array<string, mixed>  $data  name, kind, client_ref?, node_id?, rules?
     *
     * @throws ValidationException
     */
    public function create(array $data): SyncTarget
    {
        $kind = $data['kind'] ?? null;
        if (!in_array($kind, SyncTarget::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'kind is app or node.']);
        }

        if ($kind === SyncTarget::APP) {
            $ref = (string) ($data['client_ref'] ?? '');
            if ($ref === '' || strlen($ref) > 64) {
                throw ValidationException::withMessages(['client_ref' => 'An app target needs a client_ref (the install id the app keeps), up to 64 characters.']);
            }
            // Idempotent on client_ref: the same install finds its target again.
            if ($existing = SyncTarget::where('client_ref', $ref)->first()) {
                return $existing;
            }
        } else {
            $node = Client::find($data['node_id'] ?? null);
            if (!$node || !$node->isNode() || $node->status !== 'approved' || !in_array('sync-target', (array) $node->roles, true)) {
                throw ValidationException::withMessages(['node_id' => 'node_id must be an approved node that declares the sync-target role.']);
            }
            if (SyncTarget::where('node_id', $node->id)->exists()) {
                throw ValidationException::withMessages(['node_id' => 'This node already has a sync target (one stick per node).']);
            }
        }

        return SyncTarget::create([
            'name' => $data['name'],
            'kind' => $kind,
            'client_ref' => $kind === SyncTarget::APP ? $data['client_ref'] : null,
            'node_id' => $kind === SyncTarget::NODE ? (int) $data['node_id'] : null,
            'rules' => SyncRules::normalize($data['rules'] ?? null),
            'rules_version' => 1,
        ]);
    }

    /** @param array<string, mixed> $data name?, rules? */
    public function update(SyncTarget $target, array $data): SyncTarget
    {
        if (isset($data['name'])) {
            $target->name = $data['name'];
        }
        if (array_key_exists('rules', $data)) {
            $rules = SyncRules::normalize($data['rules']);
            if ($rules !== SyncRules::normalize($target->rules)) {
                $target->rules_version++;
            }
            $target->rules = $rules;
        }
        $target->save();

        return $target;
    }

    /** The rules of a target, normalised (a target stored before a default existed still reads right). @return array<string, mixed> */
    public function rules(SyncTarget $target): array
    {
        return SyncRules::normalize($target->rules);
    }

    /**
     * What these rules would hold, nothing stored and nothing prepared.
     *
     * @param  array<string, mixed>  $rules  normalised
     * @return array<string, mixed>
     */
    public function estimate(array $rules, ?int $freeBytes = null): array
    {
        $result = $this->planner->plan($rules, [], ['free_bytes' => $freeBytes]);

        return $this->estimateBody($rules, $result);
    }

    /** @return array<string, mixed> */
    private function estimateBody(array $rules, SyncResult $result): array
    {
        $format = ExportFormats::ofProfile($rules['profile']);

        return $result->totals() + [
            'profile' => $rules['profile'],
            'skipped' => (int) collect($result->warnings)->where('code', 'no_own_file')->sum('count'),
            'warnings' => $result->warnings,
            'format' => $format ? ['id' => $format, 'available' => ExportFormats::available($format)] + ExportFormats::describe($format) : null,
            'fill' => $rules['fill'],
        ];
    }

    /**
     * The plan for a target that says what it holds. Starts preparing the artifacts it needs (within the prepare-ahead cap)
     * unless `$prepare` is false.
     *
     * @param  list<array<string, mixed>>  $have
     * @return array<string, mixed>
     *
     * @throws SyncRefused over budget, nothing to sync, no encoder
     */
    public function plan(SyncTarget $target, array $have, ?int $freeBytes = null, ?string $fs = null, ?int $manifestRulesVersion = null, bool $prepare = true): array
    {
        $rules = $this->rules($target);
        $result = $this->planner->plan($rules, $have, [
            'free_bytes' => $freeBytes, 'fs' => $fs, 'manifest_rules_version' => $manifestRulesVersion, 'rules_version' => $target->rules_version,
        ]);
        $estimate = $this->estimateBody($rules, $result);

        if ($result->overBudget) {
            throw new SyncRefused('over_budget', 'The selections are bigger than the budget ('.round(($result->limitBytes ?? 0) / 1024 ** 3, 2).' GB usable, about '.round($result->candidateBytes / 1024 ** 3, 2).' GB selected). Pick less, raise the budget or use the priority fill.', 422, $estimate);
        }
        if ($result->wantedCount() === 0 && $have === []) {
            throw new SyncRefused('nothing_to_sync', 'The rules select no file with an own file to sync.', 422, $estimate);
        }
        if ($result->encoderMissing !== []) {
            throw new SyncRefused('encoder_unavailable', "This server's ffmpeg has no encoder for ".implode(', ', $result->encoderMissing).'.', 422, $estimate);
        }

        $diff = $result->diff;
        $artifacts = $this->artifactRows(array_map(fn (array $c) => $c['fingerprint'], array_filter($result->wanted, fn ($c) => $c['delivery'] === 'artifact')));
        $slots = (int) config('sync.prepare_ahead') - count(array_filter($artifacts, fn (SyncArtifact $a) => $a->state === SyncArtifact::PREPARING));
        $counts = ['ready' => 0, 'preparing' => 0, 'failed' => 0];
        $touch = [];

        $download = function (array $entry) use ($target, $result, &$artifacts, &$slots, &$counts, &$touch, $prepare): array {
            $meta = $result->wanted[$entry['key']];
            $body = ['key' => $entry['key'], 'path' => $entry['path'], 'fingerprint' => $entry['fingerprint'], 'size' => $entry['size']];

            if ($meta['delivery'] === 'copy') {
                $counts['ready']++;

                return $body + ['sha256' => $meta['sha256'], 'state' => 'ready', 'url' => $this->url($target, $entry['key'], $entry['fingerprint'])];
            }

            $row = $artifacts[$entry['fingerprint']] ?? null;
            if (!$row || $row->state === SyncArtifact::FAILED) {
                if ($prepare && ($slots > 0)) {
                    $row = SyncArtifacts::request($entry['fingerprint'], $meta['spec']);
                    $artifacts[$entry['fingerprint']] = $row;
                    $slots--;
                }
            }

            if ($row && $row->state === SyncArtifact::READY) {
                $counts['ready']++;
                $touch[] = $entry['fingerprint'];

                return ['size' => $row->size] + $body + ['sha256' => $row->sha256, 'state' => 'ready', 'url' => $this->url($target, $entry['key'], $entry['fingerprint'])];
            }
            if ($row && $row->state === SyncArtifact::FAILED) {
                $counts['failed']++;

                return $body + ['sha256' => null, 'state' => 'failed', 'error' => $row->error, 'url' => null];
            }
            $counts['preparing']++;
            $touch[] = $entry['fingerprint'];

            return $body + ['sha256' => null, 'state' => 'preparing', 'url' => null];
        };

        $add = array_map($download, $diff->add);
        $replace = array_map(fn ($e) => $download($e) + ['from_path' => $e['from_path'], 'from_size' => $e['from_size']], $diff->replace);
        if ($touch !== [] && $prepare) {
            SyncArtifacts::touch($touch);
        }

        return [
            'target_id' => $target->id,
            'rules_version' => $target->rules_version,
            'add' => $add,
            'replace' => $replace,
            'move' => $diff->move,
            'keep' => count($diff->keep),
            'remove' => $diff->remove,
            'path_conflicts' => $diff->pathConflicts,
            'totals' => [
                'bytes_to_add' => $diff->bytesToAdd(), 'bytes_freed' => $diff->bytesFreed(), 'net_bytes' => $diff->netBytes(),
                'wanted' => $result->wantedCount(), 'wanted_bytes' => $result->wantedBytes,
                'copied' => $result->copied, 'transcoded' => $result->transcoded,
            ] + $counts,
            'warnings' => $result->warnings,
            'deferred' => $result->deferred,
            'confirm_required' => $result->confirmRequired,
            'remove_first' => $result->removeFirst,
            'budget_bytes' => $result->limitBytes,
        ];
    }

    /** A signed, relative, 6-hour URL bound to target, key and fingerprint. */
    public function url(SyncTarget $target, string $key, string $fingerprint): string
    {
        return URL::temporarySignedRoute(
            'api.sync.files', now()->addHours((int) config('sync.url_hours')),
            ['target' => $target->id, 'key' => $key, 'fingerprint' => $fingerprint], absolute: false,
        );
    }

    /**
     * Validates the `have` of a plan request without a per-item validator (a 30,000 entry manifest must stay cheap).
     *
     * @return list<array{key: string, fingerprint: string, path: string, size: int, mtime: int}>
     *
     * @throws ValidationException
     */
    public static function have(mixed $raw): array
    {
        if (!is_array($raw) || count($raw) > 200000) {
            throw ValidationException::withMessages(['have' => 'have must be a list of at most 200000 entries.']);
        }
        $out = [];
        $keys = [];
        foreach (array_values($raw) as $i => $e) {
            foreach (['key' => 100, 'fingerprint' => 100, 'path' => 1000] as $field => $max) {
                if (!is_array($e) || !isset($e[$field]) || !is_string($e[$field]) || $e[$field] === '' || strlen($e[$field]) > $max) {
                    throw ValidationException::withMessages(["have.{$i}.{$field}" => "A have entry needs a {$field} (text, up to {$max} characters)."]);
                }
            }
            if (isset($keys[$e['key']])) {
                throw ValidationException::withMessages(["have.{$i}.key" => "Duplicate key {$e['key']}."]);
            }
            $keys[$e['key']] = true;
            $out[] = ['key' => $e['key'], 'fingerprint' => $e['fingerprint'], 'path' => $e['path'], 'size' => max(0, (int) ($e['size'] ?? 0)), 'mtime' => (int) ($e['mtime'] ?? 0)];
        }

        return $out;
    }

    /** @param list<string> $fingerprints @return array<string, SyncArtifact> */
    private function artifactRows(array $fingerprints): array
    {
        $rows = [];
        foreach (array_chunk(array_values(array_unique($fingerprints)), 500) as $chunk) {
            foreach (SyncArtifact::whereIn('fingerprint', $chunk)->get() as $row) {
                $rows[$row->fingerprint] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function resource(SyncTarget $target): array
    {
        $job = $target->job();

        return [
            'id' => $target->id,
            'name' => $target->name,
            'kind' => $target->kind,
            'client_ref' => $target->client_ref,
            'server_id' => Syncs::serverId(),
            'node_id' => $target->node_id,
            'node' => $target->node ? ['id' => $target->node->id, 'name' => $target->node->name] : null,
            'rules' => $this->rules($target),
            'rules_version' => $target->rules_version,
            'owned_bytes' => (int) $target->owned_bytes,
            'owned_count' => (int) $target->owned_count,
            'last_report' => $target->last_report,
            'last_report_at' => $target->last_report_at?->toIso8601String(),
            'last_run' => $target->last_run,
            'running' => $job !== null && !$job->isFinished(),
            'created_at' => $target->created_at?->toIso8601String(),
        ];
    }
}
