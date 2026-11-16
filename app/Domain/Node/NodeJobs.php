<?php

namespace App\Domain\Node;

use App\Models\Client;
use App\Models\NodeJob;
use App\Services\MqttService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Jobs for nodes: fixed types, one validator each, a lifecycle of queued, accepted, running and a final state
 * (docs/architecture/node-agent.md section 4). REST is authoritative; the MQTT message is only a nudge.
 */
final class NodeJobs
{
    public const LEASE_SECONDS = 60;

    /**
     * The fixed job types: the role that must be enabled (null: any node), seconds until a queued job expires,
     * and the params (name => rule list). Nothing else is ever accepted.
     */
    public const TYPES = [
        'ping' => ['role' => null, 'ttl' => 30, 'params' => ['message' => ['nullable', 'string', 'max:100']]],
        // Line-in (18.2): the capture device is the node's own config, never a param. The Icecast port and source password
        // are added when the node fetches the job (see deliver()), so the password is never stored or listed.
        'line_in.start' => ['role' => 'line-in', 'ttl' => 120, 'params' => ['mount' => ['required', 'string', 'regex:/^linein-\d{1,9}\.mp3$/']]],
        'line_in.stop' => ['role' => 'line-in', 'ttl' => 120, 'params' => []],
    ];

    public static function topic(Client $node): string
    {
        return "remoment/node/{$node->id}/cmd/job";
    }

    /**
     * Create a job (or return the existing one for the same idempotency key) and nudge the node.
     *
     * @param  array<string, mixed>  $params
     */
    public static function create(Client $node, string $type, array $params = [], ?string $idempotencyKey = null): NodeJob
    {
        $spec = self::TYPES[$type] ?? throw ValidationException::withMessages(['type' => "Unknown job type {$type}."]);

        if (!$node->isNode() || $node->status !== 'approved') {
            throw ValidationException::withMessages(['node' => 'Not an approved node.']);
        }
        if ($spec['role'] !== null && !in_array($spec['role'], $node->activeRoles(), true)) {
            throw ValidationException::withMessages(['type' => "The node has not got the {$spec['role']} role switched on."]);
        }

        $clean = validator($params, $spec['params'])->validate();

        if ($idempotencyKey !== null && $existing = NodeJob::where('client_id', $node->id)->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $job = NodeJob::create([
            'id' => (string) Str::ulid(),
            'client_id' => $node->id,
            'role' => $spec['role'],
            'type' => $type,
            'params' => $clean ?: null,
            'state' => 'queued',
            'idempotency_key' => $idempotencyKey,
            'deadline_at' => now()->addSeconds($spec['ttl']),
        ]);

        self::nudge($node, $job);

        return $job;
    }

    /**
     * The job as the node gets it: the stored payload, plus for `line_in.start` the Icecast port and source password
     * (REST only, Bearer node token, never MQTT, never in the admin listing).
     *
     * @return array<string, mixed>
     */
    public static function deliver(NodeJob $job): array
    {
        $payload = $job->toPayload();

        if ($job->type === 'line_in.start') {
            $payload['params'] = ((array) ($job->params ?? [])) + [
                'icecast_port' => (int) config('radio_relay.icecast.port'),
                'source_password' => (string) config('radio_relay.icecast.source_password'),
            ];
        }

        return $payload;
    }

    /** A doorbell, not a command: the node fetches the job over REST. A broker that is down only costs latency. */
    private static function nudge(Client $node, NodeJob $job): void
    {
        try {
            app(MqttService::class)->publish(self::topic($node), json_encode(['job_id' => $job->id]), 1);
        } catch (\Throwable $e) {
            Log::info("Node {$node->id} not nudged: {$e->getMessage()}");
        }
    }

    /** Move queued jobs past their deadline to expired and jobs whose lease ran out to lost. */
    public static function sweep(?Client $node = null): void
    {
        $scope = fn () => NodeJob::query()->when($node, fn ($q) => $q->where('client_id', $node->id));

        $scope()->where('state', 'queued')->where('deadline_at', '<', now())
            ->update(['state' => 'expired', 'error' => 'not accepted in time', 'finished_at' => now()]);
        $scope()->whereIn('state', ['accepted', 'running'])->where('lease_until', '<', now())
            ->update(['state' => 'lost', 'error' => 'lease expired', 'finished_at' => now()]);
    }

    /** The oldest queued job of this node, or null. A job already accepted by it is returned again (a retry after a lost answer). */
    public static function next(Client $node): ?NodeJob
    {
        self::sweep($node);

        return NodeJob::where('client_id', $node->id)->whereIn('state', ['accepted', 'queued'])
            ->where(fn ($q) => $q->whereNull('role')->orWhereIn('role', $node->activeRoles() ?: ['-']))
            ->orderByRaw("state = 'accepted' DESC")->orderBy('id')->first();
    }

    /** Atomic queued to accepted. False when somebody got there first or the job is not queued. */
    public static function accept(NodeJob $job): bool
    {
        return NodeJob::where('id', $job->id)->where('state', 'queued')->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', now()))
            ->update([
                'state' => 'accepted',
                'accepted_at' => now(),
                'lease_until' => now()->addSeconds(self::LEASE_SECONDS),
                'updated_at' => now(),
            ]) === 1;
    }

    /** Renew the lease and note progress; returns false when the job is no longer the node's to run. */
    public static function progress(NodeJob $job): bool
    {
        return NodeJob::where('id', $job->id)->whereIn('state', ['accepted', 'running'])
            ->update(['state' => 'running', 'lease_until' => now()->addSeconds(self::LEASE_SECONDS), 'updated_at' => now()]) === 1;
    }

    /** Store the outcome once; a repeat after a lost answer changes nothing. @param  array<string, mixed>|null  $result */
    public static function finish(NodeJob $job, bool $ok, ?array $result, ?string $error): NodeJob
    {
        DB::transaction(function () use ($job, $ok, $result, $error) {
            NodeJob::where('id', $job->id)->whereIn('state', ['accepted', 'running'])->update([
                'state' => $job->cancel_requested && !$ok ? 'cancelled' : ($ok ? 'succeeded' : 'failed'),
                'result' => $result === null ? null : json_encode($result),
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'finished_at' => now(),
                'lease_until' => null,
                'updated_at' => now(),
            ]);
        });

        return $job->fresh();
    }

    /** Cancel: a queued job ends at once, a running one is told on its next progress call. */
    public static function cancel(NodeJob $job): NodeJob
    {
        NodeJob::where('id', $job->id)->where('state', 'queued')->update(['state' => 'cancelled', 'finished_at' => now()]);
        NodeJob::where('id', $job->id)->whereIn('state', ['accepted', 'running'])->update(['cancel_requested' => true]);

        return $job->fresh();
    }
}
